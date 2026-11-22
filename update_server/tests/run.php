<?php

declare(strict_types=1);

use Tycms\Distribution\HttpError;
use Tycms\Distribution\Protocol;
use Tycms\Distribution\Repository;

require_once dirname(__DIR__) . '/lib/Protocol.php';
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
    foreach (['public/index.php', 'lib/Protocol.php', 'lib/Repository.php'] as $file) {
        copy(dirname(__DIR__) . '/' . $file, $root . '/server/' . $file);
    }
    $config = ['storage_path' => $root . '/storage', 'publish_token_sha256' => [hash('sha256', $token)],
        'trusted_public_keys' => ['test-key' => $public], 'max_package_bytes' => 1000000, 'allow_loopback_http' => true];
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
    check($legacyRepository->release('1.0.1')['notes'] === null, 'Ancienne release sans notes illisible.');
    $repository->authenticate('Bearer ' . $token);
    refused(fn() => $repository->authenticate('Bearer ' . str_repeat('0', 64)), 'Token invalide accepté.', 401);
    refused(fn() => (new Repository(array_replace($config, ['publish_token_sha256' => []]), $root . '/server/public'))->authenticate('Bearer ' . $token), 'Liste vide autorisant un token.', 401);
    refused(fn() => new Repository(array_replace($config, ['storage_path' => $root . '/server/public/storage']), $root . '/server/public'), 'Stockage public accepté.');
    $invalidHash = $manifest;
    $invalidHash['archive']['sha256'] = str_repeat('0', 64);
    refused(fn() => $repository->publish($zipPath, Protocol::sign($invalidHash, 'test-key', $secret), $notes), 'Empreinte invalide acceptée.', 422);
    check($repository->releases(null, null) === [], 'Publication incomplète visible.');

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
    check($exit === 0 && str_contains($out, $url) && str_contains($out, 'Publication annulée.'), 'Confirmation de destination incorrecte.');
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
    check(!str_contains($out . $err, $token), 'Token exposé par le client.');
    check(hash_file('sha256', $zipPath) === $manifest['archive']['sha256'], 'Le client a modifié le ZIP.');
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
    symlink($root . '/storage/releases/v1_0_1', $root . '/storage/releases/v1_0_9');
    check(request($url . '?action=package&version=1.0.9')[0] === 404, 'Alias symbolique de release accepté.');
    $revoked = array_replace($config, ['publish_token_sha256' => []]);
    file_put_contents($root . '/server/configuration.php', '<?php return ' . var_export($revoked, true) . ';');
    check(request($url . '?action=publish', $token, $zipPath, $envelope)[0] === 401, 'Token révoqué accepté.');
    $noHttp = array_replace($config, ['allow_loopback_http' => false]);
    file_put_contents($root . '/server/configuration.php', '<?php return ' . var_export($noHttp, true) . ';');
    [$status, $body] = request($url);
    check($status === 400 && !str_contains($body, $root) && !str_contains($body, $token), 'HTTPS non imposé ou données privées exposées.');
    $client['allow_loopback_http'] = false;
    file_put_contents($clientConfig, '<?php return ' . var_export($client, true) . ';');
    check(command($arguments)[0] === 1, 'HTTP accepté sans exception locale explicite.');
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
    echo "OK : signatures, ZIP, authentification, publication PHP locale, HTTP, compatibilités, téléchargement et refus d’écrasement.\n";
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    removeFixture($root);
}
