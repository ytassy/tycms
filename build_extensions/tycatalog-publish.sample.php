<?php

// Copier vers tycatalog-publish.local.php (ignoré par Git), puis chmod 600.
// Le jeton est stocké directement dans ce fichier local, jamais dans le dépôt.
return [
    'url' => 'http://localhost:8080',
    // Identifiant numérique affiché en badge « ID » dans l'administration du catalogue.
    'catalog' => 1,
    'token' => '', // tycat_…
    'dist_dir' => __DIR__ . '/dist',
    'display_name' => '',
    'description' => '',
];
