<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\ExtensionCatalog;

use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use YTassy\Tycms\CoreExts\Core\Helper\Extension\CatalogSourceClient;
use YTassy\Tycms\CoreExts\Core\Models\CatalogSource;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

/**
 * Canal demandé à un catalogue d'extensions.
 *
 * Le client ne doit jamais demander la bêta sans réglage explicite : une source
 * laissée par défaut ne reçoit que les versions release.
 */
final class CatalogSourceChannelTest extends FrameworkTestCase
{
    private function urls(CatalogSource $source): array
    {
        $app = $this->createApplication();
        $client = new CatalogSourceClient($app);
        $versions = new ReflectionMethod($client, 'versionsUrl');
        $version = new ReflectionMethod($client, 'versionUrl');

        return [$versions->invoke($client, $source, 'plg_lms'),
            $version->invoke($client, $source, 'plg_lms', '0.1.0')];
    }

    private function source(string $channel): CatalogSource
    {
        $app = $this->createApplication();
        $source = new CatalogSource($app);
        $source->base_url = 'https://catalog.example.test/';
        $source->channel = $channel;

        return $source;
    }

    #[Test]
    public function aSourceLeftByDefaultAsksOnlyForRelease(): void
    {
        [$versions, $version] = $this->urls($this->source('release'));

        self::assertStringContainsString('channel=release', $versions);
        self::assertStringContainsString('channel=release', $version);
        self::assertStringNotContainsString('beta', $versions . $version);
    }

    #[Test]
    public function aSourceSetToBetaAsksForTheBetaChannel(): void
    {
        [$versions, $version] = $this->urls($this->source('beta'));

        self::assertStringContainsString('channel=beta', $versions);
        self::assertStringContainsString('channel=beta', $version);
    }

    #[Test]
    public function anUnexpectedChannelValueFallsBackToRelease(): void
    {
        // Une base modifiée à la main ne doit ni échouer ni servir une bêta.
        [$versions, $version] = $this->urls($this->source('canal'));

        self::assertStringContainsString('channel=release', $versions);
        self::assertStringContainsString('channel=release', $version);
    }

    #[Test]
    public function theBaseUrlKeepsNoTrailingSlash(): void
    {
        [$versions] = $this->urls($this->source('release'));

        self::assertStringStartsWith('https://catalog.example.test/extensions/plg_lms/versions', $versions);
        self::assertStringNotContainsString('.test//', $versions);
    }
}
