<?php

declare(strict_types=1);

// Le bootstrap reste autonome : aucune classe de release n'est encore chargée.
$stopBootstrap = static function (string $reason): never {
    error_log('Tycms bootstrap : ' . $reason);

    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Le site est temporairement indisponible.';
    exit(1);
};

// Lire une seule fois l'état local, sans exposer les chemins en cas d'erreur.
$installationRoot = dirname(__DIR__);
$releaseFile = $installationRoot . '/var/releases-states.json';
$contents = @file_get_contents($releaseFile);

if ($contents === false) {
    $stopBootstrap('État des releases illisible.');
}

try {
    $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    $stopBootstrap('État des releases : JSON invalide.');
}

if (!is_array($data) || ($data['schema_version'] ?? null) !== 1) {
    $stopBootstrap('Schéma de l’état des releases non pris en charge.');
}

// Accepter un identifiant de dossier, jamais un chemin ou une URL d'inclusion.
$activeRelease = $data['active_release'] ?? null;

if (!is_string($activeRelease) || preg_match('/\A[A-Za-z0-9_-]+\z/', $activeRelease) !== 1) {
    $stopBootstrap('Identifiant de release invalide.');
}

// Vérifier aussi les chemins réels pour refuser les liens sortant des releases.
$releasesRoot = realpath($installationRoot . '/releases');
$indexFile = realpath($installationRoot . '/releases/' . $activeRelease . '/index.php');

if ($releasesRoot === false || $indexFile === false
    || !str_starts_with($indexFile, $releasesRoot . DIRECTORY_SEPARATOR)
    || !is_file($indexFile) || !is_readable($indexFile)) {
    $stopBootstrap('Point d’entrée de release absent, inaccessible ou hors des releases.');
}

require_once $indexFile;
