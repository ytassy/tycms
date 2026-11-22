<?php

// Copier vers configuration.php, hors de public/ ; ne pas versionner la copie locale.
return [
    // Empreintes SHA-256 uniquement. Une liste vide n'autorise aucune publication.
    // Générer un token avec : php update_server/tools/token.php
    'publish_token_sha256' => [
    ],

    // Identifiant => clé publique Ed25519 en base64, installée par un canal de confiance.
    // La clé privée reste sur la machine de publication.
    'trusted_public_keys' => [
        // 'release-1' => 'CLE_PUBLIQUE_BASE64',
    ],

    // Doit rester hors de public/ et sur un stockage local supportant flock/rename.
    'storage_path' => __DIR__ . '/storage',
    'max_package_bytes' => 128 * 1024 * 1024,

    // Réservé aux tests via le serveur PHP intégré, accessible depuis la boucle locale.
    'allow_loopback_http' => false,
];
