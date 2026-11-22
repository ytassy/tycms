<?php

namespace YTassy\Tycms\CoreExts\Core\Helper\Crypt;

use RuntimeException;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;

/** Authenticated encryption for application secrets stored as strings or blobs. */
final class CryptHelper extends BaseHelper
{
    private const CIPHER = 'aes-256-gcm';
    private const PREFIX = 'tycmscrypt:v1:';
    private const IV_LENGTH = 12;
    private const TAG_LENGTH = 16;

    /**
     * $purpose separates keys used by distinct features. An explicit password
     * overrides Configuration.secret; the same inputs are needed for decryption.
     */
    public function encrypt(string $plainText, string $purpose = 'generic:v1', ?string $password = null): string
    {
        $key = $this->key($purpose, $password);
        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';
        $cipherText = openssl_encrypt(
            $plainText, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, self::PREFIX . $purpose,
            self::TAG_LENGTH
        );
        if ($cipherText === false || strlen($tag) !== self::TAG_LENGTH) {
            throw new RuntimeException('Impossible de chiffrer les données.');
        }

        return self::PREFIX . base64_encode($iv . $tag . $cipherText);
    }

    public function decrypt(string $encrypted, string $purpose = 'generic:v1', ?string $password = null): string
    {
        $key = $this->key($purpose, $password);
        if (!str_starts_with($encrypted, self::PREFIX)) {
            throw new RuntimeException('Format de données chiffrées inconnu.');
        }
        $payload = base64_decode(substr($encrypted, strlen(self::PREFIX)), true);
        if ($payload === false || strlen($payload) < self::IV_LENGTH + self::TAG_LENGTH) {
            throw new RuntimeException('Données chiffrées invalides.');
        }
        $iv = substr($payload, 0, self::IV_LENGTH);
        $tag = substr($payload, self::IV_LENGTH, self::TAG_LENGTH);
        $cipherText = substr($payload, self::IV_LENGTH + self::TAG_LENGTH);
        $plainText = openssl_decrypt(
            $cipherText, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, self::PREFIX . $purpose
        );
        if ($plainText === false) {
            throw new RuntimeException('Impossible de déchiffrer les données.');
        }

        return $plainText;
    }

    private function key(string $purpose, ?string $password): string
    {
        if ($purpose === '') {
            throw new RuntimeException('Usage de clé vide.');
        }
        $secret = $password ?? (string) ($this->app->getConf()->secret ?? '');
        if ($secret === '') {
            throw new RuntimeException('Configuration.secret ne peut pas être vide.');
        }

        return hash_hkdf('sha256', $secret, 32, 'tycms:crypt:v1:' . $purpose);
    }
}
