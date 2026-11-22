<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\ExtensionCatalog;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\CoreExts\Core\Helper\Extension\CatalogCompatibility;

final class CatalogCompatibilityTest extends TestCase
{
    #[Test]
    public function sourceVersionsAcceptStarListAndRejectEmptyForUpdates(): void
    {
        self::assertTrue(CatalogCompatibility::sourceAllows('*', '0.4.0'));
        self::assertTrue(CatalogCompatibility::sourceAllows(['0.3.0', '0.4.0'], '0.4.0'));
        self::assertFalse(CatalogCompatibility::sourceAllows(['0.3.0'], '0.4.0'));
        self::assertFalse(CatalogCompatibility::sourceAllows([], '0.4.0'));
        self::assertFalse(CatalogCompatibility::sourceAllows(null, '0.4.0'));
    }

    #[Test]
    public function cmsAndPhpBoundsAreBothRequired(): void
    {
        $metadata = $this->metadata();

        self::assertTrue(CatalogCompatibility::compatible($metadata, '1.1.3', '8.4.1'));
        self::assertFalse(CatalogCompatibility::compatible($metadata, '1.2.0', '8.4.1'));
        self::assertFalse(CatalogCompatibility::compatible($metadata, '1.1.3', '8.6.0'));
    }

    #[Test]
    public function devReleaseIsNotEvaluated(): void
    {
        self::assertNull(CatalogCompatibility::compatible($this->metadata(), 'dev', '8.4.1'));
    }

    /** @return array<string, string> */
    private function metadata(): array
    {
        return [
            'cms_min' => '1.1.3',
            'cms_max_exclusive' => '1.2.0',
            'php_min' => '8.4.1',
            'php_max_exclusive' => '8.6.0',
        ];
    }
}
