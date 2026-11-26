<?php

declare(strict_types=1);

/**
 * Client de publication TyCatalog.
 *
 * Sans argument, propose un menu. Les valeurs par défaut viennent de
 * build_extensions/tycatalog-publish.local.php, ignoré par Git. Le jeton n'est
 * jamais affiché.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', [
    'url:', 'catalog:', 'token::', 'zip::', 'notes::', 'signature::',
    'display-name::', 'description::', 'publish', 'archive-url::', 'notes-url::',
    'publish-version:', 'withdraw:', 'reactivate:', 'promote:', 'channel:', 'interactive', 'help',
]);

if (isset($options['help'])) {
    fwrite(STDOUT, <<<TXT
Usage interactive :
  php build_extensions/tycatalog-publish.php

Usage direct :
  php build_extensions/tycatalog-publish.php --url=https://exemple.fr --catalog=1 \
    --zip=plg_lms-0_1_0.zip --notes=plg_lms-0_1_0.md \
    --signature=plg_lms-0_1_0.extension-signature.json [--publish]

  php build_extensions/tycatalog-publish.php --url=… --catalog=1 \
    --archive-url=https://…/paquet.zip --notes-url=https://…/notes.md \
    --signature=…extension-signature.json [--publish]

  php build_extensions/tycatalog-publish.php --url=… --catalog=1 --publish-version=12

Le canal de diffusion se choisit avec --channel=release (défaut) ou beta :
  php build_extensions/tycatalog-publish.php --url=… --catalog=1 \
    --zip=… --notes=… --signature=… [--publish] [--channel=beta]

Actions directes sur une version existante :
  --publish-version=12  --withdraw=12  --reactivate=12  --promote=12

Configuration locale : build_extensions/tycatalog-publish.local.php
Jeton : TYCMS_TYCATALOG_TOKEN, --token, ou clé « token » de la configuration locale.
TXT);
    exit(0);
}

function fail(string $message): never
{
    fwrite(STDERR, 'Erreur : ' . $message . "\n");
    exit(1);
}

/** @return array<string, mixed> */
function loadConfig(): array
{
    $path = configPath();
    if (!is_file($path)) {
        return [];
    }
    if (is_link($path) || !is_readable($path)) {
        fail('Configuration de publication illisible : ' . $path);
    }
    $config = require $path;
    if (!is_array($config)) {
        fail('Configuration de publication invalide : ' . $path);
    }
    if (trim((string) ($config['token'] ?? '')) !== ''
        && DIRECTORY_SEPARATOR === '/' && (fileperms($path) & 0077) !== 0) {
        fail('La configuration locale contient un jeton ; appliquer chmod 600 à ' . $path . '.');
    }

    return $config;
}

function configPath(): string
{
    return getenv('TYCMS_TYCATALOG_PUBLISH_CONFIG') ?: __DIR__ . '/tycatalog-publish.local.php';
}

function prompt(string $label, string $default = ''): string
{
    fwrite(STDOUT, $label . ($default !== '' ? ' [' . $default . ']' : '') . ' : ');
    $value = fgets(STDIN);
    if ($value === false) {
        exit(0);
    }
    $value = trim($value);

    return $value === '' ? $default : $value;
}

function promptRequired(string $label, string $default = ''): string
{
    do {
        $value = prompt($label, $default);
    } while ($value === '');

    return $value;
}

function confirm(string $question): bool
{
    fwrite(STDOUT, $question . ' [o/N] : ');
    $answer = fgets(STDIN);

    return $answer !== false && in_array(strtolower(trim($answer)), ['o', 'oui', 'y', 'yes'], true);
}

function promptSecret(string $label): string
{
    fwrite(STDOUT, $label . ' : ');
    $isPosix = DIRECTORY_SEPARATOR === '/';
    if ($isPosix) {
        @shell_exec('stty -echo');
    }
    $value = fgets(STDIN);
    if ($isPosix) {
        @shell_exec('stty echo');
        fwrite(STDOUT, "\n");
    }

    return trim((string) $value);
}

function validToken(string $token): bool
{
    return preg_match('/\Atycat_[a-f0-9]{64}\z/', $token) === 1;
}

function saveConfigToken(array $config, string $token): void
{
    $path = configPath();
    if (is_link($path)) {
        fail('La configuration de publication ne peut pas être un lien symbolique : ' . $path);
    }

    $config['token'] = $token;
    $contents = "<?php\n\nreturn " . var_export($config, true) . ";\n";
    if (file_put_contents($path, $contents, LOCK_EX) === false) {
        fail('Impossible d’enregistrer le jeton dans ' . $path . '.');
    }
    @chmod($path, 0600);
}

/** @return array{token:string,source:string} */
function resolveToken(array $config, array $options, bool $interactive): array
{
    $env = trim((string) getenv('TYCMS_TYCATALOG_TOKEN'));
    if ($env !== '') {
        if (!validToken($env)) {
            fail('Jeton TYCMS_TYCATALOG_TOKEN invalide.');
        }
        return ['token' => $env, 'source' => 'variable TYCMS_TYCATALOG_TOKEN'];
    }

    if (isset($options['token'])) {
        fwrite(STDERR, "Attention : --token peut rester dans l’historique du shell. Préférer la configuration locale.\n");
        $token = trim((string) $options['token']);
        if (!validToken($token)) {
            fail('Jeton --token invalide.');
        }
        return ['token' => $token, 'source' => 'option --token'];
    }

    $token = trim((string) ($config['token'] ?? ''));
    if ($token !== '') {
        if (!validToken($token)) {
            fail('Jeton de la configuration locale invalide.');
        }
        return ['token' => $token, 'source' => 'configuration locale'];
    }

    if (!$interactive) {
        fail('Jeton absent. Renseigner « token » dans ' . configPath() . ' ou TYCMS_TYCATALOG_TOKEN.');
    }

    $token = promptSecret('Coller le jeton de publication');
    if (!validToken($token)) {
        fail('Jeton invalide.');
    }
    if (confirm('Enregistrer ce jeton dans ' . configPath() . ' avec les permissions 0600 ?')) {
        saveConfigToken($config, $token);
        fwrite(STDOUT, "Jeton enregistré dans la configuration locale.\n");
    }

    return ['token' => $token, 'source' => 'saisi pour cette session'];
}

function baseUrl(string $url): string
{
    $parts = parse_url($url);
    $host = is_array($parts) ? (string) ($parts['host'] ?? '') : '';
    $local = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    if (!is_array($parts) || $host === '' || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['query']) || isset($parts['fragment'])
        || !in_array($parts['scheme'] ?? '', $local ? ['https', 'http'] : ['https'], true)) {
        fail('URL de base invalide : HTTPS attendu, hors localhost.');
    }

    return rtrim($url, '/');
}

/** @param array<string, mixed> $fields @return array<string, mixed> */
function postMultipart(string $url, string $token, array $fields): array
{
    $curl = curl_init($url);
    if ($curl === false) fail('Initialisation HTTP impossible.');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $fields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 300,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
    ]);

    return finish($curl);
}

/** @param array<string, mixed> $data @return array<string, mixed> */
function postJson(string $url, string $token, array $data): array
{
    // Le routeur valide les paramètres de schéma comme une chaîne JSON.
    $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $body = json_encode(['data' => $encoded], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $curl = curl_init($url);
    if ($curl === false) fail('Initialisation HTTP impossible.');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 300,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
            'Content-Type: application/json',
        ],
    ]);

    return finish($curl);
}

/** @return array<string, mixed> */
function finish(mixed $curl): array
{
    try {
        $body = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    } finally {
            // Pas de curl_close() : sans effet depuis PHP 8.0 et déprécié en 8.5.
        unset($curl);
    }

    $decoded = is_string($body) ? json_decode($body, true) : null;
    if (!is_array($decoded)) {
        fail('Réponse distante invalide (HTTP ' . $status . ').');
    }
    if ($status !== 200) {
        $message = trim((string) ($decoded['message'] ?? ''));
        fail('HTTP ' . $status . ($message !== '' ? ' : ' . $message : '.'));
    }
    if (($decoded['error'] ?? false) === true
        || (($decoded['is_message'] ?? false) === true && trim((string) ($decoded['message'] ?? '')) !== '')) {
        fail((string) ($decoded['message'] ?? 'Le serveur a refusé la demande.'));
    }

    return $decoded;
}

function checkPackageFiles(string $distDir): array
{
    $archives = glob(rtrim($distDir, '/') . '/*.zip') ?: [];
    sort($archives);
    if ($archives === []) {
        fail('Aucun ZIP dans ' . $distDir . '. Construire le paquet d’abord.');
    }

    fwrite(STDOUT, "Paquets disponibles :\n");
    foreach ($archives as $index => $archive) {
        fwrite(STDOUT, ($index + 1) . '. ' . basename($archive) . "\n");
    }
    fwrite(STDOUT, "0. Annuler\n");
    $choice = prompt('Choix', '0');
    if ($choice === '0') {
        return [];
    }
    if (!ctype_digit($choice) || !isset($archives[(int) $choice - 1])) {
        fail('Choix invalide.');
    }

    $zip = $archives[(int) $choice - 1];
    $base = substr($zip, 0, -4);
    $notes = $base . '.md';
    $signature = $base . '.extension-signature.json';
    foreach ([$notes, $signature] as $file) {
        if (!is_file($file) || !is_readable($file)) {
            fail('Fichier requis absent : ' . $file);
        }
    }

    return ['zip' => $zip, 'notes' => $notes, 'signature' => $signature];
}

function depositLocal(string $base, string $token, int $catalogId, array $files, string $displayName, string $description): int
{
    $fields = [
        'catalogId' => (string) $catalogId,
        'displayName' => $displayName,
        'description' => $description,
        'archive' => new CURLFile($files['zip'], 'application/zip', basename($files['zip'])),
        'notes' => new CURLFile($files['notes'], 'text/markdown', basename($files['notes'])),
        'signature' => new CURLFile($files['signature'], 'application/json', basename($files['signature'])),
    ];
    $result = postMultipart($base . '/tycms-catalog-api/v1/deposit', $token, $fields);
    $versionId = (int) ($result['version_id'] ?? 0);
    if ($versionId < 1) fail('Le serveur n’a pas retourné d’identifiant de version.');

    return $versionId;
}

function depositExternal(string $base, string $token, int $catalogId, string $archiveUrl, string $notesUrl, string $signatureFile, string $displayName, string $description): int
{
    $envelope = (string) file_get_contents($signatureFile);
    $result = postJson($base . '/tycms-catalog-api/v1/reference', $token, [
        'catalogId' => $catalogId,
        'archiveUrl' => $archiveUrl,
        'notesUrl' => $notesUrl,
        'envelope' => $envelope,
        'displayName' => $displayName,
        'description' => $description,
    ]);
    $versionId = (int) ($result['version_id'] ?? 0);
    if ($versionId < 1) fail('Le serveur n’a pas retourné d’identifiant de version.');

    return $versionId;
}

function cleanupSummary(mixed $cleanup): string
{
    if (!is_array($cleanup)) {
        return '';
    }

    $summary = ' Nettoyage : ' . (int) ($cleanup['versions_deleted'] ?? 0) . ' version(s), '
        . (int) ($cleanup['archives_deleted'] ?? 0) . ' ZIP.';
    if (!empty($cleanup['errors'])) {
        $summary .= ' Certaines opérations ont échoué.';
    }

    return $summary;
}

function publishVersion(string $base, string $token, int $catalogId, int $versionId,
    string $channel = 'release'): void
{
    $result = postJson($base . '/tycms-catalog-api/v1/publish', $token, [
        'catalogId' => $catalogId, 'versionId' => $versionId, 'channel' => $channel,
    ]);
    $label = $channel === 'beta' ? 'publiée en bêta' : 'publiée en release';
    fwrite(STDOUT, 'Version #' . $versionId . ' ' . $label
        . cleanupSummary($result['cleanup'] ?? null) . "\n");
}

/**
 * Demander où diffuser la version déposée.
 *
 * @return string|null 'beta', 'release', ou null pour la laisser en brouillon
 */
function askDiffusion(): ?string
{
    fwrite(STDOUT, "\nDiffusion de cette version :\n");
    fwrite(STDOUT, "1. Publier en bêta (instances qui la demandent seulement)\n");
    fwrite(STDOUT, "2. Laisser en brouillon (aucune diffusion)\n");
    fwrite(STDOUT, "3. Publier en release (toutes les instances)\n");
    $choice = prompt('Choix', '2');

    return ['1' => 'beta', '3' => 'release'][$choice] ?? null;
}

function versionAction(string $base, string $token, int $catalogId, string $action, int $versionId): void
{
    $result = postJson($base . '/tycms-catalog-api/v1/' . $action, $token, [
        'catalogId' => $catalogId, 'versionId' => $versionId,
    ]);
    $labels = ['withdraw' => 'retirée', 'reactivate' => 'réactivée', 'promote' => 'promue en release'];
    fwrite(STDOUT, 'Version #' . $versionId . ' ' . ($labels[$action] ?? $action) . '.'
        . cleanupSummary($result['cleanup'] ?? null) . "\n");
}

$config = loadConfig();
$interactive = $argc === 1 || isset($options['interactive']);
$url = (string) ($options['url'] ?? $config['url'] ?? '');
$catalogId = (int) ($options['catalog'] ?? $config['catalog'] ?? 0);
$distDir = (string) ($config['dist_dir'] ?? __DIR__ . '/dist');
$displayName = (string) ($options['display-name'] ?? $config['display_name'] ?? '');
$description = (string) ($options['description'] ?? $config['description'] ?? '');

if (!$interactive) {
    if ($url === '' || $catalogId < 1) {
        fail('Utiliser --url et --catalog, ou la configuration locale.');
    }
    $base = baseUrl($url);
    $tokenData = resolveToken($config, $options, false);
    $token = $tokenData['token'];

    // Canal de publication : `release` par défaut, `beta` sur demande explicite.
    $channel = (string) ($options['channel'] ?? 'release');
    if (!in_array($channel, ['release', 'beta'], true)) {
        fail('--channel doit valoir release ou beta.');
    }

    if (isset($options['publish-version'])) {
        publishVersion($base, $token, $catalogId, (int) $options['publish-version'], $channel);
        exit(0);
    }
    if (isset($options['withdraw'])) {
        versionAction($base, $token, $catalogId, 'withdraw', (int) $options['withdraw']);
        exit(0);
    }
    if (isset($options['reactivate'])) {
        versionAction($base, $token, $catalogId, 'reactivate', (int) $options['reactivate']);
        exit(0);
    }
    if (isset($options['promote'])) {
        versionAction($base, $token, $catalogId, 'promote', (int) $options['promote']);
        exit(0);
    }

    $signatureFile = (string) ($options['signature'] ?? '');
    if ($signatureFile === '' || !is_file($signatureFile) || filesize($signatureFile) > 131072) {
        fail('Enveloppe de signature absente ou trop volumineuse.');
    }

    $external = isset($options['archive-url'], $options['notes-url']);
    if ($external) {
        $versionId = depositExternal($base, $token, $catalogId,
            (string) $options['archive-url'], (string) $options['notes-url'], $signatureFile, $displayName, $description);
    } else {
        if (!isset($options['zip'], $options['notes'])) {
            fail('Fournir --zip et --notes, ou --archive-url et --notes-url.');
        }
        $files = ['zip' => (string) $options['zip'], 'notes' => (string) $options['notes'], 'signature' => $signatureFile];
        foreach ([['zip', 134217728, 'ZIP'], ['notes', 262144, 'notes']] as [$key, $limit, $label]) {
            if (!is_file($files[$key]) || filesize($files[$key]) < 1 || filesize($files[$key]) > $limit) {
                fail('Fichier ' . $label . ' absent ou trop volumineux.');
            }
        }
        $versionId = depositLocal($base, $token, $catalogId, $files, $displayName, $description);
    }

    fwrite(STDOUT, 'Brouillon déposé : version #' . $versionId . ".\n");
    if (isset($options['publish'])) {
        publishVersion($base, $token, $catalogId, $versionId, $channel);
    }
    exit(0);
}

// Mode interactif : les valeurs manquantes sont demandées, le reste vient de la configuration.
if ($url === '') {
    $url = promptRequired('URL de base du site (ex: https://exemple.fr)');
}
$base = baseUrl($url);
if ($catalogId < 1) {
    do {
        $catalogId = (int) promptRequired('Identifiant du catalogue');
    } while ($catalogId < 1);
}
$config['url'] = $url;
$config['catalog'] = $catalogId;
$tokenData = resolveToken($config, $options, true);
$token = $tokenData['token'];
$tokenSource = $tokenData['source'];

while (true) {
    fwrite(STDOUT, "\nClient de publication TyCatalog\n");
    fwrite(STDOUT, 'URL : ' . $base . "\n");
    fwrite(STDOUT, 'Catalogue : #' . $catalogId . "\n");
    fwrite(STDOUT, 'Jeton : ' . $tokenSource . "\n");
    fwrite(STDOUT, 'Paquets : ' . $distDir . "\n\n");
    fwrite(STDOUT, "1. Déposer un paquet local, puis choisir la diffusion\n");
    fwrite(STDOUT, "2. Déposer un paquet local en brouillon\n");
    fwrite(STDOUT, "3. Déposer par URL externe\n");
    fwrite(STDOUT, "4. Publier une version existante\n");
    fwrite(STDOUT, "5. Retirer une version\n");
    fwrite(STDOUT, "6. Réactiver une version\n");
    fwrite(STDOUT, "7. Promouvoir une bêta en release\n");
    fwrite(STDOUT, "0. Annuler\n");
    $choice = prompt('Choix', '0');

    if ($choice === '0') {
        fwrite(STDOUT, "Publication annulée.\n");
        exit(0);
    }

    try {
        if ($choice === '1' || $choice === '2') {
            $files = checkPackageFiles($distDir);
            if ($files === []) {
                continue;
            }
            if (!confirm('Déposer ' . basename($files['zip']) . ' sur ' . $base . ' catalogue #' . $catalogId . ' ?')) {
                continue;
            }
            $versionId = depositLocal($base, $token, $catalogId, $files, $displayName, $description);
            fwrite(STDOUT, 'Brouillon déposé : version #' . $versionId . ".\n");
            if ($choice === '1') {
                // La version reste en brouillon tant que la diffusion n'est pas choisie.
                $channel = askDiffusion();
                if ($channel !== null) {
                    publishVersion($base, $token, $catalogId, $versionId, $channel);
                } else {
                    fwrite(STDOUT, "Version laissée en brouillon.\n");
                }
            }
        } elseif ($choice === '3') {
            $archiveUrl = promptRequired('URL HTTPS du ZIP');
            $notesUrl = promptRequired('URL HTTPS des notes');
            $signature = promptRequired('Fichier de signature');
            if (!is_file($signature) || filesize($signature) > 131072) {
                fail('Enveloppe de signature absente ou trop volumineuse.');
            }
            if (!confirm('Déposer la référence externe sur ' . $base . ' catalogue #' . $catalogId . ' ?')) {
                continue;
            }
            $versionId = depositExternal($base, $token, $catalogId, $archiveUrl, $notesUrl, $signature, $displayName, $description);
            fwrite(STDOUT, 'Brouillon déposé : version #' . $versionId . ".\n");
            $channel = askDiffusion();
            if ($channel !== null) {
                publishVersion($base, $token, $catalogId, $versionId, $channel);
            } else {
                fwrite(STDOUT, "Version laissée en brouillon.\n");
            }
        } else {
            $versionId = (int) promptRequired('Identifiant de version');
            if ($versionId < 1) {
                fail('Identifiant de version invalide.');
            }
            $action = ['4' => 'publish', '5' => 'withdraw', '6' => 'reactivate', '7' => 'promote'][$choice] ?? null;
            if ($action === null) {
                fail('Choix invalide.');
            }
            $actionLabels = ['publish' => 'Publier', 'withdraw' => 'Retirer',
                'reactivate' => 'Réactiver', 'promote' => 'Promouvoir en release'];
            if (!confirm($actionLabels[$action] . ' la version #' . $versionId . ' ?')) {
                continue;
            }
            if ($action === 'publish') {
                $channel = askDiffusion();
                if ($channel === null) {
                    fwrite(STDOUT, "Version laissée en brouillon.\n");
                } else {
                    publishVersion($base, $token, $catalogId, $versionId, $channel);
                }
            } else {
                versionAction($base, $token, $catalogId, $action, $versionId);
            }
        }
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Erreur : ' . $exception->getMessage() . "\n");
    }
}
