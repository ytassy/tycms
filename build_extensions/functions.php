<?php

declare(strict_types=1);

// Une installation incomplète doit produire une erreur lisible, sans avertissement PHP brut.
foreach ([dirname(__DIR__) . '/src/releases/vdev/framework/Extensions/ExtensionMetadata.php', __DIR__ . '/Protocol.php'] as $requiredTool) {
    if (!is_file($requiredTool) || !is_readable($requiredTool)) throw new RuntimeException('Outil de build requis absent ou illisible : ' . $requiredTool);
    require_once $requiredTool;
}

use Tycms\ExtensionDistribution\Protocol;
use YTassy\Tycms\Extensions\ExtensionMetadata;

function extensionBuildCommand(array $command, string $directory, bool $capture = false): string
{
    if (!is_dir($directory)) throw new RuntimeException('Dossier de commande introuvable : ' . $directory);
    $process = @proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => $capture ? ['pipe', 'w'] : STDOUT, 2 => STDERR], $pipes, $directory);
    if (!is_resource($process)) throw new RuntimeException('Impossible de lancer « ' . implode(' ', $command) . ' » dans ' . $directory);
    $output = $capture ? stream_get_contents($pipes[1]) : '';
    if ($capture) fclose($pipes[1]);
    $status = proc_close($process);
    if ($status !== 0) throw new RuntimeException('Échec de « ' . implode(' ', $command) . ' » (code ' . $status . '), dossier : ' . $directory);
    return $output;
}

function extensionBuildRead(string $path, int $maximum): string
{
    if (!is_file($path)) throw new RuntimeException('Fichier requis introuvable : ' . $path);
    if (is_link($path)) throw new RuntimeException('Lien symbolique interdit : ' . $path);
    if (!is_readable($path) || filesize($path) > $maximum) throw new RuntimeException('Fichier illisible ou trop volumineux : ' . $path);
    $contents = @file_get_contents($path);
    if ($contents === false) throw new RuntimeException('Lecture impossible : ' . $path);
    return $contents;
}

function extensionBuildDirectory(string $directory): void
{
    if ((!is_dir($directory) && !@mkdir($directory, 0755, true)) || realpath($directory) !== rtrim($directory, '/')) {
        throw new RuntimeException('Dossier inaccessible ou symbolique : ' . $directory);
    }
}

function extensionBuildWrite(string $path, string $contents): void
{
    extensionBuildDirectory(dirname($path));
    if (is_link($path) || @file_put_contents($path, $contents) !== strlen($contents)) throw new RuntimeException('Écriture impossible ou incomplète : ' . $path);
}

function extensionBuildCopy(string $source, string $target, string $root): void
{
    if (realpath($source) !== $source || !str_starts_with($source, $root . '/') || !is_file($source)) {
        throw new RuntimeException('Source absente ou lien symbolique interdit : ' . $source);
    }
    extensionBuildDirectory(dirname($target));
    if (is_link($target) || !@copy($source, $target)) throw new RuntimeException('Copie impossible de ' . $source . ' vers ' . $target);
    chmod($target, 0644);
}

/** Les mêmes exclusions s’appliquent aux sources de compilation et au paquet de production. */
function extensionBuildSource(string $relative, bool $publicAssets = false): bool
{
    if (preg_match('~(?:^|/)(?:\.[^/]*|node_modules|vendor|tests?|tools|dist|logs?|tmp|cache|backups?)(?:/|$)|(?:\.local\.|\.sample\.|\.example\.)|\.(?:secret|pem|key|crt|map)$~i', $relative)) return false;
    if ($publicAssets && (str_starts_with($relative, 'build/') || preg_match('~\.(?:php[0-9]*|phtml|phar|cgi|pl|sh)(?:\.|$)~i', $relative))) return false;
    if (in_array(strtolower(basename($relative)), ['auth.json', 'configuration.local.php'], true)) return false;
    return true;
}

/** Ne pas charger Plugin.php : le menu reste une lecture de fichiers, sans démarrer le CMS. */
function extensionBuildSelection(string $repository, ?string $selection): ?string
{
    $available = [];
    foreach (['plugins', 'modules', 'templates'] as $kind) {
        foreach (glob($repository . '/src/extensions/' . $kind . '/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $path = $kind . '/' . basename($directory);
            try {
                if (realpath($directory) !== $directory) throw new RuntimeException('Dossier symbolique.');
                $metadata = ExtensionMetadata::read($directory . '/version.json', $path);
                $available[$path] = $metadata;
            } catch (Throwable $error) {
                if ($selection === $path) throw new RuntimeException($path . ' : ' . $error->getMessage());
                fwrite(STDERR, $path . ' — non compilable : ' . $error->getMessage() . PHP_EOL);
            }
        }
    }
    ksort($available);
    if ($selection !== null) {
        if (!isset($available[$selection])) throw new RuntimeException('Extension inconnue ou sans version.json valide : ' . $selection);
        return $selection;
    }
    if ($available === []) throw new RuntimeException('Aucune extension compilable.');
    fwrite(STDERR, "Extensions disponibles :\n");
    foreach (array_values($available) as $i => $metadata) {
        fwrite(STDERR, ($i + 1) . '. ' . ExtensionMetadata::relativePath($metadata) . ' — ' . $metadata['version'] . PHP_EOL);
    }
    fwrite(STDERR, "0. Annuler\nChoix : ");
    $choice = fgets(STDIN);
    if ($choice === false || trim($choice) === '0') return null;
    $choice = trim($choice);
    if (!ctype_digit($choice) || !isset(array_values($available)[(int) $choice - 1])) throw new RuntimeException('Choix invalide.');
    $metadata = array_values($available)[(int) $choice - 1];
    fwrite(STDERR, json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\nConstruire et signer ce paquet ? [o/N] : ");
    $answer = fgets(STDIN);
    return $answer !== false && in_array(strtolower(trim($answer)), ['o', 'oui'], true) ? ExtensionMetadata::relativePath($metadata) : null;
}

function extensionBuildPrepare(string $repository, string $selection): string
{
    if (!preg_match('~\A(?:plugins|modules|templates)/[A-Z][A-Za-z0-9]{0,63}\z~', $selection)) throw new RuntimeException('Dossier d’extension invalide.');
    $directory = $repository . '/src/extensions/' . $selection;
    if (realpath($directory) !== $directory) throw new RuntimeException('Dossier d’extension absent ou symbolique : ' . $directory);
    $metadata = ExtensionMetadata::read($directory . '/version.json', $selection);
    if (extensionBuildRead($directory . '/version.md', Protocol::MAX_NOTES_BYTES) === '') throw new RuntimeException('Notes de version vides : ' . $directory . '/version.md');
    $base = ExtensionMetadata::artifactName($metadata);
    $temporary = $repository . '/build_extensions/temp';
    extensionBuildDirectory($temporary);
    $work = $temporary . '/' . $base . '-' . bin2hex(random_bytes(8));
    if (!@mkdir($work, 0700)) throw new RuntimeException('Création de la copie de travail impossible : ' . $work);

    // Git fournit l’inventaire, y compris les nouveaux fichiers non ignorés ; le contenu courant est figé.
    $codePrefix = 'src/extensions/' . $selection . '/';
    $assetsPrefix = 'src/public/assets/' . $metadata['id'] . '/';
    $inventory = extensionBuildCommand(['git', '-c', 'safe.directory=' . $repository, 'ls-files', '--cached', '--others', '--exclude-standard', '-z', '--', $codePrefix, $assetsPrefix], $repository, true);
    foreach (array_unique(explode("\0", $inventory)) as $path) {
        if ($path === '') continue;
        $public = str_starts_with($path, $assetsPrefix);
        $relative = substr($path, strlen($public ? $assetsPrefix : $codePrefix));
        if (!extensionBuildSource($relative, $public)) continue;
        Protocol::safePath($relative);
        extensionBuildCopy($repository . '/' . $path, $work . '/' . $path, $repository);
    }
    // Les métadonnées sont obligatoires, même si le fichier vient juste d’être créé.
    extensionBuildWrite($work . '/' . $codePrefix . 'version.json', json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    extensionBuildCopy($directory . '/version.md', $work . '/' . $codePrefix . 'version.md', $repository);
    foreach (['extension-vite.mjs', 'shared-esm.mjs'] as $tool) {
        extensionBuildCopy($repository . '/src/tools/' . $tool, $work . '/src/tools/' . $tool, $repository);
    }
    extensionBuildDirectory($work . '/src/public/assets');
    extensionBuildWrite($work . '/build-version.json', json_encode($metadata, JSON_THROW_ON_ERROR));
    extensionBuildWrite($work . '/build-plan.json', json_encode(['metadata' => $metadata,
        'frontend_entry' => is_dir($directory . '/frontend') ? 'src/main.js' : null], JSON_THROW_ON_ERROR));
    return $work;
}

/** Copier uniquement des fichiers ordinaires ; aucun lien créé par npm ne traverse l’assemblage. */
function extensionBuildTree(string $source, string $target, string $root, bool $runtime = false, bool $assets = false): void
{
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)) as $file) {
        $relative = substr($file->getPathname(), strlen($source) + 1);
        if ($runtime && (str_starts_with($relative, 'frontend/') || !extensionBuildSource($relative))) continue;
        if ($runtime && str_ends_with(strtolower($relative), '.md') && $relative !== 'version.md') continue;
        if ($runtime && preg_match('/\.(?:vue|ts|tsx)$/i', $relative)) continue;
        // Les fichiers générés .vite sont nécessaires ; les secrets et les sourcemaps restent exclus.
        if ($assets && $relative !== '.vite/manifest.json' && !extensionBuildSource($relative)) {
            if (str_starts_with($relative, 'build/')) throw new RuntimeException('Sortie de build interdite : ' . $relative);
            continue;
        }
        Protocol::safePath($relative);
        extensionBuildCopy($file->getPathname(), $target . '/' . $relative, $root);
    }
}

function extensionBuildCompile(string $work): void
{
    $metadata = ExtensionMetadata::read($work . '/build-version.json');
    $plan = json_decode(extensionBuildRead($work . '/build-plan.json', 65536), true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($plan) || !array_key_exists('frontend_entry', $plan) || ($plan['metadata'] ?? null) !== $metadata
        || !in_array($plan['frontend_entry'], [null, 'src/main.js'], true)) throw new RuntimeException('Plan de build absent ou incohérent : ' . $work . '/build-plan.json');
    $selection = ExtensionMetadata::relativePath($metadata);
    $directory = $work . '/src/extensions/' . $selection;
    $assets = $work . '/src/public/assets/' . $metadata['id'];
    if (is_file($directory . '/composer.json')) throw new RuntimeException('Dépendances Composer non prises en charge dans ce premier contrat : ' . $directory . '/composer.json');
    $entry = ['plugin' => 'Plugin.php', 'module' => 'Module.php', 'template' => 'Template.php'][$metadata['type']];
    if (!is_file($directory . '/' . $entry)) throw new RuntimeException('Point d’entrée PHP requis introuvable : ' . $directory . '/' . $entry);

    // Le frontend se compile dans la copie ; aucune dépendance du Vite local n’est touchée.
    $frontendEntry = $plan['frontend_entry'];
    if ($frontendEntry !== null) {
        foreach (['package.json', 'package-lock.json', 'src/main.js'] as $required) {
            if (!is_file($directory . '/frontend/' . $required)) throw new RuntimeException('Fichier frontend requis introuvable : ' . $directory . '/frontend/' . $required);
        }
        extensionBuildCommand(['npm', 'ci', '--include=dev', '--no-audit', '--no-fund'], $directory . '/frontend');
        extensionBuildCommand(['npm', 'run', 'build'], $directory . '/frontend');
    }

    $package = $work . '/package';
    extensionBuildDirectory($package . '/extension');
    extensionBuildTree($directory, $package . '/extension', $work, true);
    if (is_dir($assets)) extensionBuildTree($assets, $package . '/assets', $work, false, true);
    $internal = ExtensionMetadata::read($package . '/extension/version.json', $selection);
    if ($internal !== $metadata) throw new RuntimeException('La compilation a modifié les métadonnées de l’extension.');

    // Contrôler la syntaxe sans exécuter les classes de l’extension ni ses migrations.
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($package, FilesystemIterator::SKIP_DOTS)) as $file) {
        $relative = substr($file->getPathname(), strlen($package) + 1);
        Protocol::safePath($relative);
        if ($file->isLink() || !$file->isFile()) throw new RuntimeException('Fichier de paquet non ordinaire.');
        if (str_ends_with(strtolower($relative), '.php')) extensionBuildCommand([PHP_BINARY, '-l', $file->getPathname()], $package);
        $files[$relative] = $file->getPathname();
    }
    ksort($files);
    $base = ExtensionMetadata::artifactName($metadata);
    $artifacts = $work . '/artifacts';
    extensionBuildDirectory($artifacts);
    $archive = $artifacts . '/' . $base . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::EXCL) !== true) throw new RuntimeException('Création du ZIP impossible : ' . $archive);
    foreach ($files as $relative => $file) {
        if (!$zip->addFile($file, $relative)) throw new RuntimeException('Ajout au ZIP impossible : ' . $file);
        $zip->setExternalAttributesName($relative, ZipArchive::OPSYS_UNIX, 0100644 << 16);
        $zip->setMtimeName($relative, 315532800);
    }
    if (!$zip->close()) throw new RuntimeException('Fermeture du ZIP impossible.');
    $notes = extensionBuildRead($package . '/extension/version.md', Protocol::MAX_NOTES_BYTES);
    extensionBuildWrite($artifacts . '/' . $base . '.md', $notes);
    $manifest = ['schema_version' => 1, 'artifact_type' => 'tycms-extension', 'metadata' => $metadata, 'frontend_entry' => $frontendEntry,
        'archive' => ['filename' => $base . '.zip', 'bytes' => filesize($archive), 'sha256' => hash_file('sha256', $archive)],
        'release_notes' => ['filename' => $base . '.md', 'bytes' => strlen($notes), 'sha256' => hash('sha256', $notes)]];
    Protocol::inspectArchive($archive, $manifest);
    extensionBuildWrite($artifacts . '/' . $base . '.extension-build.json', json_encode(Protocol::manifest($manifest), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    echo 'Paquet préparé : ' . $base . PHP_EOL;
}
