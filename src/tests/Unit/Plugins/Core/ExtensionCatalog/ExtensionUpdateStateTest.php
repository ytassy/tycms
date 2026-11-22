<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\ExtensionCatalog;

use PHPUnit\Framework\Attributes\Test;
use YTassy\Tycms\CoreExts\Core\Helper\Extension\ExtensionUpdateState;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class ExtensionUpdateStateTest extends FrameworkTestCase
{
    #[Test]
    public function pendingStateSurvivesBetweenRequestsAndCanBeDeleted(): void
    {
        $application = $this->createApplication();
        $state = new ExtensionUpdateState($application);
        $identifier = 'plg_teststate_' . bin2hex(random_bytes(4));
        $operationId = bin2hex(random_bytes(16));
        $directory = $state->directory() . '/.update-' . $operationId;

        try {
            self::assertTrue(mkdir($directory, 0700));
            self::assertTrue(mkdir($directory . '/previous-extension', 0700));

            $state->write($identifier, [
                'schema_version' => 1,
                'operation_id' => $operationId,
                'extension_id' => $identifier,
                'type' => 'plugin',
                'folder' => 'TestState',
                'from_version' => '0.1.0',
                'to_version' => '0.2.0',
                'source_id' => 1,
                'source_name' => 'Test',
                'source_url' => 'https://example.org/catalog',
                'key_id' => 'extensions-test',
                'archive_sha256' => str_repeat('a', 64),
                'backup_directory' => $directory,
                'status' => 'files_updated',
                'warning' => null,
                'created' => gmdate('c'),
            ]);

            $pending = $state->pending($identifier);
            self::assertNotNull($pending);
            self::assertSame($operationId, $pending['operation_id']);
            self::assertSame('0.1.0', $pending['from_version']);
            self::assertSame('0.2.0', $pending['to_version']);

            $state->delete($identifier);
            self::assertNull($state->pending($identifier));
        } finally {
            try {
                $state->delete($identifier);
            } catch (\Throwable) {
                // Nettoyage de test uniquement.
            }
            $this->remove($directory);
        }
    }

    #[Test]
    public function missingStateIsNull(): void
    {
        $state = new ExtensionUpdateState($this->createApplication());
        self::assertNull($state->pending('plg_absent_' . bin2hex(random_bytes(4))));
    }

    #[Test]
    public function identifierTraversalIsRejected(): void
    {
        $state = new ExtensionUpdateState($this->createApplication());

        $this->expectException(\RuntimeException::class);
        $state->pending('../evil');
    }

    #[Test]
    public function backupDirectoryMustMatchTheOperation(): void
    {
        $state = new ExtensionUpdateState($this->createApplication());
        $identifier = 'plg_invalidstate_' . bin2hex(random_bytes(4));
        $operationId = bin2hex(random_bytes(16));
        $foreignDirectory = sys_get_temp_dir() . '/tycms-foreign-backup-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($foreignDirectory, 0700));

        try {
            $state->write($identifier, [
                'schema_version' => 1,
                'operation_id' => $operationId,
                'extension_id' => $identifier,
                'type' => 'plugin',
                'folder' => 'TestState',
                'from_version' => '0.1.0',
                'to_version' => '0.2.0',
                'source_id' => 1,
                'source_name' => 'Test',
                'source_url' => 'https://example.org/catalog',
                'key_id' => 'extensions-test',
                'archive_sha256' => str_repeat('a', 64),
                'backup_directory' => $foreignDirectory,
                'status' => 'files_updated',
                'warning' => null,
                'created' => gmdate('c'),
            ]);

            $this->expectException(\RuntimeException::class);
            $state->pending($identifier);
        } finally {
            try {
                $state->delete($identifier);
            } catch (\Throwable) {
                // Nettoyage de test uniquement.
            }
            $this->remove($foreignDirectory);
        }
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
