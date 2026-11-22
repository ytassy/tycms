<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use RuntimeException;
use YTassy\Tycms\CoreExts\Core\Helper\Crypt\CryptHelper;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class CryptHelperTest extends FrameworkTestCase
{
    public function testArbitraryConfigurationSecretAndSeparatePurposes(): void
    {
        $app = $this->createApplication();
        $app->getConf()->secret = 'clé libre / espaces + accents !';
        $crypto = new CryptHelper($app);

        $first = $crypto->encrypt('donnée sensible', 'provider:v1');
        $second = $crypto->encrypt('donnée sensible', 'provider:v1');
        self::assertStringStartsWith('tycmscrypt:v1:', $first);
        self::assertNotSame($first, $second);
        self::assertSame('donnée sensible', $crypto->decrypt($first, 'provider:v1'));
        self::assertSame('', $crypto->decrypt($crypto->encrypt('', 'provider:v1'), 'provider:v1'));

        $this->expectException(RuntimeException::class);
        $crypto->decrypt($first, 'link:v1');
    }

    public function testExplicitPasswordOverridesConfigurationAndTamperingFails(): void
    {
        $app = $this->createApplication();
        $app->getConf()->secret = '';
        $crypto = new CryptHelper($app);
        $cipher = $crypto->encrypt('secret', password: 'mot de passe libre');
        self::assertSame('secret', $crypto->decrypt($cipher, password: 'mot de passe libre'));

        try {
            $crypto->decrypt($cipher, password: 'autre mot de passe');
            self::fail('Une autre clé doit être refusée.');
        } catch (RuntimeException) {
            self::assertTrue(true);
        }

        $payload = base64_decode(substr($cipher, strlen('tycmscrypt:v1:')), true);
        self::assertIsString($payload);
        $payload[15] = chr(ord($payload[15]) ^ 1);
        $modified = 'tycmscrypt:v1:' . base64_encode($payload);
        $this->expectException(RuntimeException::class);
        $crypto->decrypt($modified, password: 'mot de passe libre');
    }

    public function testInvalidFormatAndEmptySecretAreRejected(): void
    {
        $app = $this->createApplication();
        $crypto = new CryptHelper($app);
        try {
            $crypto->decrypt('ancienne valeur en clair');
            self::fail('Un format inconnu doit être refusé.');
        } catch (RuntimeException) {
            self::assertTrue(true);
        }

        $app->getConf()->secret = '';
        $this->expectException(RuntimeException::class);
        $crypto->encrypt('secret');
    }
}
