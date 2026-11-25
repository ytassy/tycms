<?php

// Tester une écriture réelle avec le compte des requêtes PHP.
$dirs = [
    'var',
    'var/updates',
    'var/extensions',
    'public',
    'releases/vdev',
    'logs',
    'tmp',
    'tmp/migrations',
    'public/medias',
    'public/assets/fonts'
];

foreach ($dirs as $dir) {
    $file = $dir . '/.write-test-' . uniqid();

    if (file_put_contents($file, 'test') === false || !unlink($file)) {
        fwrite(STDERR, "Dossier inaccessible en écriture : $dir\n");
        exit(1);
    }
}

// Les états privés peuvent être en 0600 après une écriture atomique.
foreach (['var/releases-states.json', 'var/last_update_data.json', 'releases/vdev/configuration.php'] as $file) {
    if (!is_file($file) || !is_readable($file)) {
        fwrite(STDERR, "Fichier inaccessible en lecture : $file\n");
        exit(1);
    }
}
