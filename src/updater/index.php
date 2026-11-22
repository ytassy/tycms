<?php

declare(strict_types=1);

use Tycms\Updater\OperationStore;
use Tycms\Updater\UpdaterService;

require_once __DIR__ . '/UpdaterService.php';

// Le moteur reste autonome et ne charge ni session ni classes de la release.
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
$nonce = base64_encode(random_bytes(18));
// Le compilateur SFC utilise une exécution dynamique ; les styles injectés reçoivent ce nonce.
header("Content-Security-Policy: default-src 'none'; script-src 'self' 'unsafe-eval'; style-src 'self' 'nonce-{$nonce}'; connect-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit;
}

// Le nom du script public est la clé de l'opération, révocable par son état privé.
try {
    $store = new OperationStore(dirname(__DIR__));
    $entrypoint = basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $snapshot = $store->snapshot($entrypoint);
} catch (Throwable) {
    http_response_code(404);
    exit('Opération indisponible.');
}

$cookieName = 'tycms_updater_csrf';
$token = $_COOKIE[$cookieName] ?? null;
if ($method === 'GET') {
    // Le jeton est propre au chemin secret et sert aux futures actions POST.
    if (!is_string($token) || preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1) {
        $token = bin2hex(random_bytes(32));
        setcookie($cookieName, $token, [
            'path' => '/' . $entrypoint,
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    header('Content-Type: text/html; charset=UTF-8');
    $escapedToken = htmlspecialchars($token, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $escapedNonce = htmlspecialchars($nonce, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="updater-csrf" content="' . $escapedToken . '">'
        . '<meta name="updater-style-nonce" content="' . $escapedNonce . '">'
        . '<title>Mise à jour Tycms</title>'
        . '<link rel="stylesheet" href="/assets_updater/vendor/bootstrap.min.css">'
        . '<link rel="stylesheet" href="/assets_updater/app/updater.css">'
        . '<script defer src="/assets_updater/vendor/vue.runtime.global.prod.js"></script>'
        . '<script defer src="/assets_updater/vendor/vue3-sfc-loader.js"></script>'
        . '<script defer src="/assets_updater/app/main.js"></script></head>'
        . '<body><main id="updater-app" class="container py-5">'
        . '<h1>Mise à jour Tycms</h1><p id="updater-loading" role="status">Chargement de l’interface…</p>'
        . '<noscript>JavaScript est nécessaire pour afficher la mise à jour.</noscript>'
        . '</main></body></html>';
    exit;
}

header('Content-Type: application/json; charset=UTF-8');
$postedToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
$origin = $_SERVER['HTTP_ORIGIN'] ?? null;
$host = $_SERVER['HTTP_HOST'] ?? '';
$scheme = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
if (!is_string($token) || preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1
    || !is_string($postedToken) || !hash_equals($token, $postedToken)
    || ($origin !== null && (!is_string($origin) || !is_string($host) || $host === ''
        || $origin !== $scheme . '://' . $host))) {
    http_response_code(403);
    exit('{"error":"Action non autorisée."}');
}

$action = $_POST['action'] ?? null;
if (!is_string($action) || !in_array($action, ['status', 'prepare', 'maintenance', 'backup', 'skip_backup', 'migrate', 'activate', 'online', 'restore', 'rollback', 'external_restore', 'cancel'], true)) {
    http_response_code(400);
    exit('{"error":"Action inconnue."}');
}

try {
    $result = (new UpdaterService($store))->dispatch($entrypoint, $action);
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    http_response_code(409);
    $message = get_class($error) === RuntimeException::class
        ? $error->getMessage() : 'Cette étape a échoué. Consultez l’état avant de recommencer.';
    error_log('Tycms updater : échec ' . get_class($error));
    echo json_encode([
        'error' => $message . ($error->getPrevious() == null ? '' :  ("\n" . $error->getPrevious()?->getMessage())),
        'previous' => $error->getPrevious()?->getMessage(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
}
