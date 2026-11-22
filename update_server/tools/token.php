<?php

declare(strict_types=1);

// Cet outil d'administration n'est jamais utilisable depuis une requête HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if ($argc > 2 || ($argc === 2 && !in_array($argv[1], ['--hash', '--help'], true))) {
    fwrite(STDERR, "Usage : php update_server/tools/token.php [--hash | --help]\n");
    exit(1);
}

if (($argv[1] ?? null) === '--help') {
    echo "Sans option : générer un token aléatoire et son empreinte SHA-256.\n";
    echo "--hash : lire un token de 64 caractères hexadécimaux sur l'entrée standard.\n";
    echo "Ne pas passer le token en argument de commande.\n";
    exit;
}

try {
    // Une entropie de 256 bits permet un stockage par empreinte SHA-256 simple.
    if (($argv[1] ?? null) === '--hash') {
        fwrite(STDERR, "Token (64 caractères hexadécimaux minuscules), puis Entrée :\n");
        $input = fgets(STDIN, 68);
        $token = $input === false ? '' : rtrim($input, "\r\n");
        if (preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1) {
            fwrite(STDERR, "Format invalide : utiliser un token aléatoire de 64 caractères hexadécimaux minuscules.\n");
            exit(1);
        }
    } else {
        $token = bin2hex(random_bytes(32));
        echo "Token à conserver côté publication (secret) :\n" . $token . "\n\n";
    }

    // Le serveur recevra uniquement cette empreinte dans sa configuration privée.
    $hash = hash('sha256', $token);
    echo "Ligne à ajouter dans publish_token_sha256 de configuration.php :\n";
    echo "    '" . $hash . "',\n";
} catch (Throwable) {
    fwrite(STDERR, "Impossible de préparer le token.\n");
    exit(1);
}
