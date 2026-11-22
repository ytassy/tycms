<?php

declare(strict_types=1);

// Script d'intégration continue : n'est jamais utilisable depuis une requête HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Préparer la configuration privée de publication pour le job de release.
 *
 * Le token, l'URL du serveur et la clé de signature viennent de l'environnement
 * du job (secrets GitHub). Le fichier produit contient le token et le chemin
 * de la clé : il est donc écrit **hors du dépôt** dans le dossier désigné par
 * CONFIG_DIR, et `build/publish.php` continue de refuser tout fichier du dépôt.
 *
 * Aucun secret n'est accepté en argument de commande : la ligne de commande est
 * visible dans la liste des processus de la machine.
 */
const REQUIRED_SECRETS = [
    'server_url' => 'TYCMS_PUBLISH_SERVER_URL',
    'token' => 'TYCMS_PUBLISH_TOKEN',
    'key_id' => 'TYCMS_PUBLISH_KEY_ID',
];

try {
    // Un argument signalerait un appel inattendu : échouer plutôt que deviner.
    if ($argc > 1) {
        throw new RuntimeException('Aucun argument attendu ; les valeurs viennent de l’environnement.');
    }

    // Lire la configuration par variables d'environnement, sans jamais les afficher.
    $config = [];
    foreach (REQUIRED_SECRETS as $field => $variable) {
        $value = getenv($variable);
        if (!is_string($value) || $value === '') {
            throw new RuntimeException('Variable d’environnement absente : ' . $variable . '.');
        }
        $config[$field] = $value;
    }

    // La configuration doit rester hors du dépôt, comme l'exige publish.php.
    $configDir = getenv('CONFIG_DIR');
    $repository = realpath(dirname(__DIR__));
    if ($repository === false) {
        throw new RuntimeException('Dépôt introuvable.');
    }
    if (!is_string($configDir) || $configDir === '') {
        throw new RuntimeException('Variable d’environnement absente : CONFIG_DIR.');
    }
    // Vérifier l'emplacement avant toute création : aucune trace ne doit rester dans le dépôt.
    $absolute = static function (string $path): string {
        return $path !== '' && $path[0] === '/' ? $path : getcwd() . '/' . $path;
    };
    if (str_starts_with($absolute($configDir) . '/', $repository . '/')) {
        throw new RuntimeException('Le dossier de configuration doit rester hors du dépôt.');
    }
    if (!is_dir($configDir) && !mkdir($configDir, 0700, true) && !is_dir($configDir)) {
        throw new RuntimeException('Dossier de configuration impossible à créer.');
    }
    $configDir = realpath($configDir);
    if ($configDir === false || str_starts_with($configDir . '/', $repository . '/')) {
        throw new RuntimeException('Le dossier de configuration doit rester hors du dépôt.');
    }

    // La clé de signature Ed25519 arrive aussi par l'environnement et reste privée.
    $secret = getenv('TYCMS_RELEASE_SECRET');
    if (!is_string($secret) || $secret === '') {
        throw new RuntimeException('Variable d’environnement absente : TYCMS_RELEASE_SECRET.');
    }
    $secretFile = $configDir . '/release.secret';
    if (file_put_contents($secretFile, $secret) === false) {
        throw new RuntimeException('Clé de signature non écrite.');
    }
    chmod($secretFile, 0600);

    // Le fichier retourne un tableau lu par publish.php ; aucune donnée n'est recopiée en sortie.
    $published = [
        'server_url' => $config['server_url'],
        'token' => $config['token'],
        'key_id' => $config['key_id'],
        'private_key_file' => $secretFile,
        'timeout_seconds' => 300,
        'allow_loopback_http' => false,
    ];
    $target = $configDir . '/publication.php';
    $content = '<?php return ' . var_export($published, true) . ";\n";
    if (file_put_contents($target, $content) === false) {
        throw new RuntimeException('Configuration de publication non écrite.');
    }
    chmod($target, 0600);

    echo 'Configuration de publication préparée hors du dépôt.', PHP_EOL;
} catch (Throwable $error) {
    // Ne jamais recopier un secret ni exposer une trace interne.
    fwrite(STDERR, 'Préparation refusée : ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
