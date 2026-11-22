<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/update_server/lib/Protocol.php';

use Tycms\Distribution\Protocol;

/** Valider les métadonnées avant toute construction de chemin ou installation d’outils. */
function readReleaseMetadata(string $json): array
{
    $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        throw new RuntimeException('Objet JSON de release attendu.');
    }

    return Protocol::releaseMetadata($data);
}

/** Sélection explicite : les fichiers locaux et les anciens bundles restent exclus. */
function releaseSource(string $path): bool
{
    if (preg_match('~(?:^|/)(?:\.git[^/]*|\.env[^/]*|\.npmrc|auth\.json|node_modules|dist)(?:/|$)~', $path)) {
        return false;
    }

    // Tous les assets suivis de l'updater sont livrés, y compris ses bibliothèques.
    if (str_starts_with($path, 'src/public/assets_updater/')) {
        return true;
    }

    if (preg_match('~(?:^|/)vendor(?:/|$)~', $path)) {
        return false;
    }

    // L'extension de test ne fait pas partie des installations distribuées.
    if (str_starts_with($path, 'src/releases/vdev/core_extensions/Test/')) {
        return false;
    }

    if (in_array($path, [
        'src/public/index.php', 'src/public/.htaccess',
        'src/releases/vdev/index.php', 'src/releases/vdev/version.php',
        'src/releases/vdev/configuration.base.php', 'src/releases/vdev/configuration.sample.php',
        'src/releases/vdev/composer.json', 'src/releases/vdev/composer.lock', 'src/tools/core-assets.mjs', 'src/tools/shared-esm.mjs',
    ], true)) {
        return true;
    }

    if (str_starts_with($path, 'src/updater/')) {
        return !str_ends_with($path, '.md');
    }

    if (str_starts_with($path, 'src/releases/vdev/framework/')
        || str_starts_with($path, 'src/releases/vdev/core_extensions/')) {

        return !str_ends_with($path, '.md');
    }

    if (str_starts_with($path, 'src/ria/runtime/') || str_starts_with($path, 'src/ria/src/') || str_starts_with($path, 'src/ria/scripts/')) {
        return true;
    }

    if (in_array($path, [
        'src/ria/package.json', 'src/ria/package-lock.json', 'src/ria/vite.config.ts', 'src/ria/vite.runtime.config.mjs',
        'src/tools/mermaid/package.json', 'src/tools/mermaid/package-lock.json',
        'src/tools/mermaid/build.mjs', 'src/tools/mermaid/mermaid-entry.mjs',
        'src/tools/mermaid/svg2roughjs-entry.mjs',
    ], true)) {
        return true;
    }

    $assets = 'src/public/assets_core_vdev/';
    if (!str_starts_with($path, $assets)) {
        return false;
    }

    $relative = substr($path, strlen($assets));

    // Tout le dossier plg_core est livré, sauf le sous-dossier caché .vite généré par Vite
    // et les fichiers de suivi Git. Les bundles ignorés par Git ne sont pas dans l'inventaire.
    if (str_starts_with($relative, 'plg_core/')) {
        return !str_starts_with($relative, 'plg_core/.vite/')
            && !str_ends_with($relative, '.gitignore');
    }

    return str_starts_with($relative, 'tpl_main/');
}

/** Éviter le shell pour les chemins, arguments et commandes des outils. */
function buildCommand(array $command, string $directory, bool $capture = false): string
{
    echo '> ' . implode(' ', $command) . PHP_EOL;
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'],
        1 => $capture ? ['pipe', 'w'] : STDOUT, 2 => STDERR], $pipes, $directory);
    if (!is_resource($process)) {
        throw new RuntimeException('Impossible de lancer ' . $command[0]);
    }

    $output = $capture ? stream_get_contents($pipes[1]) : '';
    if ($capture) {
        fclose($pipes[1]);
    }
    if (proc_close($process) !== 0) {
        throw new RuntimeException('Échec de la commande ' . $command[0]);
    }

    return $output;
}

function buildDirectory(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0755, true)) {
        throw new RuntimeException('Création du dossier impossible : ' . $path);
    }
    if (is_link($path)) {
        throw new RuntimeException('Dossier symbolique interdit : ' . $path);
    }
}

/** Une écriture incomplète doit interrompre la fabrication. */
function buildWrite(string $path, string $contents): void
{
    if (file_put_contents($path, $contents) !== strlen($contents)) {
        throw new RuntimeException('Écriture incomplète : ' . $path);
    }
}

/** Refuser aussi les liens dans les parents, pour ne jamais copier hors du dépôt. */
function buildCopy(string $source, string $target, string $root): void
{
    $real = realpath($source);
    if ($real === false || $real !== $source || !str_starts_with($real, $root . '/') || !is_file($source)) {
        throw new RuntimeException('Source absente ou lien interdit : ' . $source);
    }

    buildDirectory(dirname($target));
    if (!copy($source, $target)) {
        throw new RuntimeException('Copie impossible : ' . $source);
    }
    chmod($target, 0644);
}

/** Supprimer un fichier ou un arbre fabriqué, en restant confiné à la copie de build. */
function buildRemoveTree(string $path, string $root): void
{
    $real = realpath($path);
    if ($real === false || $real !== $path || !str_starts_with($real, $root . '/') || is_link($path)) {
        throw new RuntimeException('Suppression hors copie refusée : ' . $path);
    }

    if (is_dir($path)) {
        foreach (new DirectoryIterator($path) as $entry) {
            if (!$entry->isDot()) {
                buildRemoveTree($entry->getPathname(), $root);
            }
        }
        if (!rmdir($path)) {
            throw new RuntimeException('Suppression impossible : ' . $path);
        }

        return;
    }

    if (!unlink($path)) {
        throw new RuntimeException('Suppression impossible : ' . $path);
    }
}

/**
 * Alléger google/apiclient-services selon la sélection `extra` du manifeste.
 * Le build n'exécute aucun script Composer : ce nettoyage borné remplace le
 * hook `Google\Task\Composer::cleanup`, et l'autoload est régénéré ensuite.
 */
function pruneGoogleServices(string $release): void
{
    $release = realpath($release) ?: throw new RuntimeException('Copie de build introuvable.');
    $manifest = json_decode((string) file_get_contents($release . '/composer.json'), true, 16, JSON_THROW_ON_ERROR);
    $keep = is_array($manifest) && is_array($manifest['extra']['google/apiclient-services'] ?? null)
        ? $manifest['extra']['google/apiclient-services']
        : [];

    // Une sélection absente ou une dépendance non installée ne supprime rien.
    $services = $release . '/vendor/google/apiclient-services/src';
    if ($keep === [] || !is_dir($services)) {
        return;
    }

    // Valider la sélection avant toute suppression : un nom inutilisable bloque le build.
    foreach ($keep as $service) {
        if (!is_string($service) || $service === '' || !preg_match('/^[a-zA-Z0-9]+$/', $service)
            || !is_dir($services . '/' . $service)) {

            throw new RuntimeException('Service Google inconnu ou nom invalide : ' . var_export($service, true));
        }
    }

    // Retirer chaque service non retenu avec son fichier de déclaration associé.
    $removed = 0;
    foreach (new DirectoryIterator($services) as $entry) {
        if ($entry->isDot() || !$entry->isDir()) {
            continue;
        }
        if ($entry->isLink()) {
            throw new RuntimeException('Lien interdit dans les services Google : ' . $entry->getPathname());
        }
        if (in_array($entry->getFilename(), $keep, true)) {
            continue;
        }

        buildRemoveTree($entry->getPathname(), $release);
        $declaration = $services . '/' . $entry->getFilename() . '.php';
        if (file_exists($declaration)) {
            buildRemoveTree($declaration, $release);
        }
        $removed++;
    }

    echo 'Services Google retirés : ' . $removed . ' ; conservés : ' . count($keep) . PHP_EOL;
}

/**
 * Alléger aws/aws-sdk-php selon la sélection `extra` du manifeste.
 * Le SDK livre tous ses services dans un seul paquet : ce nettoyage borné
 * reprend la logique officielle `Aws\Script\Composer\Composer` sans exécuter
 * de script Composer. Les services partagés par l'authentification restent.
 */
function pruneAwsServices(string $release): void
{
    $release = realpath($release) ?: throw new RuntimeException('Copie de build introuvable.');
    $manifest = json_decode((string) file_get_contents($release . '/composer.json'), true, 16, JSON_THROW_ON_ERROR);
    $keep = is_array($manifest) && is_array($manifest['extra']['aws/aws-sdk-php'] ?? null)
        ? $manifest['extra']['aws/aws-sdk-php']
        : [];

    // Une sélection absente ou une dépendance non installée ne supprime rien.
    $sdk = $release . '/vendor/aws/aws-sdk-php/src';
    $mappingFile = $sdk . '/data/manifest.json.php';
    if ($keep === [] || !is_file($mappingFile)) {
        return;
    }

    // Table générée par le SDK : dossier de modèles -> namespace du client.
    $mapping = require $mappingFile;
    $services = [];
    foreach (is_array($mapping) ? $mapping : [] as $model => $attributes) {
        if (!is_string($model) || !is_array($attributes) || !is_string($attributes['namespace'] ?? null)) {
            throw new RuntimeException('Entrée de manifeste AWS invalide.');
        }
        $services[$attributes['namespace']] = $model;
    }
    if ($services === []) {
        throw new RuntimeException('Manifeste des services AWS invalide.');
    }

    // Reprendre les services que le SDK ne supprime jamais : authentification et KMS.
    $kept = ['Kms', 'S3', 'SSO', 'SSOOIDC', 'Sts', 'Signin'];
    if (in_array('DynamoDbStreams', $keep, true)) {
        $kept[] = 'DynamoDb';
    }

    // Valider la sélection avant toute suppression : un nom inutilisable bloque le build.
    foreach ($keep as $service) {
        if (!is_string($service) || !isset($services[$service])) {
            throw new RuntimeException('Service AWS inconnu ou nom invalide : ' . var_export($service, true));
        }
        $kept[] = $service;
    }
    $kept = array_values(array_unique($kept));

    // Ne jamais retirer un modèle encore utilisé par un service conservé.
    $models = [];
    foreach ($kept as $service) {
        if (isset($services[$service])) {
            $models[$services[$service]] = true;
        }
    }

    // Retirer les clients et modèles non retenus, sans toucher aux fichiers partagés.
    $removed = 0;
    foreach ($services as $service => $model) {
        if (in_array($service, $kept, true)) {
            continue;
        }

        $directory = $sdk . '/' . $service;
        if (is_dir($directory)) {
            buildRemoveTree($directory, $release);
            $removed++;
        }
        $modelDirectory = $sdk . '/data/' . $model;
        if (!isset($models[$model]) && is_dir($modelDirectory)) {
            buildRemoveTree($modelDirectory, $release);
        }
    }

    echo 'Services AWS retirés : ' . $removed . ' ; conservés : ' . count($kept) . PHP_EOL;
}

/** Copier un arbre fabriqué et contrôlé, sans suivre de liens symboliques. */
function buildTree(string $source, string $target, string $root): void
{
    buildDirectory($target);
    foreach (new DirectoryIterator($source) as $entry) {
        if ($entry->isDot()) {
            continue;
        }
        if ($entry->isLink()) {
            throw new RuntimeException('Lien interdit dans la distribution : ' . $entry->getPathname());
        }

        $destination = $target . '/' . $entry->getFilename();
        if ($entry->isDir()) {
            buildTree($entry->getPathname(), $destination, $root);
        } else {
            buildCopy($entry->getPathname(), $destination, $root);
        }
    }
}

function buildRelease(string $repository, bool $prepareOnly): void
{
    $repository = realpath($repository) ?: throw new RuntimeException('Dépôt introuvable.');
    $metadata = readReleaseMetadata(file_get_contents($repository . '/version.json'));
    ['version' => $version, 'version_string' => $suffix] = $metadata;
    if (!$prepareOnly && !class_exists(ZipArchive::class)) {
        throw new RuntimeException('Extension PHP zip requise.');
    }

    // Un dossier neuf évite tout nettoyage risqué et toute réutilisation de bundles.
    foreach (['build', 'build/temp', 'build/dist'] as $directory) {
        buildDirectory($repository . '/' . $directory);
    }
    $work = $repository . '/build/temp/build-' . $suffix . '-' . bin2hex(random_bytes(8));
    buildDirectory($work);
    $source = $work . '/src';
    $release = $source . '/releases/vdev';

    // Git sert uniquement d'inventaire ; la copie prend le contenu courant des fichiers suivis.
    $files = explode("\0", buildCommand(['git', '-c', 'safe.directory=' . $repository, 'ls-files', '-z', '--', 'src'], $repository, true));
    foreach ($files as $path) {
        if (releaseSource($path)) {
            buildCopy($repository . '/' . $path, $work . '/' . $path, $repository);
        }
    }

    buildWrite($release . '/version.php', "<?php\n\ndefine('APP_VERSION', '$version');\ndefine('APP_VERSION_STRING', '$suffix');\n\nreturn APP_VERSION;\n");
    $assets = $source . '/public/assets_core_v' . $suffix;
    if (!rename($source . '/public/assets_core_vdev', $assets)) {
        throw new RuntimeException('Préparation des assets impossible.');
    }
    foreach (['plugins', 'modules', 'templates'] as $kind) {
        buildDirectory($source . '/extensions/' . $kind);
    }
    echo 'Copie isolée : ' . $work . PHP_EOL;
    buildWrite($work . '/build-version.json', json_encode($metadata, JSON_THROW_ON_ERROR));
    buildCopy($repository . '/version.md', $work . '/version.md', $repository);
    if (filesize($work . '/version.md') < 1 || filesize($work . '/version.md') > Protocol::MAX_NOTES_BYTES) {
        throw new RuntimeException('version.md doit contenir entre 1 et 262144 octets.');
    }
    if ($prepareOnly) {
        echo $work . PHP_EOL;
        return;
    }

    compileRelease($work, $repository . '/build/dist');
}

function compileRelease(string $work, string $distribution): void
{
    $work = realpath($work) ?: throw new RuntimeException('Copie de travail introuvable.');
    $metadata = readReleaseMetadata(file_get_contents($work . '/build-version.json'));
    ['version' => $version, 'version_string' => $suffix] = $metadata;
    $source = $work . '/src';
    $release = $source . '/releases/vdev';
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('Extension PHP zip requise.');
    }
    buildDirectory($distribution);

    // Aucun plugin ni script Composer ; conserver la résolution PSR-4 des extensions locales.
    buildCommand(['composer', 'validate', '--no-check-publish'], $release);
    buildCommand(['composer', 'install', '--no-dev', '--prefer-dist', '--no-interaction', '--no-progress', '--no-plugins', '--no-scripts', '--optimize-autoloader'], $release);

    // `--no-scripts` désactive les hooks Google et AWS : appliquer leurs sélections
    // ici, puis régénérer l'autoload pour retirer les classes des services supprimés.
    pruneGoogleServices($release);
    pruneAwsServices($release);
    buildCommand(['composer', 'dump-autoload', '--optimize', '--no-dev', '--no-plugins', '--no-scripts'], $release);
    buildCommand(['composer', 'check-platform-reqs', '--no-dev'], $release);
    foreach (['tools/mermaid', 'ria'] as $project) {
        buildCommand(['npm', 'ci', '--include=dev', '--no-audit', '--no-fund'], $source . '/' . $project);
        buildCommand(['npm', 'run', 'build'], $source . '/' . $project);
    }

    // Assembler uniquement les arbres d'exécution dans un second dossier propre.
    $package = $work . '/package';
    $target = $package . '/releases/v' . $suffix;
    buildTree($release, $target, $work);
    buildTree($source . '/public', $package . '/public', $work);
    buildTree($source . '/updater', $package . '/updater', $work);
    foreach (['extensions/plugins', 'extensions/modules', 'extensions/templates', 'logs', 'tmp',
        'public/assets/fonts', 'public/medias', 'public/assets_updater', 'var'] as $directory) {
        buildDirectory($package . '/' . $directory);
    }
    buildWrite($package . '/var/releases-states.json', json_encode([
        'schema_version' => 1, 'active_release' => 'v' . $suffix,
        'available_update' => null, 'active_operation' => null,
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
    buildWrite($package . '/var/last_update_data.json', "{}\n");

    // Contrôler les indispensables avant de rendre l'archive disponible.
    foreach (["releases/v$suffix/vendor/autoload.php", "releases/v$suffix/configuration.base.php",
        "releases/v$suffix/configuration.sample.php",
        'public/index.php', 'public/.htaccess', 'updater/index.php',
        'public/assets_updater/vendor/bootstrap.min.css',
        'public/assets_updater/vendor/vue.runtime.global.prod.js',
        'public/assets_updater/vendor/vue3-sfc-loader.js',
        'var/releases-states.json', 'var/last_update_data.json',
        "public/assets_core_v$suffix/plg_core/css/index.css",
        "public/assets_core_v$suffix/plg_core/js/index.js",
        "public/assets_core_v$suffix/plg_core/js/extension-api.js",
        "public/assets_core_v$suffix/plg_core/images/logo_tycms_1.webp",
        "public/assets_core_v$suffix/plg_core/.vite/manifest.json",
        "public/assets_core_v$suffix/plg_core/runtime/prod/vue.js",
        "public/assets_core_v$suffix/plg_core/mermaid/lib/mermaid.mjs",
        "public/assets_core_v$suffix/plg_core/mermaid/runtime.mjs"] as $file) {
        if (!is_file($package . '/' . $file)) {
            throw new RuntimeException('Fichier requis absent : ' . $file);
        }
    }
    buildCommand([PHP_BINARY, '-r', 'require $argv[1]; if (class_exists("PHPUnit\\Framework\\TestCase")) exit(1); if (!class_exists("YTassy\\Tycms\\Application")) exit(2);', $target . '/vendor/autoload.php'], $package);

    // Vérifier que le nettoyage AWS laisse S3 utilisable, sans aucun appel réseau.
    buildCommand([PHP_BINARY, '-r', <<<'PHP'
require $argv[1];
$handler = new Aws\MockHandler();
$handler->append(new Aws\Result(['Body' => 'fixture']));
$client = new Aws\S3\S3Client([
    'region' => 'eu-west-3',
    'version' => 'latest',
    'credentials' => ['key' => 'fixture', 'secret' => 'fixture'],
    'handler' => $handler,
]);
$client->putObject(['Bucket' => 'fixture', 'Key' => 'fixture', 'Body' => 'fixture']);
$client->createPresignedRequest(
    $client->getCommand('GetObject', ['Bucket' => 'fixture', 'Key' => 'fixture']),
    '+5 minutes'
);
PHP, $target . '/vendor/autoload.php'], $package);

    // Créer le fichier provisoire sur le même montage que le ZIP final : renommage atomique.
    $temporary = $distribution . '/.tycms-' . $suffix . '-' . bin2hex(random_bytes(8)) . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($temporary, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
        throw new RuntimeException('Création ZIP impossible.');
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($package, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($iterator as $entry) {
        $name = substr($entry->getPathname(), strlen($package) + 1);
        if ($entry->isLink() || preg_match('~(?:^|/)(?:configuration\.php|node_modules|\.git[^/]*|phpunit\.xml)(?:/|$)~', $name)) {
            throw new RuntimeException('Contenu interdit : ' . $name);
        }
        $added = $entry->isDir() ? $zip->addEmptyDir($name) : $zip->addFile($entry->getPathname(), $name);
        if (!$added) {
            throw new RuntimeException('Archivage impossible : ' . $name);
        }
        $zip->setExternalAttributesName($entry->isDir() ? $name . '/' : $name,
            ZipArchive::OPSYS_UNIX, ($entry->isDir() ? 040755 : 0100644) << 16);
    }
    if (!$zip->close() || $zip->open($temporary, ZipArchive::CHECKCONS) !== true) {
        throw new RuntimeException('Archive invalide.');
    }
    $zip->close();
    finishReleaseArtifacts($temporary, $work . '/version.md', $metadata, $distribution);
}

/** Figer les trois artefacts ; toute paire incohérente sera refusée par la publication. */
function finishReleaseArtifacts(string $zip, string $notes, array $metadata, string $distribution): void
{
    $metadata = Protocol::releaseMetadata($metadata);
    $base = 'tycms-' . $metadata['version_string'];
    $stamp = bin2hex(random_bytes(8));
    $temporaryNotes = $distribution . '/.' . $base . '-' . $stamp . '.md';
    $temporaryMetadata = $distribution . '/.' . $base . '-' . $stamp . '.json';
    $contents = file_get_contents($notes, false, null, 0, Protocol::MAX_NOTES_BYTES + 1);
    if (!is_string($contents) || strlen($contents) < 1 || strlen($contents) > Protocol::MAX_NOTES_BYTES) {
        throw new RuntimeException('Notes de version absentes ou trop volumineuses.');
    }
    // Manifeste v2 : le build produit une release de prod ; le canal reste modifiable sur le serveur.
    $manifest = ['schema_version' => Protocol::SCHEMA_VERSION, 'type' => 'system',
        'channel' => Protocol::CHANNEL_PROD] + $metadata + [
        'archive' => ['filename' => $base . '.zip', 'bytes' => filesize($zip), 'sha256' => hash_file('sha256', $zip)],
        'release_notes' => ['filename' => $base . '.md', 'bytes' => strlen($contents), 'sha256' => hash('sha256', $contents)],
    ];
    Protocol::validate($manifest);
    buildWrite($temporaryNotes, $contents);
    buildWrite($temporaryMetadata, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

    // Sérialiser les sorties d’un même dossier ; le JSON est remplacé en dernier.
    $lockPath = $distribution . '/.artifacts.lock';
    if (is_link($lockPath)) {
        throw new RuntimeException('Verrou des artefacts invalide.');
    }
    $lock = fopen($lockPath, 'c');
    if ($lock === false) {
        throw new RuntimeException('Verrou des artefacts inaccessible.');
    }
    try {
        if (!flock($lock, LOCK_EX)) {
            throw new RuntimeException('Verrouillage des artefacts impossible.');
        }
        $files = [$zip => $base . '.zip', $temporaryNotes => $base . '.md', $temporaryMetadata => $base . '.build.json'];
        foreach ($files as $name) {
            if (is_link($distribution . '/' . $name)) {
                throw new RuntimeException('Lien symbolique interdit dans les sorties du build.');
            }
        }
        foreach ($files as $temporary => $name) {
            if (!rename($temporary, $distribution . '/' . $name)) {
                throw new RuntimeException('Finalisation des artefacts impossible.');
            }
            echo 'Artefact : ' . $distribution . '/' . $name . PHP_EOL;
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
        foreach ([$temporaryNotes, $temporaryMetadata] as $temporary) {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
    echo 'SHA-256 ZIP : ' . $manifest['archive']['sha256'] . PHP_EOL;
}
