<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Extensions;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use YTassy\Tycms\Extensions\ExtensionPackage;

/**
 * Contrat cryptographique partagé par le build, TyCatalog et le futur client
 * Core. Une signature valide authentifie l'éditeur, pas l'innocuité du code.
 */
final class ExtensionPackageTest extends TestCase
{
    private const DOMAIN = "TyCMS extension package v1\0";

    #[Test]
    public function signedManifestIsAcceptedAndAnyAlterationIsRejected(): void
    {
        if (!extension_loaded('sodium')) {
            self::markTestSkipped('sodium est requis pour vérifier les paquets.');
        }

        $keyPair = sodium_crypto_sign_keypair();
        $public = base64_encode(sodium_crypto_sign_publickey($keyPair));
        $secret = sodium_crypto_sign_secretkey($keyPair);
        $manifest = $this->manifest();
        $payload = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $envelope = $this->envelope($payload, $secret);

        self::assertSame($manifest, ExtensionPackage::verify($envelope, ['extensions-store-1' => $public]));

        // Modifier le payload sans refaire la signature doit être refusé.
        $tamperedPayload = json_decode($payload, true, 16, JSON_THROW_ON_ERROR);
        $tamperedPayload['metadata']['version'] = '0.1.1';
        $tampered = $this->envelope(json_encode($tamperedPayload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $secret);
        $this->expectException(RuntimeException::class);
        ExtensionPackage::verify($tampered, ['extensions-store-1' => $public]);
    }

    #[Test]
    public function unknownOrInvalidKeyIsRejected(): void
    {
        if (!extension_loaded('sodium')) {
            self::markTestSkipped('sodium est requis pour vérifier les paquets.');
        }

        $keyPair = sodium_crypto_sign_keypair();
        $public = base64_encode(sodium_crypto_sign_publickey($keyPair));
        $secret = sodium_crypto_sign_secretkey($keyPair);
        $envelope = $this->envelope(
            json_encode($this->manifest(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $secret,
        );

        $this->expectException(RuntimeException::class);
        ExtensionPackage::verify($envelope, ['extensions-other-1' => $public]);
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        $notes = 'Notes de version.';
        $metadata = [
            'schema_version' => 1,
            'id' => 'plg_demo',
            'type' => 'plugin',
            'folder' => 'Demo',
            'version' => '0.1.0',
            'source_versions' => [],
            'cms_min' => '1.0.0',
            'cms_max_exclusive' => '2.0.0',
            'php_min' => '8.4.0',
            'php_max_exclusive' => '9.0.0',
        ];

        return ExtensionPackage::manifest([
            'schema_version' => 1,
            'artifact_type' => 'tycms-extension',
            'metadata' => $metadata,
            'frontend_entry' => null,
            'archive' => [
                'filename' => 'plg_demo-0_1_0.zip',
                'bytes' => 100,
                'sha256' => str_repeat('a', 64),
            ],
            'release_notes' => [
                'filename' => 'plg_demo-0_1_0.md',
                'bytes' => strlen($notes),
                'sha256' => hash('sha256', $notes),
            ],
        ]);
    }

    private function envelope(string $payload, string $secret): string
    {
        return json_encode([
            'format' => 'tycms-extension-signature-v1',
            'key_id' => 'extensions-store-1',
            'payload' => base64_encode($payload),
            'signature' => base64_encode(sodium_crypto_sign_detached(self::DOMAIN . $payload, $secret)),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
