<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$metadata = ['version' => '1.0.0', 'version_string' => '1_0_0',
    'source_versions' => [], 'php_min' => '8.4.1', 'php_max_exclusive' => '8.6.0'];

// Refuser les versions susceptibles de fabriquer des chemins arbitraires ou ambigus.
foreach (['../1.0.0', '1.0.0/../../secret', '01.0.0', '1.0.0-rc1', '', null, []] as $version) {
    try {
        readReleaseMetadata(json_encode(array_replace($metadata, ['version' => $version])));
        throw new LogicException('Version invalide acceptée.');
    } catch (RuntimeException) {
    }
}
try {
    readReleaseMetadata(json_encode(array_replace($metadata, ['version_string' => '../../secret'])));
    throw new LogicException('Suffixe invalide accepté.');
} catch (RuntimeException) {
}
check(readReleaseMetadata(json_encode($metadata)) === $metadata, 'Métadonnées valides refusées.');
$star = array_replace($metadata, ['source_versions' => '*']);
check(readReleaseMetadata(json_encode($star)) === $star, 'Marqueur « * » refusé pour les versions sources.');
foreach (['source_versions', 'php_min', 'php_max_exclusive'] as $required) {
    $invalid = $metadata;
    unset($invalid[$required]);
    try {
        readReleaseMetadata(json_encode($invalid));
        throw new LogicException('Champ obligatoire absent accepté.');
    } catch (RuntimeException) {
    }
}
foreach ([['source_versions' => ['1.0.0']], ['source_versions' => ['0.9.0', '0.9.0']],
    ['source_versions' => '*foo'], ['source_versions' => '1.0.0'], ['source_versions' => ['*']],
    ['php_min' => '8.6.0'], ['php_max_exclusive' => '../8.6.0']] as $changes) {
    try {
        readReleaseMetadata(json_encode(array_replace($metadata, $changes)));
        throw new LogicException('Compatibilités invalides acceptées.');
    } catch (RuntimeException) {
    }
}

// Les fichiers locaux, dépendances et bundles existants ne peuvent entrer dans la copie.
foreach ([
    'src/releases/vdev/configuration.php', 'src/releases/vdev/vendor/autoload.php',
    'src/releases/vdev/core_extensions/Test/Plugin.php', 'src/var/releases-states.json',
    'src/var/last_update_data.json', 'src/public/medias/private.pdf',
    'src/extensions/plugins/Private/Plugin.php', 'src/ria/node_modules/package/index.js',
    'src/ria/.env.production', 'src/ria/.npmrc', 'src/tools/mermaid/auth.json',
    'src/public/assets_updater/vendor/.env', 'src/public/assets_updater/vendor/node_modules/index.js',
    'src/public/assets_core_vdev/plg_core/.vite/manifest.json',
    'src/public/assets_core_vdev/plg_core/.vite/.gitignore',
    'src/public/assets_core_vdev/plg_core/css/.gitignore',
    'src/public/assets_core_vdev/plg_core/js/.gitignore',
    'src/public/assets_core_vdev/plg_core/runtime/.gitignore',
    'src/public/assets/plg_lms/build/index.js',
] as $path) {
    check(!releaseSource($path), 'Fichier local ou généré sélectionné : ' . $path);
}
foreach (['src/public/.htaccess', 'src/releases/vdev/composer.lock',
    'src/ria/vite.runtime.config.mjs', 'src/ria/runtime/vue.js', 'src/tools/shared-esm.mjs',
    'src/releases/vdev/configuration.base.php',
    'src/releases/vdev/configuration.sample.php',
    'src/public/assets_core_vdev/plg_core/images/logo_tycms_1.webp',
    'src/public/assets_core_vdev/plg_core/js/index.js',
    'src/public/assets_core_vdev/plg_core/css/index.css',
    'src/public/assets_core_vdev/plg_core/runtime/prod/vue.js',
    'src/public/assets_core_vdev/plg_core/mermaid/lib/mermaid.mjs',
    'src/public/assets_core_vdev/plg_core/mermaid/svg2roughjs.mjs',
    'src/public/assets_core_vdev/plg_core/mermaid/runtime.mjs',
    'src/public/assets_core_vdev/plg_core/mermaid/.htaccess',
    'src/public/assets_core_vdev/plg_core/page-canvas-size.js',
    'src/public/assets_core_vdev/plg_core/videojs/video-8.23.4.min.js',
    'src/updater/index.php', 'src/public/assets_updater/app/App.vue',
    'src/public/assets_updater/vendor/bootstrap.min.css',
    'src/public/assets_updater/vendor/vue.runtime.global.prod.js',
    'src/public/assets_updater/vendor/vue3-sfc-loader.js',
    'src/public/assets_updater/vendor/LICENSE-vue.txt',
    'src/public/assets_updater/vendor/nested/vendor/index.js',
    'src/public/assets_updater/guide.md'] as $path) {
    check(releaseSource($path), 'Fichier nécessaire exclu : ' . $path);
}

// Un lien dans un dossier parent ne doit pas permettre de copier un secret extérieur.
$fixture = sys_get_temp_dir() . '/tycms-build-test-' . bin2hex(random_bytes(8));
mkdir($fixture);
mkdir($fixture . '/source');
mkdir($fixture . '/external');
file_put_contents($fixture . '/external/secret', 'fixture');
symlink($fixture . '/external', $fixture . '/source/linked');
try {
    try {
        buildCopy($fixture . '/source/linked/secret', $fixture . '/copied', $fixture . '/source');
        throw new LogicException('Lien parent accepté.');
    } catch (RuntimeException) {
    }
    check(!file_exists($fixture . '/copied'), 'Secret extérieur copié.');
} finally {
    unlink($fixture . '/source/linked');
    unlink($fixture . '/external/secret');
    rmdir($fixture . '/source');
    rmdir($fixture . '/external');
    rmdir($fixture);
}

// La sélection Google doit s'appliquer sans exécuter le hook Composer.
$fixture = sys_get_temp_dir() . '/tycms-build-google-' . bin2hex(random_bytes(8));
$services = $fixture . '/vendor/google/apiclient-services/src';
foreach (['Drive', 'YouTube', 'Removed'] as $service) {
    mkdir($services . '/' . $service, 0755, true);
    file_put_contents($services . '/' . $service . '/Resource.php', 'fixture');
    file_put_contents($services . '/' . $service . '.php', 'fixture');
}
file_put_contents($fixture . '/composer.json', json_encode([
    'extra' => ['google/apiclient-services' => ['Drive', 'YouTube']],
], JSON_THROW_ON_ERROR));
try {
    ob_start();
    pruneGoogleServices($fixture);
    ob_end_clean();
    check(is_dir($services . '/Drive') && is_file($services . '/Drive.php'), 'Service Google retenu supprimé.');
    check(is_dir($services . '/YouTube'), 'Service Google retenu supprimé.');
    check(!file_exists($services . '/Removed') && !file_exists($services . '/Removed.php'),
        'Service Google non retenu conservé.');

    // Une sélection incohérente doit bloquer le build avant toute suppression.
    file_put_contents($fixture . '/composer.json', json_encode([
        'extra' => ['google/apiclient-services' => ['Absent']],
    ], JSON_THROW_ON_ERROR));
    try {
        pruneGoogleServices($fixture);
        throw new LogicException('Service Google absent accepté.');
    } catch (RuntimeException) {
    }
} finally {
    buildRemoveTree($fixture, dirname($fixture));
}

// La sélection AWS réduit le SDK monolithique sans retirer les services partagés.
$fixture = sys_get_temp_dir() . '/tycms-build-aws-' . bin2hex(random_bytes(8));
$sdk = $fixture . '/vendor/aws/aws-sdk-php/src';
$services = ['S3' => 's3', 'Kms' => 'kms', 'Ec2' => 'ec2', 'SSOOIDC' => 'sso-oidc', 'Sts' => 'sts'];
foreach ($services as $namespace => $model) {
    mkdir($sdk . '/' . $namespace, 0755, true);
    mkdir($sdk . '/data/' . $model, 0755, true);
    file_put_contents($sdk . '/data/' . $model . '/api-2.json', 'fixture');
}
file_put_contents($sdk . '/data/manifest.json.php', '<?php return ' . var_export(array_map(
    static fn (string $model): array => ['namespace' => $model],
    array_flip($services)
), true) . ';');
file_put_contents($fixture . '/composer.json', json_encode([
    'extra' => ['aws/aws-sdk-php' => ['S3']],
], JSON_THROW_ON_ERROR));
try {
    ob_start();
    pruneAwsServices($fixture);
    ob_end_clean();
    check(is_dir($sdk . '/S3') && is_dir($sdk . '/data/s3'), 'Service AWS retenu supprimé.');
    check(is_dir($sdk . '/Kms') && is_dir($sdk . '/Sts') && is_dir($sdk . '/data/sts'),
        'Service AWS partagé supprimé.');
    check(!file_exists($sdk . '/Ec2') && !file_exists($sdk . '/data/ec2'), 'Service AWS non retenu conservé.');

    // Une sélection incohérente doit bloquer le build avant toute suppression.
    file_put_contents($fixture . '/composer.json', json_encode([
        'extra' => ['aws/aws-sdk-php' => ['Nope']],
    ], JSON_THROW_ON_ERROR));
    try {
        pruneAwsServices($fixture);
        throw new LogicException('Service AWS absent accepté.');
    } catch (RuntimeException) {
    }
} finally {
    buildRemoveTree($fixture, dirname($fixture));
}

echo "Contrôles du build réussis : versions, sélection et confinement des sources.\n";
