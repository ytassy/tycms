<?php

declare(strict_types=1);

use Tycms\Distribution\Admin;
use Tycms\Distribution\HttpError;
use Tycms\Distribution\Protocol;
use Tycms\Distribution\Repository;

require_once dirname(__DIR__) . '/lib/Protocol.php';
require_once dirname(__DIR__) . '/lib/Admin.php';
require_once dirname(__DIR__) . '/lib/Repository.php';
require_once dirname(__DIR__, 2) . '/build/functions.php';

function check(bool $value, string $message): void
{
    if (!$value) {
        throw new RuntimeException($message);
    }
}

function refused(callable $operation, string $message, ?int $status = null): void
{
    try {
        $operation();
    } catch (Throwable $error) {
        if ($status !== null) {
            check($error instanceof HttpError && $error->status === $status, $message . ' (statut inattendu)');
        }
        return;
    }
    throw new RuntimeException($message);
}

function removeFixture(string $directory): void
{
    foreach (new DirectoryIterator($directory) as $entry) {
        if ($entry->isDot()) {
            continue;
        }
        if ($entry->isDir() && !$entry->isLink()) {
            removeFixture($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($directory);
}

function command(array $arguments, ?string $input = null, ?array $environment = null): array
{
    $process = proc_open($arguments, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
    check(is_resource($process), 'Processus de test inaccessible.');
    if ($input !== null) {
        fwrite($pipes[0], $input);
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
}

function request(string $url, ?string $token = null, ?string $zip = null, ?string $envelope = null, ?string $notes = "# Notes de test\n"): array
{
    $curl = curl_init($url);
    $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_PROXY => ''];
    if ($token !== null) {
        $options[CURLOPT_HTTPHEADER] = ['Authorization: Bearer ' . $token, 'Expect:'];
    }
    if ($zip !== null) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = ['manifest' => $envelope, 'package' => new CURLFile($zip)];
        if ($notes !== null) {
            $options[CURLOPT_POSTFIELDS]['notes'] = $notes;
        }
    }
    curl_setopt_array($curl, $options);
    $body = curl_exec($curl);
    check(is_string($body), 'Requête HTTP locale impossible.');
    return [curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $body];
}

/**
 * Requête d'administration : formulaire URL-encodé, cookie de session optionnel
 * et redirections non suivies pour observer le 303 après une action.
 */
function adminRequest(string $url, ?array $form = null, ?string $cookie = null): array
{
    $curl = curl_init($url);
    $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_PROXY => '',
        CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false];
    if ($cookie !== null) {
        $options[CURLOPT_COOKIE] = 'tycms_distribution_admin=' . $cookie;
    }
    if ($form !== null) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = http_build_query($form);
    }
    curl_setopt_array($curl, $options);
    $response = curl_exec($curl);
    check(is_string($response), 'Requête d’administration impossible.');
    $headerBytes = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    preg_match_all('/^Set-Cookie:\s*([^;\r\n]+)/mi', substr($response, 0, $headerBytes), $cookies);
    $setCookie = $cookies[1] !== [] ? trim(end($cookies[1])) : null;

    return [curl_getinfo($curl, CURLINFO_RESPONSE_CODE), substr($response, $headerBytes), $setCookie,
        substr($response, 0, $headerBytes)];
}

umask(0077);
$root = sys_get_temp_dir() . '/tycms-distribution-test-' . bin2hex(random_bytes(8));
mkdir($root);
$server = null;
try {
    $repositoryRoot = dirname(__DIR__, 2);
    $pair = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey($pair);
    $public = base64_encode(sodium_crypto_sign_publickey($pair));
    $token = bin2hex(random_bytes(32));
    mkdir($root . '/server');
    foreach (['public', 'lib'] as $directory) {
        mkdir($root . '/server/' . $directory);
    }
    foreach (['public/index.php', 'lib/Protocol.php', 'lib/Repository.php', 'lib/Admin.php', 'lib/AdminView.php'] as $file) {
        copy(dirname(__DIR__) . '/' . $file, $root . '/server/' . $file);
    }
    $adminPassword = 'mot-de-passe-administration-test';
    $config = ['storage_path' => $root . '/storage', 'publish_token_sha256' => [hash('sha256', $token)],
        'trusted_public_keys' => ['test-key' => $public], 'max_package_bytes' => 1000000, 'allow_loopback_http' => true,
        'admin_user' => 'admin', 'admin_password_hash' => password_hash($adminPassword, PASSWORD_ARGON2ID)];
    file_put_contents($root . '/server/configuration.php', '<?php return ' . var_export($config, true) . ';');

    // Fabriquer une archive factice : aucun code du site ni donnée SQL n'est utilisé.
    $zipPath = $root . '/tycms-1_0_1.zip';
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE);
    foreach (['index.php', 'configuration.base.php', 'composer.json', 'composer.lock', 'vendor/autoload.php'] as $file) {
        $zip->addFromString('releases/v1_0_1/' . $file, '<?php // fixture');
    }
    $zip->addFromString('releases/v1_0_1/version.php', "<?php\ndefine('APP_VERSION', '1.0.1');\ndefine('APP_VERSION_STRING', '1_0_1');\n");
    foreach (['public/index.php', 'public/.htaccess', 'updater/index.php', 'var/releases-states.json'] as $file) {
        $zip->addFromString($file, 'fixture');
    }
    $zip->close();
    $notes = "# Notes de test\n";
    file_put_contents($root . '/version.md', $notes);
    $metadata = ['version' => '1.0.1', 'version_string' => '1_0_1',
        'source_versions' => ['1.0.0'], 'php_min' => '8.4.1', 'php_max_exclusive' => '8.6.0'];
    ob_start();
    finishReleaseArtifacts($zipPath, $root . '/version.md', $metadata, $root);
    ob_end_clean();
    $metadataPath = $root . '/tycms-1_0_1.build.json';
    $notesPath = $root . '/tycms-1_0_1.md';
    $manifest = json_decode(file_get_contents($metadataPath), true, 16, JSON_THROW_ON_ERROR);
    check(file_get_contents($notesPath) === $notes, 'Notes non figées par le build.');
    file_put_contents($root . '/version.md', 'Notes du développement suivant');
    check(file_get_contents($notesPath) === $notes, 'Notes figées modifiées par le développement suivant.');
    $envelope = Protocol::sign($manifest, 'test-key', $secret);
    check(Protocol::verify($envelope, ['test-key' => $public]) === $manifest, 'Signature correcte refusée.');
    // L'indentation de l'enveloppe ne change ni le payload signé ni sa compatibilité.
    check(str_contains($envelope, "\n    \"key_id\""), 'Enveloppe non indentée.');
    $compact = json_encode(json_decode($envelope, true, 16, JSON_THROW_ON_ERROR), JSON_THROW_ON_ERROR);
    check(Protocol::verify($compact, ['test-key' => $public]) === $manifest, 'Ancienne enveloppe compacte refusée.');
    refused(fn() => Protocol::verify($envelope, []), 'Clé inconnue acceptée.');
    $tampered = json_decode($envelope, true);
    $tampered['payload'] = base64_encode(str_replace('8.4.1', '8.0.0', base64_decode($tampered['payload'])));
    refused(fn() => Protocol::verify(json_encode($tampered), ['test-key' => $public]), 'Manifeste altéré accepté.');
    foreach (['../1.0.0', '01.0.0', '1.0.0-rc1', ['1.0.0']] as $invalid) {
        refused(fn() => Protocol::version($invalid), 'Version invalide acceptée.');
    }
    foreach ([['1.0.1'], ['2.0.0'], ['1.0.0', '1.0.0'], ['>=1.0.0'],
        '*foo', '1.0.0', ['*']] as $sources) {
        $invalid = array_replace($manifest, ['source_versions' => $sources]);
        refused(fn() => Protocol::validate($invalid), 'Liste de sources invalide acceptée.');
    }
    check(Protocol::compatible($manifest, '1.0.0', '8.4.1'), 'Borne PHP minimale refusée.');
    check(!Protocol::compatible($manifest, '1.0.0', '8.6.0'), 'Borne PHP maximale acceptée.');
    check(!Protocol::compatible($manifest, '0.9.0', '8.4.1'), 'Source non autorisée acceptée.');
    $star = array_replace($manifest, ['source_versions' => '*']);
    Protocol::validate($star);
    check(Protocol::compatible($star, '0.9.0', '8.4.1'), 'Marqueur « * » refusé pour une version antérieure.');
    check(Protocol::compatible($star, '1.0.0', '8.4.1'), 'Marqueur « * » refusé pour une version source.');
    check(!Protocol::compatible($star, '1.0.1', '8.4.1'), 'Marqueur « * » accepté pour la cible elle-même.');
    check(!Protocol::compatible($star, '1.0.0', '8.6.0'), 'Borne PHP maximale ignorée avec « * ».');
    $initialOnly = array_replace($manifest, ['source_versions' => []]);
    Protocol::validate($initialOnly);
    check(!Protocol::compatible($initialOnly, '1.0.0', '8.4.1'), 'Liste vide interprétée comme compatibilité universelle.');
    Protocol::inspectArchive($zipPath, '1.0.1');
    refused(fn() => Protocol::inspectArchive($zipPath, '1.0.2'), 'Mauvaise version de ZIP acceptée.');
    $badZip = $root . '/unsafe.zip';
    copy($zipPath, $badZip);
    $zip->open($badZip);
    $zip->deleteName('releases/v1_0_1/configuration.base.php');
    $zip->close();
    refused(fn() => Protocol::inspectArchive($badZip, '1.0.1'), 'Archive sans configuration de base acceptée.');
    copy($zipPath, $badZip);
    $zip->open($badZip);
    $zip->addFromString('../secret.php', 'fixture');
    $zip->close();
    refused(fn() => Protocol::inspectArchive($badZip, '1.0.1'), 'Traversée ZIP acceptée.');
    copy($zipPath, $badZip);
    $zip->open($badZip);
    $zip->addFromString('public/link', '../secret');
    $zip->setExternalAttributesName('public/link', ZipArchive::OPSYS_UNIX, 0120777 << 16);
    $zip->close();
    refused(fn() => Protocol::inspectArchive($badZip, '1.0.1'), 'Lien symbolique ZIP accepté.');
    copy($zipPath, $badZip);
    $zip->open($badZip);
    $zip->addFromString('releases/v1_0_1/configuration.php', 'secret factice');
    $zip->close();
    refused(fn() => Protocol::inspectArchive($badZip, '1.0.1'), 'Configuration locale embarquée acceptée.');

    $repository = new Repository($config, $root . '/server/public');
    $legacy = $manifest;
    unset($legacy['release_notes']);
    $legacyRepository = new Repository(array_replace($config, ['storage_path' => $root . '/legacy']), $root . '/server/public');
    $legacyRepository->publish($zipPath, Protocol::sign($legacy, 'test-key', $secret));
    check($legacyRepository->release('1.0.1', Repository::CHANNEL_PENDING)['notes'] === null, 'Ancienne release sans notes illisible.');
    $repository->authenticate('Bearer ' . $token);
    refused(fn() => $repository->authenticate('Bearer ' . str_repeat('0', 64)), 'Token invalide accepté.', 401);
    refused(fn() => (new Repository(array_replace($config, ['publish_token_sha256' => []]), $root . '/server/public'))->authenticate('Bearer ' . $token), 'Liste vide autorisant un token.', 401);
    refused(fn() => new Repository(array_replace($config, ['storage_path' => $root . '/server/public/storage']), $root . '/server/public'), 'Stockage public accepté.');
    $invalidHash = $manifest;
    $invalidHash['archive']['sha256'] = str_repeat('0', 64);
    refused(fn() => $repository->publish($zipPath, Protocol::sign($invalidHash, 'test-key', $secret), $notes), 'Empreinte invalide acceptée.', 422);
    check($repository->releases(null, null) === [], 'Publication incomplète visible.');
    check($repository->pending() === [] && $repository->approved() === [], 'Proposition en échec visible.');

    // Serveur PHP éphémère limité à 127.0.0.1 ; les exceptions HTTP restent testables.
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $server = proc_open([PHP_BINARY, '-S', $address, '-t', $root . '/server/public'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $root . '/http.log', 'a'], 2 => ['file', $root . '/http.log', 'a']], $pipes);
    $ready = false;
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $probe = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
        if ($probe !== false) {
            fclose($probe);
            $ready = true;
            break;
        }
        usleep(20000);
    }
    check($ready, 'Serveur local indisponible.');
    $url = 'http://' . $address . '/index.php';
    check(request($url . '?action=publish', str_repeat('0', 64), $zipPath, $envelope)[0] === 401, 'API sans bon token acceptée.');
    check(request($url . '?action=publish')[0] === 405, 'Publication GET acceptée.');
    check(request($url . '?action=publish', $token, $zipPath, json_encode($tampered))[0] === 422, 'API acceptant une fausse signature.');
    check(request($url . '?action=publish', $token, $zipPath, $envelope, null)[0] === 422, 'Notes manquantes acceptées.');
    check(request($url . '?action=publish', $token, $zipPath, $envelope, str_repeat('x', strlen($notes)))[0] === 422, 'Notes altérées acceptées.');

    $unknownKey = json_decode($envelope, true);
    $unknownKey['key_id'] = 'unknown';
    check(request($url . '?action=publish', $token, $zipPath, json_encode($unknownKey))[0] === 422, 'Clé distante inconnue acceptée.');
    $lock = fopen($root . '/storage/publication.lock', 'c');
    flock($lock, LOCK_EX);
    try {
        check(request($url . '?action=publish', $token, $zipPath, $envelope)[0] === 409, 'Publication concurrente acceptée.');
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    $oversize = array_replace($config, ['max_package_bytes' => 1]);
    refused(fn() => (new Repository($oversize, $root . '/server/public'))->publish($zipPath, $envelope, $notes), 'Limite de taille ignorée.', 413);

    // Le vrai client PHP signe et publie l'archive factice, sans commande Docker.
    $keyFile = $root . '/test.secret';
    file_put_contents($keyFile, base64_encode($secret));
    $clientConfig = $root . '/publication.php';
    $client = ['server_url' => $url, 'token' => $token, 'key_id' => 'test-key',
        'private_key_file' => $keyFile, 'allow_loopback_http' => true];
    file_put_contents($clientConfig, '<?php return ' . var_export($client, true) . ';');
    // Isoler le menu des vrais ZIP locaux et vérifier qu'aucune sélection n'est implicite.
    $menuRoot = $root . '/menu';
    mkdir($menuRoot . '/build/dist', 0700, true);
    mkdir($menuRoot . '/update_server/lib', 0700, true);
    copy($repositoryRoot . '/build/publish.php', $menuRoot . '/build/publish.php');
    copy($repositoryRoot . '/update_server/lib/Protocol.php', $menuRoot . '/update_server/lib/Protocol.php');
    $menuArguments = [PHP_BINARY, $menuRoot . '/build/publish.php', '--config=' . $clientConfig];
    check(command($menuArguments)[0] === 1, 'Menu sans ZIP accepté.');
    foreach ([$zipPath, $metadataPath, $notesPath] as $artifact) {
        copy($artifact, $menuRoot . '/build/dist/' . basename($artifact));
    }
    check(command($menuArguments, "0\n")[0] === 0, 'Annulation du menu refusée.');
    check(command($menuArguments)[0] === 0, 'Fin d’entrée du menu non traitée.');
    check(command($menuArguments, "99\n")[0] === 1, 'Sélection inexistante acceptée.');
    [$exit, $out] = command($menuArguments, "1\nn\n");
    check($exit === 0 && str_contains($out, $url) && str_contains($out, 'Envoi annulé.'), 'Confirmation de destination incorrecte.');
    // Choisir la configuration dans un dossier privé factice, sans utiliser celles de l'hôte.
    $configHome = $root . '/config-home';
    mkdir($configHome . '/tycms', 0700, true);
    mkdir($configHome . '/tycms-validation', 0700, true);
    copy($clientConfig, $configHome . '/tycms/publication.php');
    $alternative = array_replace($client, ['server_url' => 'https://validation.example.test/index.php']);
    file_put_contents($configHome . '/tycms-validation/publication.php', '<?php return ' . var_export($alternative, true) . ';');
    $environment = array_replace(getenv(), ['XDG_CONFIG_HOME' => $configHome, 'TYCMS_PUBLICATION_CONFIG' => '']);
    $selectConfig = [PHP_BINARY, $menuRoot . '/build/publish.php'];
    [$exit, $out] = command($selectConfig, "1\n\nn\n", $environment);
    check($exit === 0 && str_contains($out, '1. ' . $configHome . '/tycms/publication.php (par défaut)')
        && str_contains($out, 'Serveur : ' . $url), 'Entrée ne choisit pas la configuration standard en premier.');
    [$exit, $out] = command($selectConfig, "1\n2\nn\n", $environment);
    check($exit === 0 && str_contains($out, 'Serveur : https://validation.example.test/index.php'), 'Configuration alternative ignorée.');
    check(command($selectConfig, "1\n0\n", $environment)[0] === 0, 'Annulation du choix de configuration refusée.');
    check(command($selectConfig, "1\n", $environment)[0] === 0, 'Fin d’entrée du choix de configuration ignorée.');
    check(command($selectConfig, "1\n99\n", $environment)[0] === 1, 'Numéro de configuration inexistant accepté.');
    unlink($configHome . '/tycms/publication.php');
    [$exit, $out, $err] = command($selectConfig, "1\n\n", $environment);
    check($exit === 1 && str_contains($err, 'Configuration choisie absente'), 'Configuration absente remplacée implicitement.');
    $arguments = [PHP_BINARY, $repositoryRoot . '/build/publish.php', $zipPath, '--config=' . $clientConfig];
    // Un artefact manquant ou modifié doit être refusé avant tout envoi.
    $frozen = file_get_contents($metadataPath);
    unlink($metadataPath);
    check(command($arguments)[0] === 1, 'Publication sans métadonnées acceptée.');
    file_put_contents($metadataPath, $frozen);
    file_put_contents($notesPath, str_repeat('x', strlen($notes)));
    check(command($arguments)[0] === 1, 'Notes modifiées acceptées.');
    file_put_contents($notesPath, $notes);
    $wrongIdentity = $manifest;
    $wrongIdentity['archive']['sha256'] = str_repeat('0', 64);
    file_put_contents($metadataPath, json_encode($wrongIdentity));
    check(command($arguments)[0] === 1, 'ZIP ne correspondant pas aux métadonnées accepté.');
    file_put_contents($metadataPath, $frozen);
    [$exit, $out, $err] = command($arguments);
    check($exit === 0, 'Échec du vrai client : ' . $err);
    // Le client annonce une proposition en attente, jamais une distribution immédiate.
    check(str_contains($out, 'Proposition enregistrée : 1.0.1, en attente d’approbation')
        && str_contains($out, $manifest['archive']['sha256']), 'Message de proposition incorrect.');
    check(!str_contains($out, 'Version publiée'), 'Distribution annoncée avant approbation.');
    check(!str_contains($out . $err, $token), 'Token exposé par le client.');
    check(hash_file('sha256', $zipPath) === $manifest['archive']['sha256'], 'Le client a modifié le ZIP.');
    check($repository->channelOf('1.0.1') === Repository::CHANNEL_PENDING, 'Publication directe sans approbation.');
    check(request($url . '?action=manifest&version=1.0.1')[0] === 404, 'Proposition en attente exposée par le manifeste.');
    check(request($url . '?action=releases')[1] === '{"schema_version":1,"releases":[]}', 'Proposition en attente exposée par le catalogue.');
    check(request($url . '?action=package&version=1.0.1')[0] === 404, 'Proposition en attente téléchargeable.');

    // Approbation en bêta : immuable, sans retour possible vers l'attente ou une nouvelle approbation.
    check(count($repository->pending()) === 1 && $repository->pending()[0]['channel'] === Repository::CHANNEL_PENDING,
        'Proposition absente de la liste d’administration.');
    check($repository->approve('1.0.1', Repository::CHANNEL_BETA)['version'] === '1.0.1', 'Approbation bêta refusée.');
    refused(fn() => $repository->approve('1.0.1', Repository::CHANNEL_BETA), 'Double approbation acceptée.', 409);
    refused(fn() => $repository->discard('1.0.1'), 'Suppression d’une version approuvée acceptée.', 409);
    refused(fn() => $repository->approve('1.0.1', Repository::CHANNEL_PENDING), 'Retour en attente accepté.', 400);
    refused(fn() => $repository->approve('1.0.1', 'canal'), 'Canal inconnu accepté.', 400);
    check($repository->release('1.0.1', Repository::CHANNEL_BETA)['notes'] === $notes, 'Notes absentes après approbation.');

    // Promotion bêta → prod : définitive, avec la même identité signée.
    $approved = $repository->approve('1.0.1', Repository::CHANNEL_PROD);
    check($approved === $manifest && $repository->channelOf('1.0.1') === Repository::CHANNEL_PROD, 'Promotion en prod incohérente.');
    refused(fn() => $repository->approve('1.0.1', Repository::CHANNEL_BETA), 'Retour en bêta accepté.', 409);
    check(count($repository->releases(null, null)) === 1 && $repository->pending() === [], 'Catalogue public après promotion incorrect.');
    [$status, $body] = request($url . '?action=manifest&version=1.0.1');
    check($status === 200 && Protocol::verify($body, ['test-key' => $public]) === $manifest, 'Manifeste téléchargé incorrect.');
    check(str_contains($body, "\n    \"key_id\""), 'Manifeste publié non indenté.');
    [$exit, $out, $err] = command($menuArguments, "1\noui\n");
    check($exit === 1 && str_contains($err, '409') && !str_contains($out . $err, $token), 'Publication interactive ou confidentialité incorrecte.');
    [$status, $body] = request($url . '?action=notes&version=1.0.1');
    check($status === 200 && $body === $notes, 'Notes publiées incorrectes.');
    [$status, $body] = request($url . '?action=package&version=1.0.1');
    check($status === 200 && hash('sha256', $body) === $manifest['archive']['sha256'], 'Paquet téléchargé incorrect.');
    [$status, $body] = request($url . '?action=releases&source=1.0.0&php=8.4.1');
    check($status === 200 && count(json_decode($body, true)['releases']) === 1, 'Release compatible absente.');
    check(json_decode(request($url . '?action=releases&source=0.9.0&php=8.4.1')[1], true)['releases'] === [], 'Source non autorisée proposée.');
    check(json_decode(request($url . '?action=releases&source=1.0.0&php=8.6.0')[1], true)['releases'] === [], 'PHP incompatible proposé.');
    check(request($url . '?action=package&version=../1.0.1')[0] === 400, 'Traversée HTTP acceptée.');
    check(request($url . '?action=package&version=1.0.2')[0] === 404, 'Release inconnue accessible.');
    [$exit, $out, $err] = command($arguments);
    check($exit === 1 && str_contains($err, '409') && !str_contains($out . $err, $token), 'Doublon ou confidentialité incorrects.');
    check(request($url . '?action=publish', $token, $zipPath, $envelope)[0] === 409, 'Écrasement de release autorisé.');
    file_put_contents($root . '/server/public/redirect.php', '<?php header("Location: /trap.php", true, 302);');
    file_put_contents($root . '/server/public/trap.php', '<?php file_put_contents(' . var_export($root . '/redirect-followed', true) . ', "appel interdit");');
    $redirectClient = array_replace($client, ['server_url' => 'http://' . $address . '/redirect.php']);
    file_put_contents($clientConfig, '<?php return ' . var_export($redirectClient, true) . ';');
    check(command($arguments)[0] === 1 && !file_exists($root . '/redirect-followed'), 'Redirection de publication suivie.');
    symlink($root . '/storage/prod/v1_0_1', $root . '/storage/prod/v1_0_9');
    check(request($url . '?action=package&version=1.0.9')[0] === 404, 'Alias symbolique de release accepté.');
    // ---- Mini-UI d'administration : session, CSRF, approbation, promotion, refus. ----
    // Une seconde version est déposée en attente ; elle ne servira qu'à ces contrôles.
    $secondZip = $root . '/tycms-1_0_2.zip';
    copy($zipPath, $secondZip);
    // Fabriquer un ZIP valide pour 1.0.2 en réécrivant les noms et la version.
    $archive = new ZipArchive();
    $archive->open($secondZip, ZipArchive::OVERWRITE);
    foreach (['index.php', 'configuration.base.php', 'composer.json', 'composer.lock', 'vendor/autoload.php'] as $file) {
        $archive->addFromString('releases/v1_0_2/' . $file, '<?php // fixture');
    }
    $archive->addFromString('releases/v1_0_2/version.php', "<?php\ndefine('APP_VERSION', '1.0.2');\ndefine('APP_VERSION_STRING', '1_0_2');\n");
    foreach (['public/index.php', 'public/.htaccess', 'updater/index.php', 'var/releases-states.json'] as $file) {
        $archive->addFromString($file, 'fixture');
    }
    $archive->close();
    $secondNotes = "# Notes 1.0.2\n";
    file_put_contents($root . '/version.md', $secondNotes);
    $secondMetadata = ['version' => '1.0.2', 'version_string' => '1_0_2',
        'source_versions' => '*', 'php_min' => '8.4.1', 'php_max_exclusive' => '8.6.0'];
    ob_start();
    finishReleaseArtifacts($secondZip, $root . '/version.md', $secondMetadata, $root);
    ob_end_clean();
    $secondManifest = json_decode(file_get_contents($root . '/tycms-1_0_2.build.json'), true, 16, JSON_THROW_ON_ERROR);
    $secondEnvelope = Protocol::sign($secondManifest, 'test-key', $secret);
    $secondNotesFrozen = file_get_contents($root . '/tycms-1_0_2.md');
    check(request($url . '?action=publish', $token, $secondZip, $secondEnvelope, $secondNotesFrozen)[0] === 201,
        'Seconde proposition refusée.');
    $pendingDirectory = $root . '/storage/pending/v1_0_2';
    check(is_dir($pendingDirectory) && !is_dir($root . '/storage/prod/v1_0_2'), 'Proposition rangée hors de l’attente.');

    // Sans session, aucune page d'administration ni action n'est accordée.
    check(adminRequest($url . '?action=admin')[0] === 200, 'Page de connexion indisponible.');
    [$status] = adminRequest($url . '?action=approve', ['version' => '1.0.2', 'channel' => 'prod']);
    check($status === 200 && str_contains(adminRequest($url . '?action=admin')[1], 'Se connecter'),
        'Action sans session non rendue comme un refus.');
    check($repository->channelOf('1.0.2') === Repository::CHANNEL_PENDING, 'Action sans session acceptée.');

    // Identifiants faux refusés, puis connexion du compte configuré avec un cookie HttpOnly.
    [$status, $body] = adminRequest($url . '?action=login', ['user' => 'admin', 'password' => 'mauvais']);
    check($status === 200 && str_contains($body, 'Identifiants refusés'), 'Mot de passe faux accepté.');
    [$status, $body, $setCookie, $headers] = adminRequest($url . '?action=login',
        ['user' => 'admin', 'password' => $adminPassword]);
    check($status === 303 && is_string($setCookie) && str_starts_with($setCookie, Admin::COOKIE . '=')
        && stripos($headers, 'httponly') !== false, 'Connexion refusée ou cookie non protégé.');
    $session = '';
    if (is_string($setCookie) && str_starts_with($setCookie, Admin::COOKIE . '=')) {
        $session = substr($setCookie, strlen(Admin::COOKIE) + 1);
    }
    check(preg_match('/\A[a-f0-9]{64}\z/', $session) === 1, 'Connexion refusée pour le compte administrateur.');
    $csrf = Admin::csrf($session);
    [$status, $body] = adminRequest($url . '?action=admin', null, $session);
    check($status === 200 && str_contains($body, '1.0.2') && str_contains($body, 'En attente d’approbation (1)')
        && !str_contains($body, $session), 'Page d’administration incorrecte.');
    check(!str_contains($body, '<script>'), 'Notes ou données injectées comme HTML.');

    // Le CSRF dérivé de la session est exigé sur chaque mutation : une page de refus n'est pas un succès.
    [$status, $body] = adminRequest($url . '?action=approve', ['version' => '1.0.2', 'channel' => 'beta'], $session);
    check($status === 200 && str_contains($body, 'Jeton de formulaire invalide'), 'Mutation sans CSRF non refusée.');
    check($repository->channelOf('1.0.2') === Repository::CHANNEL_PENDING, 'Mutation sans CSRF appliquée.');
    [$status, $body] = adminRequest($url . '?action=approve',
        ['version' => '1.0.2', 'channel' => 'beta', 'csrf' => str_repeat('0', 64)], $session);
    check($status === 200 && str_contains($body, 'Jeton de formulaire invalide')
        && $repository->channelOf('1.0.2') === Repository::CHANNEL_PENDING, 'CSRF faux accepté.');
    [$status, $body] = adminRequest($url . '?action=approve&version=1.0.2&channel=beta', null, $session);
    check($status === 200 && str_contains($body, 'Méthode refusée'), 'Mutation en GET non refusée.');

    // Un cookie inventé ne remplace pas une session accordée.
    check(str_contains(adminRequest($url . '?action=admin', null, str_repeat('a', 64))[1], 'Se connecter'),
        'Session inventée acceptée.');

    // Approbation bêta par le formulaire, puis promotion en prod, puis refus impossible.
    [$status, , $setCookie, $headers] = adminRequest($url . '?action=approve',
        ['version' => '1.0.2', 'channel' => 'beta', 'csrf' => $csrf], $session);
    check($status === 303 && $repository->channelOf('1.0.2') === Repository::CHANNEL_BETA, 'Approbation bêta par formulaire refusée.');
    check(adminRequest($url . '?action=discard', ['version' => '1.0.2', 'csrf' => $csrf], $session)[0] === 200
        && is_dir($root . '/storage/beta/v1_0_2'), 'Suppression d’une version bêta acceptée.');
    check(adminRequest($url . '?action=promote', ['version' => '1.0.2', 'csrf' => $csrf], $session)[0] === 303
        && $repository->channelOf('1.0.2') === Repository::CHANNEL_PROD, 'Promotion par formulaire refusée.');
    [$status, $body] = adminRequest($url . '?action=promote', ['version' => '1.0.2', 'csrf' => $csrf], $session);
    check($status === 200 && str_contains($body, 'déjà dans ce canal')
        && $repository->channelOf('1.0.2') === Repository::CHANNEL_PROD, 'Double promotion non refusée.');

    // « Refuser » supprime physiquement une proposition et libère son numéro pour un envoi futur.
    [$status, $body] = adminRequest($url . '?action=discard', ['version' => '1.0.1', 'csrf' => $csrf], $session);
    check($status === 200 && str_contains($body, 'Seule une version en attente peut être refusée')
        && $repository->channelOf('1.0.1') === Repository::CHANNEL_PROD, 'Version en prod refusée.');
    $thirdZip = $root . '/tycms-1_0_3.zip';
    copy($secondZip, $thirdZip);
    $archive = new ZipArchive();
    $archive->open($thirdZip, ZipArchive::OVERWRITE);
    foreach (['index.php', 'configuration.base.php', 'composer.json', 'composer.lock', 'vendor/autoload.php'] as $file) {
        $archive->addFromString('releases/v1_0_3/' . $file, '<?php // fixture');
    }
    $archive->addFromString('releases/v1_0_3/version.php', "<?php\ndefine('APP_VERSION', '1.0.3');\ndefine('APP_VERSION_STRING', '1_0_3');\n");
    foreach (['public/index.php', 'public/.htaccess', 'updater/index.php', 'var/releases-states.json'] as $file) {
        $archive->addFromString($file, 'fixture');
    }
    $archive->close();
    file_put_contents($root . '/version.md', $secondNotes);
    ob_start();
    finishReleaseArtifacts($thirdZip, $root . '/version.md',
        ['version' => '1.0.3', 'version_string' => '1_0_3', 'source_versions' => '*',
            'php_min' => '8.4.1', 'php_max_exclusive' => '8.6.0'], $root);
    ob_end_clean();
    $third = json_decode(file_get_contents($root . '/tycms-1_0_3.build.json'), true, 16, JSON_THROW_ON_ERROR);
    $thirdEnvelope = Protocol::sign($third, 'test-key', $secret);
    check(request($url . '?action=publish', $token, $thirdZip, $thirdEnvelope, $secondNotesFrozen)[0] === 201, 'Troisième proposition refusée.');
    check(adminRequest($url . '?action=discard', ['version' => '1.0.3', 'csrf' => $csrf], $session)[0] === 303,
        'Refus par formulaire refusé.');
    check($repository->channelOf('1.0.3') === null && !file_exists($root . '/storage/pending/v1_0_3/package.zip'),
        'Refus sans suppression physique.');
    // Un canal inconnu ne doit jamais devenir un nom de dossier (« ../.. » par exemple).
    refused(fn() => $repository->approve('1.0.2', '../../evil'), 'Canal arbitraire accepté.', 400);
    check(!file_exists($root . '/evil') && !file_exists(dirname($root) . '/evil'), 'Nom de canal utilisé comme chemin.');
    [$status, $body] = adminRequest($url . '?action=discard', ['version' => '1.0.3', 'csrf' => $csrf], $session);
    check($status === 200 && str_contains($body, 'Version introuvable'), 'Refus d’une version absente non signalé.');

    // Déconnexion : la session accordée devient inutilisable.
    check(adminRequest($url . '?action=logout', null, $session)[0] === 303, 'Déconnexion refusée.');
    check(str_contains(adminRequest($url . '?action=admin', null, $session)[1], 'Se connecter'), 'Session valide après déconnexion.');

    // Compte désactivé : contrôlé sans HTTP, sur une instance dédiée. Réécrire
    // configuration.php pendant que le serveur de test tourne rendrait le résultat
    // dépendant du moment où chaque requête relit le fichier.
    $sessions = $root . '/sessions-check';
    mkdir($sessions);
    $disabled = array_replace($config, ['admin_password_hash' => '']);
    $disabledAdmin = new Admin($disabled, $sessions);
    refused(fn() => $disabledAdmin->login('admin', $adminPassword), 'Connexion acceptée sans compte configuré.');
    // Une session accordée reste fermée dès que le compte disparaît de la configuration.
    $activeAdmin = new Admin($config, $sessions);
    check($activeAdmin->login('admin', $adminPassword), 'Connexion refusée avec un compte configuré.');
    refused(fn() => (new Admin($disabled, $sessions))->require(), 'Session acceptée sans compte configuré.', 401);
    check(!Admin::configured(['admin_user' => 'admin', 'admin_password_hash' => 'motdepasse']), 'Empreinte non Argon2id acceptée.');
    check(!Admin::configured(['admin_user' => '', 'admin_password_hash' => password_hash('x', PASSWORD_ARGON2ID)]),
        'Compte sans nom accepté.');

    // Après ces essais, le catalogue public ne contient que les versions approuvées en prod.
    check(count($repository->releases(null, null)) === 2, 'Catalogue public incohérent après approbation.');

    // ---- Schéma 2 et canaux : la bêta n'est servie qu'à un client qui la demande. ----
    // Le build produit désormais un manifeste v2 de prod ; la fixture historique reste v1 ci-dessous.
    check(($manifest['schema_version'] ?? null) === 2 && Protocol::channel($manifest) === Protocol::CHANNEL_PROD,
        'Le build n’a pas produit un manifeste v2 de prod.');
    $betaManifest = array_replace($manifest, ['schema_version' => 2, 'channel' => 'beta']);
    Protocol::validate($betaManifest);
    check(Protocol::channel($betaManifest) === 'beta', 'Canal bêta ignoré.');
    foreach ([null, '', 'canal', 'PROD', ['prod']] as $invalid) {
        refused(fn() => Protocol::validate(array_replace($manifest, ['schema_version' => 2, 'channel' => $invalid])),
            'Canal v2 invalide accepté.');
    }
    refused(fn() => Protocol::validate(array_replace($manifest, ['schema_version' => 3])), 'Schéma futur accepté.');
    refused(fn() => Protocol::validate(array_replace($manifest, ['schema_version' => '1'])), 'Schéma texte accepté.');
    // La v1 reste valide sans canal : les releases déjà signées restent lisibles.
    $legacyManifest = ['schema_version' => 1] + array_diff_key($manifest, ['schema_version' => true, 'channel' => true]);
    Protocol::validate($legacyManifest);
    check(Protocol::channel($legacyManifest) === Protocol::CHANNEL_PROD, 'Manifeste v1 sans canal non traité comme prod.');
    check(!array_key_exists('channel', $legacyManifest), 'La validation a modifié le manifeste reçu.');

    // Une release bêta coexiste avec les releases de prod du catalogue.
    $betaZip = $root . '/tycms-1_0_4.zip';
    copy($zipPath, $betaZip);
    $archive = new ZipArchive();
    $archive->open($betaZip, ZipArchive::OVERWRITE);
    foreach (['index.php', 'configuration.base.php', 'composer.json', 'composer.lock', 'vendor/autoload.php'] as $file) {
        $archive->addFromString('releases/v1_0_4/' . $file, '<?php // fixture');
    }
    $archive->addFromString('releases/v1_0_4/version.php', "<?php\ndefine('APP_VERSION', '1.0.4');\ndefine('APP_VERSION_STRING', '1_0_4');\n");
    foreach (['public/index.php', 'public/.htaccess', 'updater/index.php', 'var/releases-states.json'] as $file) {
        $archive->addFromString($file, 'fixture');
    }
    $archive->close();
    file_put_contents($root . '/version.md', $secondNotes);
    ob_start();
    finishReleaseArtifacts($betaZip, $root . '/version.md',
        ['version' => '1.0.4', 'version_string' => '1_0_4', 'source_versions' => '*',
            'php_min' => '8.4.1', 'php_max_exclusive' => '8.6.0'], $root);
    ob_end_clean();
    $betaBuilt = json_decode(file_get_contents($root . '/tycms-1_0_4.build.json'), true, 16, JSON_THROW_ON_ERROR);
    check(($betaBuilt['schema_version'] ?? null) === 2 && ($betaBuilt['channel'] ?? null) === 'prod',
        'Le build ne produit pas un manifeste v2 de prod.');
    $betaEnvelope = Protocol::sign(array_replace($betaBuilt, ['channel' => 'beta']), 'test-key', $secret);
    // Les notes figées de 1.0.4 sont produites avec ce build : relire le Markdown correspondant.
    $betaNotes = file_get_contents($root . '/tycms-1_0_4.md');
    [$betaStatus, $betaBody] = request($url . '?action=publish', $token, $betaZip, $betaEnvelope, $betaNotes);
    check($betaStatus === 201, 'Proposition bêta refusée (HTTP ' . $betaStatus . ' : ' . $betaBody . ').');
    check($repository->approve('1.0.4', Repository::CHANNEL_BETA)['version'] === '1.0.4', 'Approbation bêta refusée.');
    // Le catalogue renvoie les enveloppes déjà décodées : les vérifier avant d'en lire la version.
    $versions = static function (array $envelopes) use ($public): array {
        return array_map(static fn(array $envelope): string
            => Protocol::verify(json_encode($envelope, JSON_THROW_ON_ERROR), ['test-key' => $public])['version'], $envelopes);
    };
    $prodOnly = $versions(json_decode(request($url . '?action=releases')[1], true)['releases']);
    check(count($prodOnly) === 2 && !in_array('1.0.4', $prodOnly, true),
        'Release bêta servie à un client qui ne la demande pas.');
    $withBeta = $versions(json_decode(request($url . '?action=releases&channel=beta')[1], true)['releases']);
    check(count($withBeta) === 3 && in_array('1.0.4', $withBeta, true), 'Release bêta absente du canal bêta.');
    check(count(json_decode(request($url . '?action=releases&channel=prod')[1], true)['releases']) === 2,
        'Canal prod explicite différent du défaut.');
    check(request($url . '?action=releases&channel=canal')[0] === 400, 'Canal inconnu accepté par la route.');
    check(request($url . '?action=releases&channel[]=beta')[0] === 400, 'Canal non scalaire accepté.');
    check(json_decode(request($url . '?action=releases&source=1.0.0&php=8.4.1&channel=beta')[1], true)['releases'] !== [],
        'Filtres source/PHP ignorés avec la bêta.');

    // Une version bêta se lit avec `channel=beta`, et reste introuvable sans canal.
    check(request($url . '?action=manifest&version=1.0.4')[0] === 404, 'Manifeste bêta servi sans canal.');
    check(request($url . '?action=package&version=1.0.4')[0] === 404, 'Paquet bêta servi sans canal.');
    check(request($url . '?action=notes&version=1.0.4')[0] === 404, 'Notes bêta servies sans canal.');
    [$betaStatus, $betaManifest] = request($url . '?action=manifest&version=1.0.4&channel=beta');
    check($betaStatus === 200 && Protocol::channel(Protocol::verify($betaManifest, ['test-key' => $public])) === 'beta',
        'Manifeste bêta absent du canal bêta.');
    [$betaStatus, $betaPackage] = request($url . '?action=package&version=1.0.4&channel=beta');
    check($betaStatus === 200 && hash('sha256', $betaPackage) === $betaBuilt['archive']['sha256'],
        'Paquet bêta absent du canal bêta.');
    [$betaStatus, $betaNotes] = request($url . '?action=notes&version=1.0.4&channel=beta');
    check($betaStatus === 200 && $betaNotes === $betaNotes, 'Notes bêta absentes du canal bêta.');
    // Une version de prod reste lisible sur les deux canaux.
    check(request($url . '?action=manifest&version=1.0.1&channel=prod')[0] === 200, 'Release prod refusée sur son canal.');
    check(request($url . '?action=manifest&version=1.0.1&channel=canal')[0] === 400, 'Canal inconnu accepté sur un manifeste.');

    // Révocation et HTTPS : vérifiés sans réécrire configuration.php pendant que le
    // serveur tourne, pour ne pas dépendre du moment où chaque requête relit le fichier.
    $revoked = array_replace($config, ['publish_token_sha256' => []]);
    $revokedRepository = new Repository($revoked, $root . '/server/public');
    refused(fn() => $revokedRepository->authenticate('Bearer ' . $token), 'Token révoqué accepté.', 401);

    $client['allow_loopback_http'] = false;
    file_put_contents($clientConfig, '<?php return ' . var_export($client, true) . ';');
    check(command($arguments)[0] === 1, 'HTTP accepté sans exception locale explicite.');

    // Arguments de publication refusés : aucun envoi implicite, aucune configuration du dépôt.
    foreach (['--from=1.0.0', '--initial-install-only'] as $obsolete) {
        check(command([PHP_BINARY, $repositoryRoot . '/build/publish.php', $zipPath, $obsolete,
            '--config=' . $clientConfig])[0] === 1, 'Ancien paramètre accepté.');
    }
    check(command([PHP_BINARY, $repositoryRoot . '/build/publish.php', $zipPath,
        '--config=' . $repositoryRoot . '/build/publication.sample.php'])[0] === 1, 'Configuration privée dans le dépôt acceptée.');

    // Création des clés sans affichage du secret ni écrasement d'un fichier existant.
    $generated = $root . '/generated.secret';
    [$exit, $out] = command([PHP_BINARY, $repositoryRoot . '/build/signing-key.php', $generated]);
    check($exit === 0 && is_file($generated . '.pub'), 'Génération de clé impossible.');
    check(!str_contains($out, trim(file_get_contents($generated))), 'Clé privée affichée.');
    check((fileperms($generated) & 0777) === 0600, 'Permissions de clé trop larges.');
    check(command([PHP_BINARY, $repositoryRoot . '/build/signing-key.php', $generated])[0] === 1, 'Clé existante écrasée.');
    echo "OK : signatures, ZIP, authentification, publication PHP locale, HTTP, compatibilités, téléchargement, "
        . "refus d’écrasement et approbation d’administration.\n";
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    removeFixture($root);
}
