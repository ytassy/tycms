<?php

namespace YTassy\Tycms\CoreExts\Core\Helper\Video;

use RuntimeException;

/** Format VKEY1 de l'ancienne vidéothèque, indépendant du secret applicatif. */
final class SecureVideoKey
{
    private const MAGIC = 'VKEY1';
    private const ITERATIONS = 210000;

    /** Transport historique compris par le hook réseau du lecteur Video.js. */
    public static function encodeForPlayer(string $key): string
    {
        if (strlen($key) !== 16) throw new RuntimeException('Clé vidéo invalide.');

        $letters = 'azertyuiopqsdfghjklmwxcvbn12345678999';
        return '==B64' . $letters[random_int(0, strlen($letters) - 1)] . base64_encode($key);
    }

    public static function encrypt(string $key, string $password): string
    {
        if (strlen($key) !== 16 || $password === '') throw new RuntimeException('Clé vidéo invalide.');

        $salt = random_bytes(16);
        $nonce = random_bytes(12);
        $derived = hash_pbkdf2('sha256', $password, $salt, self::ITERATIONS, 32, true);
        $tag = '';
        $cipher = openssl_encrypt($key, 'aes-256-gcm', $derived, OPENSSL_RAW_DATA, $nonce, $tag, self::MAGIC);
        if ($cipher === false || strlen($tag) !== 16) throw new RuntimeException('Chiffrement de clé impossible.');

        return self::MAGIC . $salt . $nonce . $tag . $cipher;
    }

    public static function decrypt(string $data, string $password): string
    {
        if (strlen($data) !== 65 || !str_starts_with($data, self::MAGIC) || $password === '') {
            throw new RuntimeException('Fichier de clé invalide.');
        }

        $derived = hash_pbkdf2('sha256', $password, substr($data, 5, 16), self::ITERATIONS, 32, true);
        $plain = openssl_decrypt(substr($data, 49, 16), 'aes-256-gcm', $derived, OPENSSL_RAW_DATA,
            substr($data, 21, 12), substr($data, 33, 16), self::MAGIC);
        if ($plain === false || strlen($plain) !== 16) throw new RuntimeException('Clé vidéo illisible.');

        return $plain;
    }
}
