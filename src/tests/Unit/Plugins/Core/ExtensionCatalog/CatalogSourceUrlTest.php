<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\ExtensionCatalog;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use YTassy\Tycms\CoreExts\Core\Helper\Extension\CatalogSourceUrl;

final class CatalogSourceUrlTest extends TestCase
{
    #[Test]
    public function httpsUrlIsCanonicalizedWithoutTrailingSlash(): void
    {
        self::assertSame(
            'https://example.org/tycms-catalog/official',
            CatalogSourceUrl::normalize(' HTTPS://Example.ORG/tycms-catalog/official/ ', false),
        );
    }

    #[Test]
    public function httpIsAllowedOnlyForLocalhostInDevelopment(): void
    {
        self::assertSame(
            'http://localhost:8088/tycms-catalog/official',
            CatalogSourceUrl::normalize('http://localhost:8088/tycms-catalog/official', true),
        );

        $this->expectException(RuntimeException::class);
        CatalogSourceUrl::normalize('http://localhost:8088/tycms-catalog/official', false);
    }

    #[Test]
    public function queryFragmentAndTraversalAreRejected(): void
    {
        foreach ([
            'https://example.org/catalog?token=1',
            'https://example.org/catalog#part',
            'https://user:pass@example.org/catalog',
            'https://example.org/catalog/../private',
            'ftp://example.org/catalog',
        ] as $url) {
            try {
                CatalogSourceUrl::normalize($url, false);
                self::fail('URL invalide acceptée : ' . $url);
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        }
    }

    #[Test]
    public function licenseOriginComparesSchemeHostAndEffectivePort(): void
    {
        self::assertTrue(CatalogSourceUrl::sameOrigin(
            'https://example.org/tycms-catalog/official',
            'https://example.org/tycms-catalog/official/extensions/plg_lms/download',
        ));
        // Un port explicite égal au port par défaut reste la même origine.
        self::assertTrue(CatalogSourceUrl::sameOrigin('https://example.org', 'https://example.org:443/archive.zip'));

        // Toute autre origine ne doit jamais recevoir la licence.
        self::assertFalse(CatalogSourceUrl::sameOrigin('https://example.org', 'https://cdn.example.net/archive.zip'));
        self::assertFalse(CatalogSourceUrl::sameOrigin('https://example.org', 'http://example.org/archive.zip'));
        self::assertFalse(CatalogSourceUrl::sameOrigin('https://example.org', 'https://example.org:8443/archive.zip'));
        self::assertFalse(CatalogSourceUrl::sameOrigin('http://localhost/tycms-catalog/official', 'http://localhost:8088/archive.zip'));
    }
}
