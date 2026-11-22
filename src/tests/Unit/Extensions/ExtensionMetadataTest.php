<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Extensions;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use YTassy\Tycms\Extensions\ExtensionMetadata;

final class ExtensionMetadataTest extends TestCase
{
    #[Test]
    public function emptySourceVersionsMeansFirstInstallationOnly(): void
    {
        $metadata = $this->metadata(['source_versions' => []]);

        self::assertSame([], ExtensionMetadata::validate($metadata)['source_versions']);
    }

    #[Test]
    public function starAllowsFirstInstallationAndAnyPreviousVersion(): void
    {
        $metadata = $this->metadata(['source_versions' => '*']);

        self::assertSame('*', ExtensionMetadata::validate($metadata)['source_versions']);

        foreach (['*foo', '1.0.0', ''] as $invalid) {
            try {
                ExtensionMetadata::validate($this->metadata(['source_versions' => $invalid]));
                self::fail('Version source invalide acceptée : ' . $invalid);
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        }

        // Un tableau contenant « * » reste une liste de versions valides, donc invalide.
        try {
            ExtensionMetadata::validate($this->metadata(['source_versions' => ['*']]));
            self::fail('Marqueur « * » accepté dans une liste.');
        } catch (RuntimeException) {
            self::assertTrue(true);
        }
    }

    /** @param array<string, mixed> $changes */
    private function metadata(array $changes = []): array
    {
        return array_replace([
            'schema_version' => 1,
            'id' => 'plg_demo',
            'type' => 'plugin',
            'folder' => 'Demo',
            'version' => '0.1.0',
            'source_versions' => [],
            'cms_min' => '1.1.3',
            'cms_max_exclusive' => '1.2.0',
            'php_min' => '8.4.1',
            'php_max_exclusive' => '8.6.0',
        ], $changes);
    }
}
