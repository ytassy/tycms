<?php

declare(strict_types=1);

namespace Tycms\CiCd;

use RuntimeException;
use Throwable;

// Script d'intégration continue : n'est jamais utilisable depuis une requête HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Préparer la surcouche de profil DSH pour un workflow GitHub.
 *
 * Le modèle par défaut de DSH vise le fournisseur DeepSeek officiel. En
 * intégration continue, la tâche passe par OpenRouter : cette surcouche déclare
 * le fournisseur et le modèle, sans toucher au profil livré.
 *
 * Les valeurs viennent de l'environnement, jamais d'un argument de commande :
 * la ligne de commande est visible dans la liste des processus du runner.
 */

const DEFAULTS = [
    'DSH_PROVIDER' => 'openrouter',
    'DSH_MODEL' => 'deepseek/deepseek-v4.1-flash-1017',
    'DSH_EFFORT' => 'high',
];

/**
 * Variables spécifiques à une tâche, appliquées par-dessus les valeurs générales.
 * DSH_TASK désigne la tâche courante (« audit », « triage », « fix ») : chaque
 * workflow choisit ainsi un modèle plus puissant pour la sécurité sans changer
 * celui des tickets. Une variable spécifique vide est ignorée.
 *
 *   DSH_AUDIT_MODEL, DSH_AUDIT_EFFORT, DSH_AUDIT_PROVIDER
 */
const TASK_VARIABLES = ['PROVIDER', 'MODEL', 'EFFORT'];

/** Niveaux d'effort de raisonnement acceptés par l'adaptateur pi-ai, du plus léger au plus lourd. */
const EFFORT_LEVELS = ['off', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max'];

try {
    if ($argc > 1) {
        throw new RuntimeException('Aucun argument attendu ; les valeurs viennent de l’environnement.');
    }

    // Le fichier est écrit hors du dépôt : le runner le supprime en fin de job.
    $directory = getenv('DSH_CONFIG_DIR');
    $repository = realpath(dirname(__DIR__));
    if ($repository === false) {
        throw new RuntimeException('Dépôt introuvable.');
    }
    if (!is_string($directory) || $directory === '') {
        throw new RuntimeException('Variable d’environnement absente : DSH_CONFIG_DIR.');
    }
    $absolute = $directory !== '' && $directory[0] === '/' ? $directory : getcwd() . '/' . $directory;
    if (str_starts_with($absolute . '/', $repository . '/')) {
        throw new RuntimeException('Le dossier de configuration doit rester hors du dépôt.');
    }
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Dossier de configuration impossible à créer.');
    }

    $values = [];
    foreach (DEFAULTS as $variable => $default) {
        $value = getenv($variable);
        $values[$variable] = is_string($value) && $value !== '' ? $value : $default;
    }

    // Un nom de tâche simple, pour composer des variables d'environnement.
    $task = getenv('DSH_TASK');
    $task = is_string($task) ? strtolower(trim($task)) : '';
    if ($task !== '' && preg_match('/\A[a-z][a-z0-9_-]{0,31}\z/', $task) !== 1) {
        throw new RuntimeException('DSH_TASK invalide.');
    }

    // Les valeurs propres à la tâche priment sur les valeurs générales.
    if ($task !== '') {
        foreach (TASK_VARIABLES as $field) {
            $override = getenv('DSH_' . strtoupper($task) . '_' . $field);
            if (is_string($override) && $override !== '') {
                $values['DSH_' . $field] = $override;
            }
        }
    }

    // Le fournisseur et le modèle sont recopiés dans un YAML : n'accepter que des identifiants simples.
    if (preg_match('/\A[a-z0-9-]{2,40}\z/', $values['DSH_PROVIDER']) !== 1) {
        throw new RuntimeException('DSH_PROVIDER invalide.');
    }
    if (preg_match('/\A[A-Za-z0-9._:\/-]{3,120}\z/', $values['DSH_MODEL']) !== 1) {
        throw new RuntimeException('DSH_MODEL invalide.');
    }
    // L'effort est transmis tel quel à l'adaptateur : refuser ici évite un échec au premier appel.
    $values['DSH_EFFORT'] = strtolower($values['DSH_EFFORT']);
    if (!in_array($values['DSH_EFFORT'], EFFORT_LEVELS, true)) {
        throw new RuntimeException('DSH_EFFORT accepte : ' . implode(', ', EFFORT_LEVELS) . '.');
    }

    // Deux entrées sont nécessaires : le fournisseur doit être déclaré pour que son
    // adaptateur soit enregistré, et la sélection par défaut doit le désigner.
    // Le plugin pi-ai est monté inactif sans profil de fournisseur.
    $patch = "# Généré par cicd/dsh/prepare-profile.php ; ne pas modifier à la main.\n"
        . "- id: agent-default-model\n"
        . "  config:\n"
        . '    provider: ' . $values['DSH_PROVIDER'] . "\n"
        . '    model: ' . $values['DSH_MODEL'] . "\n"
        . '    reasoningEffort: ' . $values['DSH_EFFORT'] . "\n"
        . "- id: llm-pi-ai\n"
        . "  config:\n"
        . "    providers:\n"
        . '      ' . $values['DSH_PROVIDER'] . ":\n"
        . '        apiKeyEnv: ' . strtoupper(str_replace('-', '_', $values['DSH_PROVIDER'])) . "_API_KEY\n";
    $target = $directory . '/headless.patch.yml';
    if (file_put_contents($target, $patch) !== strlen($patch)) {
        throw new RuntimeException('Surcouche de profil non écrite.');
    }
    chmod($target, 0600);

    echo 'Surcouche DSH préparée' . ($task !== '' ? ' pour ' . $task : '') . ' : '
        . $values['DSH_PROVIDER'] . ' / ' . $values['DSH_MODEL']
        . ' / effort ' . $values['DSH_EFFORT'] . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Surcouche refusée : ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
