<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\ExtensionCatalog;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use YTassy\Tycms\Extensions\ExtensionMetadata;
use YTassy\Tycms\Extensions\ExtensionPackage;

final class ExtensionPackageSignatureTest extends TestCase
{
    private const KEY_ID = 'extensions-test';
    private const DOMAIN = "TyCMS extension package v1\0";

    #[Test]
    public function validEnvelopeIsAcceptedWithTrustedKey(): void
    {
        [$public, $secret] = $this->keyPair();
        $envelope = $this->envelope($this->exampleManifest(), $secret);

        $verified = ExtensionPackage::verify($envelope, [self::KEY_ID => base64_encode($public)]);

        self::assertSame('plg_demo', $verified['metadata']['id']);
        self::assertSame('0.1.0', $verified['metadata']['version']);
    }

    #[Test]
    public function alteredPayloadIsRejected(): void
    {
        [$public, $secret] = $this->keyPair();
        $envelope = json_decode($this->envelope($this->exampleManifest(), $secret), true, 16, JSON_THROW_ON_ERROR);
        $manifest = json_decode(base64_decode($envelope['payload'], true), true, 16, JSON_THROW_ON_ERROR);
        $manifest['metadata']['version'] = '0.1.1';
        $envelope['payload'] = base64_encode(json_encode($manifest, JSON_THROW_ON_ERROR));

        $this->expectException(RuntimeException::class);
        ExtensionPackage::verify(
            json_encode($envelope, JSON_THROW_ON_ERROR),
            [self::KEY_ID => base64_encode($public)],
        );
    }

    #[Test]
    public function unknownKeyIdIsRejected(): void
    {
        [, $secret] = $this->keyPair();
        [$trusted] = $this->keyPair();

        $this->expectException(RuntimeException::class);
        ExtensionPackage::verify(
            $this->envelope($this->exampleManifest(), $secret, 'extensions-other'),
            [self::KEY_ID => base64_encode($trusted)],
        );
    }

    #[Test]
    public function wrongPublicKeyIsRejected(): void
    {
        [, $secret] = $this->keyPair();
        [$otherPublic] = $this->keyPair();

        $this->expectException(RuntimeException::class);
        ExtensionPackage::verify(
            $this->envelope($this->exampleManifest(), $secret),
            [self::KEY_ID => base64_encode($otherPublic)],
        );
    }

    #[Test]
    public function alteredSignatureIsRejected(): void
    {
        [$public, $secret] = $this->keyPair();
        $envelope = json_decode($this->envelope($this->exampleManifest(), $secret), true, 16, JSON_THROW_ON_ERROR);
        $signature = base64_decode($envelope['signature'], true);
        $signature[0] = chr(ord($signature[0]) ^ 0x01);
        $envelope['signature'] = base64_encode($signature);

        $this->expectException(RuntimeException::class);
        ExtensionPackage::verify(
            json_encode($envelope, JSON_THROW_ON_ERROR),
            [self::KEY_ID => base64_encode($public)],
        );
    }

    /** @return array{0:string,1:string} */
    private function keyPair(): array
    {
        $pair = sodium_crypto_sign_keypair();

        return [
            sodium_crypto_sign_publickey($pair),
            sodium_crypto_sign_secretkey($pair),
        ];
    }

    private function envelope(array $manifest, string $secret, string $keyId = self::KEY_ID): string
    {
        $payload = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $signature = sodium_crypto_sign_detached(self::DOMAIN . $payload, $secret);

        return json_encode([
            'format' => 'tycms-extension-signature-v1',
            'key_id' => $keyId,
            'signature' => base64_encode($signature),
            'payload' => base64_encode($payload),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function exampleManifest(): array
    {
        $metadata = ExtensionMetadata::validate([
            'schema_version' => 1,
            'id' => 'plg_demo',
            'type' => 'plugin',
            'folder' => 'Demo',
            'version' => '0.1.0',
            'source_versions' => '*',
            'cms_min' => '1.1.3',
            'cms_max_exclusive' => '1.2.0',
            'php_min' => '8.4.1',
            'php_max_exclusive' => '8.6.0',
        ]);
        $base = ExtensionMetadata::artifactName($metadata);

        return [
            'schema_version' => 1,
            'artifact_type' => 'tycms-extension',
            'metadata' => $metadata,
            'frontend_entry' => null,
            'archive' => [
                'filename' => $base . '.zip',
                'bytes' => 10,
                'sha256' => str_repeat('a', 64),
            ],
            'release_notes' => [
                'filename' => $base . '.md',
                'bytes' => 5,
                'sha256' => hash('sha256', 'Notes'),
            ],
        ];
    }
}
