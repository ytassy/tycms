<?php

// Copier hors du dépôt (par défaut ~/.config/tycms/publication.php).
// Ne jamais transmettre cette configuration au conteneur de build.
return [
    'server_url' => 'https://updates.example.org/index.php',
    'token' => 'TOKEN_ORIGINAL_GENERE_PAR_LE_SCRIPT',
    'key_id' => 'release-1',
    'private_key_file' => '/CHEMIN/PRIVE/release-1.secret',

    'timeout_seconds' => 300,

    // Refusé hors d'une URL http://127.0.0.1 ou http://[::1], même si activé.
    'allow_loopback_http' => false,
];
