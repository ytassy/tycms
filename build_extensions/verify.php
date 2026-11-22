<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Tycms\ExtensionDistribution\Protocol;

try {
    $tools = __DIR__ . '/functions.php';
    if (!is_file($tools) || !is_readable($tools)) throw new RuntimeException('Outil de build requis absent ou illisible : ' . $tools);
    require_once $tools;

    if (!extension_loaded('sodium') || !class_exists(ZipArchive::class)) throw new RuntimeException('PHP local avec sodium et zip requis pour vérifier les extensions.');
    if ($argc < 3 || $argc > 4) throw new RuntimeException('Usage : php build_extensions/verify.php archive.zip extensions-store-1.secret.pub [extensions-store-1]');
    $archive = realpath($argv[1]);
    if ($archive === false || !str_ends_with($archive, '.zip')) throw new RuntimeException('Archive ZIP introuvable : ' . $argv[1]);
    $signatureFile = substr($archive, 0, -4) . '.extension-signature.json';
    $public = extensionBuildRead($argv[2], 1024);
    $envelope = extensionBuildRead($signatureFile, 131072);
    $keyId = Protocol::keyId($argv[3] ?? 'extensions-store-1');
    $manifest = Protocol::verify($envelope, [$keyId => trim($public)]);
    if (basename($archive) !== $manifest['archive']['filename']) throw new RuntimeException('Nom d’archive différent du manifeste.');
    Protocol::inspectArchive($archive, $manifest);
    $notes = dirname($archive) . '/' . $manifest['release_notes']['filename'];
    $notesContents = extensionBuildRead($notes, Protocol::MAX_NOTES_BYTES);
    if (strlen($notesContents) !== $manifest['release_notes']['bytes']
        || !hash_equals($manifest['release_notes']['sha256'], hash('sha256', $notesContents))) throw new RuntimeException('Notes externes différentes du manifeste signé : ' . $notes);
    echo 'Signature, ZIP et notes valides : ' . $manifest['metadata']['id'] . ' ' . $manifest['metadata']['version'] . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Vérification refusée : ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
