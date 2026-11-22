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
];

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

    // Le fournisseur et le modèle sont recopiés dans un YAML : n'accepter que des identifiants simples.
    if (preg_match('/\A[a-z0-9-]{2,40}\z/', $values['DSH_PROVIDER']) !== 1) {
        throw new RuntimeException('DSH_PROVIDER invalide.');
    }
    if (preg_match('/\A[A-Za-z0-9._:\/-]{3,120}\z/', $values['DSH_MODEL']) !== 1) {
        throw new RuntimeException('DSH_MODEL invalide.');
    }

    // Deux entrées sont nécessaires : le fournisseur doit être déclaré pour que son
    // adaptateur soit enregistré, et la sélection par défaut doit le désigner.
    // Le plugin pi-ai est monté inactif sans profil de fournisseur.
    $patch = "# Généré par cicd/dsh/prepare-profile.php ; ne pas modifier à la main.\n"
        . "- id: agent-default-model\n"
        . "  config:\n"
        . '    provider: ' . $values['DSH_PROVIDER'] . "\n"
        . '    model: ' . $values['DSH_MODEL'] . "\n"
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

    echo 'Surcouche DSH préparée : ' . $values['DSH_PROVIDER'] . ' / ' . $values['DSH_MODEL'] . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Surcouche refusée : ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
