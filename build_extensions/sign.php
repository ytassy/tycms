<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use Tycms\ExtensionDistribution\Protocol;
use YTassy\Tycms\Extensions\ExtensionMetadata;

function extensionSigningConfiguration(): array
{
    $configPath = getenv('TYCMS_EXTENSION_SIGNING_CONFIG') ?: __DIR__ . '/signing.local.php';
    if (!is_file($configPath) || is_link($configPath)) throw new RuntimeException('Configuration de signature absente ou symbolique : ' . $configPath . '. Copier build_extensions/signing.sample.php vers build_extensions/signing.local.php et renseigner la clé privée hors du dépôt.');
    if (!is_readable($configPath)) throw new RuntimeException('Configuration de signature illisible : ' . $configPath);
    $config = require $configPath;
    if (!is_array($config)) throw new RuntimeException('Configuration de signature invalide : ' . $configPath);
    $keyId = Protocol::keyId($config['key_id'] ?? null);
    if (!is_string($config['private_key_file'] ?? null) || $config['private_key_file'] === '') throw new RuntimeException('Renseigner private_key_file dans ' . $configPath);
    $file = realpath($config['private_key_file']);
    if ($file === false || !is_file($file)) throw new RuntimeException('Clé privée introuvable : ' . $config['private_key_file'] . '. La générer avec build_extensions/signing-key.php.');
    $repository = realpath(dirname(__DIR__));
    if ($file === false || !is_file($file) || str_starts_with($file, $repository . '/') || filesize($file) > 1024
        || (fileperms($file) & 0077) !== 0 || !preg_match('/\Aextensions-[A-Za-z0-9_-]+\.secret\z/', basename($file))) {
        throw new RuntimeException('Clé privée extensions-….secret requise hors du dépôt, avec les permissions 0600 : ' . $config['private_key_file']);
    }
    try { $secret = Protocol::decode(trim(extensionBuildRead($file, 1024)), SODIUM_CRYPTO_SIGN_SECRETKEYBYTES); }
    catch (RuntimeException $error) { throw new RuntimeException('Lecture de la clé privée refusée : ' . $file . '. ' . $error->getMessage(), 0, $error); }
    return [$keyId, $secret];
}

try {
    $tools = __DIR__ . '/functions.php';
    if (!is_file($tools) || !is_readable($tools)) throw new RuntimeException('Outil de build requis absent ou illisible : ' . $tools);
    require_once $tools;

    if (!extension_loaded('sodium') || !class_exists(ZipArchive::class)) throw new RuntimeException('PHP local avec sodium et zip requis pour la signature.');
    [$keyId, $secret] = extensionSigningConfiguration();
    try {
        if ($argc === 2 && $argv[1] === '--check') {
            echo 'Signature configurée : ' . $keyId . PHP_EOL;
            sodium_memzero($secret);
            exit(0);
        }
        if ($argc !== 3) throw new RuntimeException('Utiliser build_extensions/build.sh pour construire et signer, ou sign.php --check pour contrôler la configuration.');
        $work = realpath($argv[1]);
        $temporaryRoot = realpath(__DIR__ . '/temp');
        if ($work === false || $temporaryRoot === false || !str_starts_with($work, $temporaryRoot . '/')) throw new RuntimeException('Copie de travail hors du dossier temporaire autorisé.');
        $expectedFile = realpath($argv[2]);
        if ($expectedFile === false || !str_starts_with($expectedFile, $temporaryRoot . '/') || str_starts_with($expectedFile, $work . '/')) {
            throw new RuntimeException('Plan attendu absent ou accessible au conteneur npm : ' . $argv[2]);
        }
        $expected = json_decode(extensionBuildRead($expectedFile, 65536), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($expected) || !is_array($expected['metadata'] ?? null) || !array_key_exists('frontend_entry', $expected)) throw new RuntimeException('Plan attendu invalide : ' . $expectedFile);
        $metadata = ExtensionMetadata::validate($expected['metadata']);
        if (ExtensionMetadata::read($work . '/build-version.json') !== $metadata) throw new RuntimeException('La compilation a modifié la version préparée.');
        $base = ExtensionMetadata::artifactName($metadata);
        $artifacts = $work . '/artifacts';
        if (realpath($artifacts) !== $artifacts) throw new RuntimeException('Dossier d’artefacts absent ou symbolique : ' . $artifacts);
        $manifestFile = $artifacts . '/' . $base . '.extension-build.json';
        $data = json_decode(extensionBuildRead($manifestFile, 65536), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new RuntimeException('Manifeste de build invalide.');
        $manifest = Protocol::manifest($data);
        if ($manifest['metadata'] !== $metadata || $manifest['frontend_entry'] !== $expected['frontend_entry']) throw new RuntimeException('Le paquet ne correspond pas au plan préparé avant npm.');
        $archive = $artifacts . '/' . $base . '.zip';
        $notes = $artifacts . '/' . $base . '.md';
        $notesContents = extensionBuildRead($notes, Protocol::MAX_NOTES_BYTES);
        if (is_link($archive)) throw new RuntimeException('Archive symbolique interdite : ' . $archive);
        if (strlen($notesContents) !== $manifest['release_notes']['bytes']
            || !hash_equals($manifest['release_notes']['sha256'], hash('sha256', $notesContents))) throw new RuntimeException('Notes différentes des métadonnées : ' . $notes);
        Protocol::inspectArchive($archive, $manifest);
        $signature = Protocol::sign($manifest, $keyId, $secret);
        // Vérifier immédiatement la signature produite, avec la clé publique dérivée.
        Protocol::verify($signature, [$keyId => base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret))]);

        $distribution = __DIR__ . '/dist';
        extensionBuildDirectory($distribution);
        $staged = [];
        try {
            foreach ([$base . '.zip', $base . '.md', $base . '.extension-build.json', $base . '.extension-signature.json'] as $filename) {
                $target = $distribution . '/' . $filename;
                if (is_link($target) || (file_exists($target) && !is_file($target))) throw new RuntimeException('Destination d’artefact non ordinaire : ' . $target);
                $temp = tempnam($distribution, '.extension-');
                if ($temp === false) throw new RuntimeException('Préparation d’artefact impossible.');
                $staged[$target] = $temp;
                if ($filename === $base . '.extension-signature.json') extensionBuildWrite($temp, $signature);
                elseif (!@copy($artifacts . '/' . $filename, $temp)) throw new RuntimeException('Copie d’artefact impossible : ' . $artifacts . '/' . $filename);
                chmod($temp, 0644);
            }
            // Le marqueur signé est publié en dernier ; une interruption ne valide pas un ensemble partiel.
            $signatureTarget = $distribution . '/' . $base . '.extension-signature.json';
            if (is_file($signatureTarget) && !unlink($signatureTarget)) throw new RuntimeException('Retrait de l’ancienne signature impossible.');
            foreach ($staged as $target => $temp) {
                if (!@rename($temp, $target)) throw new RuntimeException('Activation d’artefact impossible : ' . $target);
            }
        } finally {
            foreach ($staged as $temp) if (is_file($temp)) unlink($temp);
        }
        echo 'Extension construite et signée : ' . $distribution . '/' . $base . '.zip' . PHP_EOL;
        echo 'Clé du Store : ' . $keyId . PHP_EOL;
    } finally {
        sodium_memzero($secret);
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Signature interrompue : ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
