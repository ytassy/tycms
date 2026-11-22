<?php

declare(strict_types=1);

// Cet outil d'administration n'est jamais utilisable depuis une requête HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if ($argc > 1 && $argv[1] !== '--help') {
    fwrite(STDERR, "Usage : php update_server/tools/admin-password.php [--help]\n");
    exit(1);
}

if (($argv[1] ?? null) === '--help') {
    echo "Lit un mot de passe sur l'entrée standard et affiche uniquement son empreinte Argon2id.\n";
    echo "Ne pas passer le mot de passe en argument de commande.\n";
    exit;
}

try {
    fwrite(STDERR, "Mot de passe du compte d'administration, puis Entrée :\n");
    $input = fgets(STDIN, 4097);
    // Choisir un mot de passe assez long vaut mieux qu'une règle de caractères.
    $password = $input === false ? '' : rtrim($input, "\r\n");
    if (strlen($password) < 12 || strlen($password) > 4096) {
        fwrite(STDERR, "Mot de passe refusé : de 12 à 4096 caractères attendus.\n");
        exit(1);
    }

    // Argon2id par défaut : l'empreinte seule est déposée dans la configuration privée.
    $hash = password_hash($password, PASSWORD_ARGON2ID);
    if (!is_string($hash) || !str_starts_with($hash, '$argon2id$')) {
        fwrite(STDERR, "Empreinte Argon2id impossible ; vérifier les options PHP.\n");
        exit(1);
    }
    echo "Ligne à ajouter dans configuration.php :\n";
    echo "'admin_password_hash' => '" . $hash . "',\n";
} catch (Throwable) {
    // Ne jamais recopier la saisie ni une trace dans la sortie.
    fwrite(STDERR, "Génération impossible.\n");
    exit(1);
}
