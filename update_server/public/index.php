<?php

declare(strict_types=1);

use Tycms\Distribution\HttpError;
use Tycms\Distribution\Protocol;
use Tycms\Distribution\Repository;

ini_set('display_errors', '0');

require_once dirname(__DIR__) . '/lib/Protocol.php';
require_once dirname(__DIR__) . '/lib/Repository.php';

// Aucune session Tycms, aucun cookie ni secret dans les réponses de cette API autonome.
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
header('Cache-Control: no-store');
header('Content-Type: application/json; charset=utf-8');

$respond = static function (array $body, int $status = 200): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
};

try {
    $configuration = dirname(__DIR__) . '/configuration.php';
    if (!is_file($configuration)) {
        throw new RuntimeException('Configuration absente.');
    }
    $config = require $configuration;
    if (!is_array($config) || !extension_loaded('sodium') || !extension_loaded('zip')) {
        throw new RuntimeException('Configuration ou extensions PHP manquantes.');
    }
    $local = PHP_SAPI === 'cli-server' && ($config['allow_loopback_http'] ?? false) === true
        && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
    if (!in_array($_SERVER['HTTPS'] ?? '', ['on', '1'], true) && !$local) {
        throw new HttpError(400, 'HTTPS requis.');
    }
    $repository = new Repository($config, __DIR__);
    $action = $_GET['action'] ?? 'releases';
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    if (!is_string($action)) {
        throw new HttpError(400, 'Action invalide.');
    }

    if ($action === 'publish') {
        if ($method !== 'POST') {
            header('Allow: POST');
            throw new HttpError(405, 'Méthode refusée.');
        }
        $repository->authenticate($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null);
        $maximum = $config['max_package_bytes'] ?? 134217728;
        if (!is_int($maximum) || $maximum <= 0) {
            throw new RuntimeException('Limite de taille invalide.');
        }
        if ((float) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $maximum + Protocol::MAX_MANIFEST_BYTES + Protocol::MAX_NOTES_BYTES + 8192) {
            throw new HttpError(413, 'Corps de requête trop volumineux.');
        }
        $envelope = $_POST['manifest'] ?? null;
        $upload = $_FILES['package'] ?? null;
        if (is_array($upload) && in_array($upload['error'] ?? null, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw new HttpError(413, 'Paquet trop volumineux.');
        }
        if (!is_string($envelope) || strlen($envelope) > Protocol::MAX_MANIFEST_BYTES
            || !is_array($upload) || ($upload['error'] ?? null) !== UPLOAD_ERR_OK
            || !is_string($upload['tmp_name'] ?? null) || !is_uploaded_file($upload['tmp_name'])) {
            throw new HttpError(400, 'Manifeste et paquet multipart requis.');
        }
        $notes = $_POST['notes'] ?? null;
        if (($notes !== null && !is_string($notes)) || (is_string($notes) && strlen($notes) > Protocol::MAX_NOTES_BYTES)) {
            throw new HttpError(400, 'Notes de version invalides.');
        }
        $manifest = $repository->publish($upload['tmp_name'], $envelope, $notes);
        $respond(['published' => $manifest['version'], 'sha256' => $manifest['archive']['sha256']], 201);
    }

    if ($method !== 'GET') {
        header('Allow: GET');
        throw new HttpError(405, 'Méthode refusée.');
    }
    if ($action === 'releases') {
        $source = $_GET['source'] ?? null;
        $php = $_GET['php'] ?? null;
        if (($source !== null && !is_string($source)) || ($php !== null && !is_string($php))) {
            throw new HttpError(400, 'Filtres invalides.');
        }
        try {
            if ($source !== null) {
                Protocol::version($source);
            }
            if ($php !== null) {
                Protocol::version($php);
            }
        } catch (RuntimeException) {
            throw new HttpError(400, 'Filtres invalides.');
        }
        $respond(['schema_version' => 1, 'releases' => $repository->releases($source, $php)]);
    }
    if (!in_array($action, ['manifest', 'package', 'notes'], true)) {
        throw new HttpError(404, 'Action inconnue.');
    }
    try {
        $version = Protocol::version($_GET['version'] ?? null);
    } catch (RuntimeException) {
        throw new HttpError(400, 'Version invalide.');
    }
    $release = $repository->release($version);
    if ($action === 'manifest') {
        echo $release['envelope'];
        exit;
    }

    if ($action === 'notes') {
        if ($release['notes'] === null) {
            throw new HttpError(404, 'Notes de version absentes.');
        }
        header('Content-Type: text/markdown; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $release['manifest']['release_notes']['filename'] . '"');
        echo $release['notes'];
        exit;
    }

    // Le chemin et le nom proviennent exclusivement de la release validée côté serveur.
    $stream = fopen($release['package'], 'rb');
    if ($stream === false) {
        throw new RuntimeException('Paquet inaccessible.');
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $release['manifest']['archive']['filename'] . '"');
    header('Content-Length: ' . $release['manifest']['archive']['bytes']);
    fpassthru($stream);
    fclose($stream);
} catch (HttpError $error) {
    if ($error->status === 401) {
        header('WWW-Authenticate: Bearer');
    }
    $respond(['error' => $error->getMessage()], $error->status);
} catch (Throwable $error) {
    // Ne pas renvoyer la configuration, les chemins ni une trace au navigateur.
    error_log('Tycms distribution : erreur interne (' . get_class($error) . ').');
    $respond(['error' => 'Service de distribution indisponible.'], 503);
}
