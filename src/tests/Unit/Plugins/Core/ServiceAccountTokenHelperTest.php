<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\CoreExts\Core\Helper\ServiceAccountTokenHelper;

final class ServiceAccountTokenHelperTest extends TestCase
{
    #[Test]
    public function generatedSecretIsA64CharacterHexString(): void
    {
        $secret = ServiceAccountTokenHelper::generateSecret();

        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $secret);
    }

    #[Test]
    public function hashSecretIsDeteterministicSha256(): void
    {
        $secret = ServiceAccountTokenHelper::generateSecret();

        self::assertSame(
            hash('sha256', $secret),
            ServiceAccountTokenHelper::hashSecret($secret),
        );
        self::assertSame(
            ServiceAccountTokenHelper::hashSecret($secret),
            ServiceAccountTokenHelper::hashSecret($secret),
        );
    }

    #[Test]
    public function verifySecretAcceptsTheMatchingSecretOnly(): void
    {
        $secret = ServiceAccountTokenHelper::generateSecret();
        $hash = ServiceAccountTokenHelper::hashSecret($secret);

        self::assertTrue(ServiceAccountTokenHelper::verifySecret($secret, $hash));
        self::assertFalse(ServiceAccountTokenHelper::verifySecret('autre-secret', $hash));
    }

    #[Test]
    public function hashNeverEchoesThePlainSecret(): void
    {
        $secret = ServiceAccountTokenHelper::generateSecret();

        self::assertNotSame($secret, ServiceAccountTokenHelper::hashSecret($secret));
    }
}