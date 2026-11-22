<?php

declare(strict_types=1);

use Tycms\Distribution\Protocol;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/update_server/lib/Protocol.php';

// Ce programme utilise PHP local exclusivement ; aucune commande de build n'est lancée.
ini_set('display_errors', '0');
umask(0077);
$snapshot = null;
try {
    $options = [];
    $archive = null;
    foreach (array_slice($argv, 1) as $argument) {
        if (str_starts_with($argument, '--config=') && !isset($options['config'])) {
            $options['config'] = substr($argument, 9);
        } elseif ($archive === null && !str_starts_with($argument, '-')) {
            $archive = $argument;
        } else {
            throw new RuntimeException('Argument inconnu ou dupliqué.');
        }
    }
    $interactive = $archive === null;
    if ($interactive) {
        // Proposer seulement les ZIP du dossier de distribution, sans choix implicite.
        $archives = array_values(array_filter(glob(__DIR__ . '/dist/tycms-*.zip') ?: [],
            static fn(string $path): bool => is_file($path) && !is_link($path)
                && preg_match('/\Atycms-((?:0|[1-9][0-9]*)_(?:0|[1-9][0-9]*)_(?:0|[1-9][0-9]*))\.zip\z/', basename($path)) === 1));
        usort($archives, static fn(string $left, string $right): int => version_compare(
            str_replace('_', '.', substr(basename($right), 6, -4)),
            str_replace('_', '.', substr(basename($left), 6, -4))));
        if ($archives === []) {
            throw new RuntimeException('Aucun ZIP disponible dans build/dist/ ; lancer le build avant de publier.');
        }

        echo "ZIP disponibles dans build/dist/ :\n";
        foreach ($archives as $index => $path) {
            echo '  ' . ($index + 1) . '. ' . basename($path) . "\n";
        }
        echo "  0. Annuler\nNuméro du ZIP à proposer : ";
        $input = fgets(STDIN);
        $choice = $input === false ? '0' : trim($input);
        if ($choice === '0') {
            echo "Envoi annulé.\n";
            exit(0);
        }
        if (preg_match('/\A[1-9][0-9]*\z/', $choice) !== 1 || !isset($archives[(int) $choice - 1])) {
            throw new RuntimeException('Sélection invalide ; relancer la commande et choisir un numéro affiché.');
        }
        $archive = $archives[(int) $choice - 1];
    }
    foreach (['curl', 'sodium', 'zip'] as $extension) {
        if (!extension_loaded($extension)) {
            throw new RuntimeException('Extension PHP locale requise : ' . $extension);
        }
    }
    if (!preg_match('/\Atycms-([0-9]+)_([0-9]+)_([0-9]+)\.zip\z/', basename($archive), $parts)) {
        throw new RuntimeException('Le nom du ZIP doit être tycms-X_Y_Z.zip.');
    }
    $version = Protocol::version($parts[1] . '.' . $parts[2] . '.' . $parts[3]);

    // Lire uniquement les métadonnées capturées avec ce ZIP, jamais version.json du dépôt.
    $metadataPath = substr($archive, 0, -4) . '.build.json';
    $json = @file_get_contents($metadataPath, false, null, 0, Protocol::MAX_MANIFEST_BYTES + 1);
    if (!is_string($json) || strlen($json) > Protocol::MAX_MANIFEST_BYTES) {
        throw new RuntimeException('Métadonnées .build.json du ZIP absentes ou trop volumineuses ; refaire le build.');
    }
    $manifest = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($manifest)) {
        throw new RuntimeException('Métadonnées de build invalides.');
    }
    Protocol::validate($manifest);
    if ($manifest['version'] !== $version || $manifest['archive']['filename'] !== basename($archive)
        || !isset($manifest['release_notes'])) {
        throw new RuntimeException('Métadonnées incompatibles avec ce ZIP ou notes absentes.');
    }
    $notesPath = dirname($archive) . '/' . $manifest['release_notes']['filename'];
    $notes = @file_get_contents($notesPath, false, null, 0, Protocol::MAX_NOTES_BYTES + 1);
    if (!is_string($notes) || strlen($notes) !== $manifest['release_notes']['bytes']
        || !hash_equals($manifest['release_notes']['sha256'], hash('sha256', $notes))) {
        throw new RuntimeException('Notes de version absentes ou différentes de celles du build.');
    }

    $home = getenv('XDG_CONFIG_HOME') ?: ((getenv('HOME') ?: '') . '/.config');
    $configPath = $options['config'] ?? (getenv('TYCMS_PUBLICATION_CONFIG') ?: null);
    if ($configPath === null) {
        $configPath = $home . '/tycms/publication.php';
        if ($interactive) {
            // Lister les fichiers sans les exécuter ; seule la configuration choisie sera chargée.
            $configurations = array_values(array_filter(glob($home . '/tycms*/publication.php') ?: [],
                static fn(string $path): bool => is_file($path) && $path !== $configPath));
            sort($configurations, SORT_STRING);
            array_unshift($configurations, $configPath);

            echo "\nConfigurations de publication :\n";
            foreach ($configurations as $index => $path) {
                echo '  ' . ($index + 1) . '. ' . $path
                    . ($index === 0 ? ' (par défaut)' : '')
                    . (!is_file($path) ? ' (absent)' : '') . "\n";
            }
            echo "  0. Annuler\nNuméro de la configuration [1] : ";
            $input = fgets(STDIN);
            if ($input === false || trim($input) === '0') {
                echo "Envoi annulé.\n";
                exit(0);
            }
            $choice = trim($input) === '' ? '1' : trim($input);
            if (preg_match('/\A[1-9][0-9]*\z/', $choice) !== 1 || !isset($configurations[(int) $choice - 1])) {
                throw new RuntimeException('Sélection de configuration invalide.');
            }
            $configPath = $configurations[(int) $choice - 1];
            if (!is_file($configPath)) {
                throw new RuntimeException('Configuration choisie absente ; créer ce fichier ou sélectionner une autre configuration.');
            }
        }
    }
    $repository = realpath(dirname(__DIR__));
    $outside = static function (string $path) use ($repository): string {
        $real = realpath($path);
        if ($real === false || !is_file($real) || $real === $repository || str_starts_with($real, $repository . '/')) {
            throw new RuntimeException('La configuration et la clé privée doivent être des fichiers hors du dépôt.');
        }
        return $real;
    };
    try {
        $config = require $outside($configPath);
    } catch (Throwable) {
        throw new RuntimeException('Configuration privée de publication absente ou invalide.');
    }
    if (!is_array($config) || !is_string($config['token'] ?? null)
        || preg_match('/\A[a-f0-9]{64}\z/', $config['token']) !== 1) {
        throw new RuntimeException('Token de publication non configuré.');
    }
    $url = $config['server_url'] ?? null;
    $parts = is_string($url) ? parse_url($url) : false;
    $local = is_array($parts) && ($config['allow_loopback_http'] ?? false) === true
        && ($parts['scheme'] ?? '') === 'http' && in_array($parts['host'] ?? '', ['127.0.0.1', '[::1]'], true);
    if (!is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['query']) || isset($parts['fragment']) || preg_match('/[\x00-\x20\x7f]/', $url)
        || (($parts['scheme'] ?? '') !== 'https' && !$local)) {
        throw new RuntimeException('URL HTTPS du serveur requise, sans identifiants, query ni fragment.');
    }
    $keyId = Protocol::keyId($config['key_id'] ?? null);
    if ($interactive) {
        // Confirmer la cible avant de lire la clé privée et d'envoyer le paquet.
        echo "\nVersion : $version\nServeur : $url\nClé de signature : $keyId\n";
        echo "Confirmer l’envoi de la proposition ? [o/N] : ";
        $answer = fgets(STDIN);
        if ($answer === false || !in_array(strtolower(trim($answer)), ['o', 'oui'], true)) {
            echo "Envoi annulé.\n";
            exit(0);
        }
    }
    if (!is_string($config['private_key_file'] ?? null)) {
        throw new RuntimeException('Chemin de clé privée requis.');
    }
    $secretFile = $outside($config['private_key_file']);
    if (filesize($secretFile) > 1024) {
        throw new RuntimeException('Clé privée invalide.');
    }
    $secret = Protocol::decodeKey(trim(file_get_contents($secretFile)), SODIUM_CRYPTO_SIGN_SECRETKEYBYTES);

    // Signer et envoyer une même copie figée, même si le ZIP d'origine change entre-temps.
    $source = @fopen($archive, 'rb');
    $snapshot = tmpfile();
    if ($source === false || $snapshot === false) {
        throw new RuntimeException('ZIP ou dossier temporaire inaccessible.');
    }
    try {
        $bytes = stream_copy_to_stream($source, $snapshot, 134217729);
    } finally {
        fclose($source);
    }
    if (!is_int($bytes) || $bytes <= 0 || $bytes > 134217728) {
        throw new RuntimeException('Taille de ZIP invalide (maximum 128 Mio).');
    }
    fflush($snapshot);
    $snapshotPath = stream_get_meta_data($snapshot)['uri'];
    Protocol::inspectArchive($snapshotPath, $version);
    if ($bytes !== $manifest['archive']['bytes']
        || !hash_equals($manifest['archive']['sha256'], hash_file('sha256', $snapshotPath))) {
        throw new RuntimeException('Le ZIP diffère de celui décrit dans les métadonnées du build.');
    }
    $envelope = Protocol::sign($manifest, $keyId, $secret);
    $public = sodium_crypto_sign_publickey_from_secretkey($secret);
    sodium_memzero($secret);
    Protocol::verify($envelope, [$keyId => base64_encode($public)]);
    $timeout = $config['timeout_seconds'] ?? 300;
    if (!is_int($timeout) || $timeout < 1 || $timeout > 3600) {
        throw new RuntimeException('Délai de publication invalide.');
    }

    // Pas de redirection : le token ne doit jamais être transmis à une autre destination.
    $response = '';
    $curl = curl_init($url . '?action=publish');
    if (!curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => ['manifest' => $envelope, 'notes' => $notes, 'package' => new CURLFile($snapshotPath, 'application/zip', basename($archive))],
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $config['token'], 'Expect:'],
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => $timeout,
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$response): int {
            if (strlen($response) + strlen($chunk) > 65536) {
                return 0;
            }
            $response .= $chunk;
            return strlen($chunk);
        },
    ])) {
        throw new RuntimeException('Configuration sécurisée de cURL impossible.');
    }
    if ($local) {
        curl_setopt($curl, CURLOPT_PROXY, '');
    }
    echo "Envoi de la proposition $version depuis un ZIP existant…\n";
    $sent = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    if ($sent === false) {
        throw new RuntimeException('Échange réseau interrompu : le résultat peut être incertain. Consulter le manifeste distant avant de réessayer.');
    }
    if ($status !== 201) {
        throw new RuntimeException('Publication refusée (HTTP ' . $status . '). Aucun remplacement de version existante n’est autorisé.');
    }
    $receipt = json_decode($response, true, 16, JSON_THROW_ON_ERROR);
    if (($receipt['published'] ?? null) !== $version || ($receipt['sha256'] ?? null) !== $manifest['archive']['sha256']) {
        throw new RuntimeException('Accusé de publication incohérent ; vérifier le manifeste distant.');
    }
    // L'envoi ne rend rien public : le serveur attend une approbation dans son administration.
    echo "Proposition enregistrée : $version, en attente d’approbation.\n"
        . "SHA-256 : " . $manifest['archive']['sha256'] . "\n"
        . "Approuver cette version dans l’interface d’administration du serveur avant qu’elle soit distribuée.\n";
} catch (Throwable $error) {
    // Ne jamais afficher le contenu de la configuration, du token ou d'une réponse distante.
    fwrite(STDERR, 'Publication interrompue : ' . ($error instanceof RuntimeException ? $error->getMessage() : 'Données ou configuration invalides.') . "\n");
    exit(1);
} finally {
    if (is_resource($snapshot)) {
        fclose($snapshot);
    }
}
