<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once dirname(__DIR__) . '/update_server/lib/Protocol.php';

use Tycms\ExtensionDistribution\Protocol;
use YTassy\Tycms\Extensions\ExtensionMetadata;

$checks = 0;
function extensionCheck(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new LogicException($message);
    $checks++;
}
function extensionReject(callable $callback, ?string $message = null): void
{
    try { $callback(); }
    catch (RuntimeException | JsonException $error) {
        extensionCheck($message === null || str_contains($error->getMessage(), $message), 'Erreur sans indication du fichier ou de la cause attendue : ' . $error->getMessage());
        return;
    }
    throw new LogicException('Entrée dangereuse acceptée.');
}
function extensionTestDelete(string $path): void
{
    foreach (new FilesystemIterator($path) as $file) {
        if ($file->isDir() && !$file->isLink()) extensionTestDelete($file->getPathname());
        else unlink($file->getPathname());
    }
    rmdir($path);
}

$metadata = ['schema_version' => 1, 'id' => 'plg_demo', 'type' => 'plugin', 'folder' => 'Demo', 'version' => '0.1.0',
    'source_versions' => [], 'cms_min' => '1.1.3', 'cms_max_exclusive' => '1.2.0', 'php_min' => '8.4.1', 'php_max_exclusive' => '8.6.0'];
extensionCheck(ExtensionMetadata::validate($metadata) === $metadata, 'Métadonnées valides refusées.');
extensionCheck(ExtensionMetadata::validate(array_replace($metadata, ['source_versions' => '*']))['source_versions'] === '*',
    'Marqueur « * » refusé pour les versions sources.');
foreach ([['folder' => '../Demo'], ['folder' => '/Demo'], ['id' => 'plg_core'], ['type' => []],
    ['version' => '01.1.0'], ['source_versions' => ['0.1.0']], ['source_versions' => ['0.0.1', '0.0.1']],
    ['source_versions' => '*foo'], ['source_versions' => '1.0.0'], ['source_versions' => ['*']],
    ['cms_max_exclusive' => '1.0.0'], ['php_min' => '8.6.0'], ['extra' => 'secret']] as $changes) {
    extensionReject(fn () => ExtensionMetadata::validate(array_replace($metadata, $changes)));
}
foreach (['../outside', '/root/file', 'assets/../../file', 'extension/.env', 'extension/a\\b', 'assets/a%2fb'] as $path) {
    extensionReject(fn () => Protocol::safePath($path));
}
foreach (['.env', 'frontend/node_modules/a.js', 'tests/Fixture.php', 'private.secret', 'config.local.php', 'vendor/a.php', 'logs/app.txt'] as $path) {
    extensionCheck(!extensionBuildSource($path), 'Source privée ou locale sélectionnée : ' . $path);
}
extensionCheck(!extensionBuildSource('build/old.js', true), 'Ancien bundle sélectionné.');
extensionCheck(!extensionBuildSource('evil.php.jpg', true), 'PHP public sélectionné.');
extensionCheck(extensionBuildSource('PageComponents/CourseList.php'), 'Code de production exclu.');
extensionCheck(extensionBuildSource('logo.svg', true), 'Média statique exclu.');

$fixture = sys_get_temp_dir() . '/tycms-extension-test-' . bin2hex(random_bytes(8));
extensionBuildDirectory($fixture);
try {
    extensionBuildCommand(['git', 'init', '--quiet', $fixture], $fixture);
    $source = $fixture . '/src/extensions/plugins/Demo';
    $assets = $fixture . '/src/public/assets/plg_demo';
    extensionBuildDirectory($source);
    extensionBuildDirectory($assets . '/build');
    extensionBuildWrite($source . '/version.json', json_encode($metadata, JSON_THROW_ON_ERROR));
    extensionBuildWrite($source . '/version.md', 'Première version de démonstration.');
    extensionBuildWrite($source . '/Plugin.php', '<?php throw new RuntimeException("Le build ne doit jamais exécuter ce fichier.");');
    extensionBuildWrite($source . '/.env', 'secret de test');
    extensionBuildWrite($source . '/tests/Fixture.php', '<?php');
    extensionBuildWrite($source . '/Old.vue', '<template>source privée</template>');
    extensionBuildWrite($assets . '/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
    extensionBuildWrite($assets . '/build/stale.js', 'ancien bundle');
    extensionReject(fn () => ExtensionMetadata::read($source . '/missing.json'), $source . '/missing.json');
    extensionReject(fn () => extensionBuildRead($source . '/missing.md', 1024), $source . '/missing.md');
    extensionReject(fn () => extensionBuildCopy($source . '/missing.php', $fixture . '/unused', $fixture), $source . '/missing.php');
    extensionReject(fn () => extensionBuildCommand([PHP_BINARY, '-r', 'exit(3);'], $fixture), 'code 3');
    extensionBuildWrite($source . '/invalid.json', '{');
    extensionReject(fn () => ExtensionMetadata::read($source . '/invalid.json'), $source . '/invalid.json');
    unlink($source . '/invalid.json');
    rename($source . '/version.md', $source . '/version.saved');
    extensionReject(fn () => extensionBuildPrepare($fixture, 'plugins/Demo'), '/version.md');
    rename($source . '/version.saved', $source . '/version.md');
    foreach (['extension-vite.mjs', 'shared-esm.mjs'] as $tool) {
        extensionBuildCopy(dirname(__DIR__) . '/src/tools/' . $tool, $fixture . '/src/tools/' . $tool, dirname(__DIR__));
    }
    extensionCheck(extensionBuildSelection($fixture, 'plugins/Demo') === 'plugins/Demo', 'Extension non détectée.');
    $work = extensionBuildPrepare($fixture, 'plugins/Demo');
    extensionCheck(!file_exists($work . '/src/extensions/plugins/Demo/.env'), 'Secret copié.');
    extensionCheck(!file_exists($work . '/src/public/assets/plg_demo/build/stale.js'), 'Ancien bundle copié.');
    $preparedSource = $work . '/src/extensions/plugins/Demo';
    rename($preparedSource . '/Plugin.php', $preparedSource . '/Plugin.saved');
    extensionReject(fn () => extensionBuildCompile($work), $preparedSource . '/Plugin.php');
    rename($preparedSource . '/Plugin.saved', $preparedSource . '/Plugin.php');
    // Signaler chaque prérequis frontend avant de lancer npm.
    $originalPlan = file_get_contents($work . '/build-plan.json');
    extensionBuildWrite($work . '/build-plan.json', json_encode(['metadata' => $metadata, 'frontend_entry' => 'src/main.js'], JSON_THROW_ON_ERROR));
    foreach (['package.json', 'package-lock.json', 'src/main.js'] as $required) {
        extensionReject(fn () => extensionBuildCompile($work), $preparedSource . '/frontend/' . $required);
        extensionBuildWrite($preparedSource . '/frontend/' . $required, '{}');
    }
    extensionTestDelete($preparedSource . '/frontend');
    extensionBuildWrite($work . '/build-plan.json', $originalPlan);
    extensionBuildCompile($work);
    $base = ExtensionMetadata::artifactName($metadata);
    $archive = $work . '/artifacts/' . $base . '.zip';
    $manifest = json_decode(file_get_contents($work . '/artifacts/' . $base . '.extension-build.json'), true, 16, JSON_THROW_ON_ERROR);
    Protocol::inspectArchive($archive, $manifest);
    extensionCheck(!is_file($work . '/package/extension/Old.vue'), 'Source Vue livrée.');

    $pair = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey($pair);
    $public = base64_encode(sodium_crypto_sign_publickey($pair));
    $signed = Protocol::sign($manifest, 'extensions-test-1', $secret);
    extensionCheck(Protocol::verify($signed, ['extensions-test-1' => $public]) === $manifest, 'Signature valide refusée.');
    extensionReject(fn () => Protocol::verify($signed, []));
    extensionReject(fn () => Protocol::verify($signed, ['extensions-test-1' => base64_encode(random_bytes(32))]));
    $tampered = json_decode($signed, true, 16, JSON_THROW_ON_ERROR);
    $tampered['payload'] = base64_encode(base64_decode($tampered['payload']) . ' ');
    extensionReject(fn () => Protocol::verify(json_encode($tampered), ['extensions-test-1' => $public]));
    extensionReject(fn () => \Tycms\Distribution\Protocol::verify($signed, ['extensions-test-1' => $public]));
    extensionReject(fn () => Protocol::manifest(array_replace($manifest, ['artifact_type' => 'system'])));

    // Recalculer l’empreinte permet de tester la structure indépendamment du contrôle d’intégrité.
    foreach ([
        '../escape.php' => '<?php',
        'extension/plugin.php' => '<?php',
        'extension/frontend/main.js' => 'source',
        'extension/node_modules/a.js' => 'source',
        'assets/evil.php.jpg' => '<?php',
        'assets/bundle.js.map' => '{}',
    ] as $name => $content) {
        $bad = $fixture . '/bad.zip';
        copy($archive, $bad);
        $zip = new ZipArchive(); $zip->open($bad); $zip->addFromString($name, $content); $zip->close();
        $changed = $manifest;
        $changed['archive']['bytes'] = filesize($bad);
        $changed['archive']['sha256'] = hash_file('sha256', $bad);
        extensionReject(fn () => Protocol::inspectArchive($bad, $changed));
        unlink($bad);
    }
    $bad = $fixture . '/bad.zip';
    copy($archive, $bad);
    $zip = new ZipArchive(); $zip->open($bad); $zip->addFromString('assets/link', '../../outside');
    $zip->setExternalAttributesName('assets/link', ZipArchive::OPSYS_UNIX, 0120777 << 16); $zip->close();
    $changed = $manifest; $changed['archive']['bytes'] = filesize($bad); $changed['archive']['sha256'] = hash_file('sha256', $bad);
    extensionReject(fn () => Protocol::inspectArchive($bad, $changed));
    extensionReject(fn () => Protocol::inspectArchive($bad, $manifest));
    unlink($bad);

    $changed = $manifest; $changed['frontend_entry'] = 'src/main.js';
    extensionReject(fn () => Protocol::inspectArchive($archive, $changed), 'assets/.vite/manifest.json');
    $zip = new ZipArchive(); $zip->open($archive);
    extensionCheck($zip->locateName('assets/logo.svg') !== false, 'Média statique non livré.');
    extensionCheck($zip->locateName('extension/tests/Fixture.php') === false, 'Tests livrés.');
    $zip->close();
    sodium_memzero($secret); sodium_memzero($pair);
} finally {
    extensionTestDelete($fixture);
}
echo 'Contrôles des extensions réussis : ' . $checks . " assertions, métadonnées, ZIP et signatures.\n";
