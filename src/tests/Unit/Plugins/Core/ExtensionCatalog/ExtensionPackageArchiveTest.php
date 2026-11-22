<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\ExtensionCatalog;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use YTassy\Tycms\Extensions\ExtensionMetadata;
use YTassy\Tycms\Extensions\ExtensionPackage;
use ZipArchive;

final class ExtensionPackageArchiveTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/tycms-archive-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700));
    }

    protected function tearDown(): void
    {
        $this->remove($this->directory);
    }

    #[Test]
    public function validArchiveIsAccepted(): void
    {
        $metadata = $this->metadata();
        $archive = $this->buildArchive();
        $manifest = $this->manifestFor($archive, $metadata);

        ExtensionPackage::inspectArchive($archive, $manifest);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function archiveSizeMismatchIsRejected(): void
    {
        $archive = $this->buildArchive();
        $manifest = $this->manifestFor($archive, $this->metadata(), 'Notes', [
            'archive' => ['bytes' => filesize($archive) + 1],
        ]);

        $this->expectException(RuntimeException::class);
        ExtensionPackage::inspectArchive($archive, $manifest);
    }

    #[Test]
    public function archiveHashMismatchIsRejected(): void
    {
        $archive = $this->buildArchive();
        $manifest = $this->manifestFor($archive, $this->metadata(), 'Notes', [
            'archive' => ['sha256' => str_repeat('b', 64)],
        ]);

        $this->expectException(RuntimeException::class);
        ExtensionPackage::inspectArchive($archive, $manifest);
    }

    #[Test]
    public function notesMismatchIsRejected(): void
    {
        $archive = $this->buildArchive(notes: 'Tampered');
        $manifest = $this->manifestFor($archive, $this->metadata(), 'Notes');

        $this->expectException(RuntimeException::class);
        ExtensionPackage::inspectArchive($archive, $manifest);
    }

    #[Test]
    public function internalVersionMismatchIsRejected(): void
    {
        $metadata = $this->metadata();
        $other = $metadata;
        $other['version'] = '0.1.1';
        $archive = $this->buildArchive(versionMetadata: $other);
        $manifest = $this->manifestFor($archive, $metadata);

        $this->expectException(RuntimeException::class);
        ExtensionPackage::inspectArchive($archive, $manifest);
    }

    #[Test]
    public function executableAssetIsRejected(): void
    {
        $archive = $this->buildArchive(['assets/evil.php' => '<?php // interdit']);
        $manifest = $this->manifestFor($archive, $this->metadata());

        $this->expectException(RuntimeException::class);
        ExtensionPackage::inspectArchive($archive, $manifest);
    }

    #[Test]
    public function unknownRootIsRejected(): void
    {
        $archive = $this->buildArchive(['other/file.txt' => 'interdit']);
        $manifest = $this->manifestFor($archive, $this->metadata());

        $this->expectException(RuntimeException::class);
        ExtensionPackage::inspectArchive($archive, $manifest);
    }

    #[Test]
    public function traversalPathIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        ExtensionPackage::safePath('extension/../evil.php');
    }

    /** @return array<string, mixed> */
    private function metadata(): array
    {
        return ExtensionMetadata::validate([
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
    }

    /** @param array<string, string> $extraEntries */
    private function buildArchive(
        array $extraEntries = [],
        string $notes = 'Notes',
        ?array $versionMetadata = null,
    ): string {
        $metadata = $versionMetadata ?? $this->metadata();
        $archive = $this->directory . '/package-' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE));

        $entries = [
            'extension/version.json' => json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'extension/version.md' => $notes,
            'extension/Plugin.php' => '<?php // plugin',
        ] + $extraEntries;

        foreach ($entries as $name => $contents) {
            self::assertTrue($zip->addFromString($name, $contents));
        }
        $zip->close();

        return $archive;
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function manifestFor(
        string $archive,
        array $metadata,
        string $notes = 'Notes',
        array $overrides = [],
    ): array {
        $base = ExtensionMetadata::artifactName($metadata);
        $manifest = [
            'schema_version' => 1,
            'artifact_type' => 'tycms-extension',
            'metadata' => $metadata,
            'frontend_entry' => null,
            'archive' => [
                'filename' => $base . '.zip',
                'bytes' => filesize($archive),
                'sha256' => hash_file('sha256', $archive),
            ],
            'release_notes' => [
                'filename' => $base . '.md',
                'bytes' => strlen($notes),
                'sha256' => hash('sha256', $notes),
            ],
        ];

        return ExtensionPackage::manifest(array_replace_recursive($manifest, $overrides));
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (is_dir($path)) {
            foreach (new \FilesystemIterator($path) as $entry) {
                $this->remove($entry->getPathname());
            }
            @rmdir($path);
        }
    }
}
