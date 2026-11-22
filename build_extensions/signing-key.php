<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Garder les noms des clés d’extension distincts des clés release-… de TyCMS.
if ($argc !== 2 || !preg_match('/\Aextensions-[A-Za-z0-9_-]+\.secret\z/', basename($argv[1]))) {
    fwrite(STDERR, "Usage : php build_extensions/signing-key.php /chemin/prive/extensions-store-1.secret\n");
    exit(1);
}
if (!is_dir(dirname($argv[1]))) {
    fwrite(STDERR, 'Dossier privé introuvable : ' . dirname($argv[1]) . '. Le créer avant de générer les clés.' . PHP_EOL);
    exit(1);
}
foreach ([$argv[1], $argv[1] . '.pub'] as $file) {
    if (file_exists($file) || is_link($file)) {
        fwrite(STDERR, 'Fichier déjà présent, aucun écrasement : ' . $file . PHP_EOL);
        exit(1);
    }
}
$generator = dirname(__DIR__) . '/build/signing-key.php';
if (!is_file($generator) || !is_readable($generator)) {
    fwrite(STDERR, 'Générateur de clés requis absent ou illisible : ' . $generator . PHP_EOL);
    exit(1);
}
if (!extension_loaded('sodium')) {
    fwrite(STDERR, 'Extension PHP sodium requise pour générer les clés du Store.' . PHP_EOL);
    exit(1);
}
require $generator;
