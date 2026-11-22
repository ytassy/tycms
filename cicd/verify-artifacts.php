<?php

declare(strict_types=1);

// Script d'intégration continue : n'est jamais utilisable depuis une requête HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Vérifier que le build a produit les trois artefacts d'une version.
 *
 * `version.json` est la source unique de la version. Le script ne modifie rien :
 * il confirme la présence et la cohérence des fichiers compagnons, puis écrit
 * `version` et `string` dans GITHUB_OUTPUT pour les étapes suivantes.
 * Un nom de dossier passé en argument peut remplacer `build/dist`.
 */
try {
    if ($argc > 2) {
        throw new RuntimeException('Usage : php cicd/verify-artifacts.php [dossier]');
    }
    $repository = realpath(dirname(__DIR__));
    if ($repository === false) {
        throw new RuntimeException('Dépôt introuvable.');
    }

    // Les noms de fichiers sont dérivés de version_string, jamais d'un nom fourni librement.
    $metadata = json_decode(file_get_contents($repository . '/version.json'), true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($metadata)) {
        throw new RuntimeException('version.json invalide.');
    }
    $version = $metadata['version'] ?? null;
    $suffix = $metadata['version_string'] ?? null;
    if (!is_string($version) || preg_match('/\A(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\z/', $version) !== 1) {
        throw new RuntimeException('Champ version absent ou invalide.');
    }
    // Le build recalcule le suffixe depuis version ; version.json reste la source unique.
    if (!is_string($suffix) || $suffix === '') {
        throw new RuntimeException('Champ version_string absent.');
    }
    if ($suffix !== str_replace('.', '_', $version)) {
        // Un écart entre version et version_string empêche le build de démarrer ; le signaler clairement.
        fwrite(STDERR, 'Avertissement : version_string renseigne « ' . $suffix . ' » alors que version vaut « '
            . $version . ' ». Le build utilise la valeur recalculée.' . PHP_EOL);
        $suffix = str_replace('.', '_', $version);
    }

    // Le dossier doit rester un répertoire du dépôt, sans lien symbolique.
    $directory = $argc === 2 ? $argv[1] : $repository . '/build/dist';
    $real = realpath($directory);
    if ($real === false || !is_dir($real) || is_link($real)) {
        throw new RuntimeException('Dossier d’artefacts introuvable ou symbolique.');
    }
    $expected = [
        'tycms-' . $suffix . '.zip',
        'tycms-' . $suffix . '.build.json',
        'tycms-' . $suffix . '.md',
    ];
    $missing = [];
    foreach ($expected as $name) {
        $path = $real . '/' . $name;
        if (!is_file($path) || is_link($path) || filesize($path) === 0) {
            $missing[] = $name;
        }
    }
    if ($missing !== []) {
        // Lister ce qui est présent aide à voir un build annulé ou une version mal incrémentée.
        $present = array_values(array_filter(scandir($real) ?: [], static fn(string $name): bool
            => preg_match('/\Atycms-.+\.(?:zip|build\.json|md)\z/', $name) === 1));
        throw new RuntimeException('Artefacts manquants pour ' . $version . ' : ' . implode(', ', $missing)
            . '. Présents dans ' . $directory . ' : ' . ($present === [] ? 'aucun' : implode(', ', $present)) . '.');
    }

    // Exposer la version au workflow seulement après les contrôles.
    $output = getenv('GITHUB_OUTPUT');
    if (is_string($output) && $output !== '') {
        $lines = 'version=' . $version . "\nstring=" . $suffix . "\n";
        if (file_put_contents($output, $lines, FILE_APPEND) === false) {
            throw new RuntimeException('Sortie GITHUB_OUTPUT inaccessible.');
        }
    }
    echo 'Artefacts vérifiés pour ' . $version . '.', PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Vérification refusée : ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
