<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Aucun secret n'est affiché : les deux fichiers sont créés sans écraser d'existant.
umask(0077);
$created = [];
try {
    if ($argc !== 2 || !extension_loaded('sodium')) {
        throw new RuntimeException('Usage : php build/signing-key.php /chemin/hors-du-depot/release-1.secret (extension sodium requise)');
    }
    $directory = realpath(dirname($argv[1]));
    $repository = realpath(dirname(__DIR__));
    if ($directory === false || $directory === $repository || str_starts_with($directory, $repository . '/')) {
        throw new RuntimeException('Créer le dossier des clés hors du dépôt avant de lancer cette commande.');
    }
    $secretPath = $directory . '/' . basename($argv[1]);
    $pair = sodium_crypto_sign_keypair();
    $secret = base64_encode(sodium_crypto_sign_secretkey($pair)) . "\n";
    $public = base64_encode(sodium_crypto_sign_publickey($pair));
    foreach ([$secretPath => $secret, $secretPath . '.pub' => $public . "\n"] as $path => $contents) {
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            throw new RuntimeException('Un fichier de clé existe déjà ou ne peut pas être créé.');
        }
        $created[] = $path;
        try {
            if (fwrite($handle, $contents) !== strlen($contents)) {
                throw new RuntimeException('Écriture de clé incomplète.');
            }
        } finally {
            fclose($handle);
        }
    }
    echo "Clé privée créée : $secretPath\nClé publique : $secretPath.pub\n";
    echo "Clé publique à copier dans trusted_public_keys :\n$public\n";
    sodium_memzero($pair);
    sodium_memzero($secret);
} catch (Throwable $error) {
    foreach ($created as $path) {
        unlink($path);
    }
    fwrite(STDERR, $error instanceof RuntimeException ? $error->getMessage() . "\n" : "Création de clé impossible.\n");
    exit(1);
}
