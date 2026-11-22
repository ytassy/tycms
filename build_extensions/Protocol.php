<?php

declare(strict_types=1);

namespace Tycms\ExtensionDistribution;

// Conserver l'API historique du build, avec exactement le vérificateur livré
// par la release pour éviter deux règles cryptographiques divergentes.
require_once dirname(__DIR__) . '/src/releases/vdev/framework/Extensions/ExtensionMetadata.php';
require_once dirname(__DIR__) . '/src/releases/vdev/framework/Extensions/ExtensionPackage.php';

class Protocol extends \YTassy\Tycms\Extensions\ExtensionPackage
{
    /**
     * Signature réservée à l'outil de build : la clé privée ne doit jamais
     * entrer dans le runtime ni dans le serveur TyCatalog.
     */
    public static function sign(array $manifest, string $keyId, string $secret): string
    {
        $payload = json_encode(self::manifest($manifest), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return json_encode([
            'format' => 'tycms-extension-signature-v1',
            'key_id' => self::keyId($keyId),
            'payload' => base64_encode($payload),
            'signature' => base64_encode(sodium_crypto_sign_detached(self::DOMAIN . $payload, $secret)),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }
}
