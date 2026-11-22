<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use RuntimeException;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Core\Helper\SystemUpdate\SystemUpdatePreparationHelper;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class SystemUpdateResetTest extends FrameworkTestCase
{
    private string $root;
    private string $site;
    private const ENTRY = 'update_' . 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' . '.php';
    private const PACKAGE = 'v1_0_0-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/tycms-reset-' . bin2hex(random_bytes(8));
        $this->site = $this->root . '/site';
        $this->put('public/index.php', 'bootstrap');
        $this->put('public/medias/photo.jpg', 'media');
        $this->put('public/.htaccess', 'protection');
        $this->put('public/update_notes.php', 'fichier distinct');
        $this->put('public/' . self::ENTRY, 'lien temporaire');
        $this->put('releases/v1_0_0/configuration.php', 'configuration locale');
        $this->put('extensions/plugin.php', 'extension locale');
        $this->put('var/updates/packages/' . self::PACKAGE . '/package.zip', 'ZIP');
        $this->put('var/updates/packages/' . self::PACKAGE . '/files/updater/index.php', 'extrait');
        $this->put('var/updates/packages/.incoming-' . str_repeat('b', 32) . '/package.part', 'interrompu');
        $this->put('var/updates/.prepare-' . str_repeat('c', 32) . '/previous-engine/index.php', 'ancien moteur');
        $this->put('var/updates/' . str_repeat('e', 32) . '/backup/backup.sql', 'sauvegarde orpheline');
        $this->put('var/updates/' . str_repeat('e', 32) . '/backup/metadata.json', '{}');
        $this->put('var/.state-' . str_repeat('d', 32), 'état temporaire');
        $this->put('var/last_update_data.json', '{}');
        $this->put('updater/index.php', 'source moteur');
        $this->put('public/assets_updater/app.js', 'source assets');
        $this->put('updater_test/index.php', 'moteur de test');
        $this->put('public/assets_updater_test/app.js', 'assets de test');
        $this->state(['status' => 'prepared', 'development' => false]);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
        parent::tearDown();
    }

    private function put(string $relative, string $contents): void
    {
        $path = $this->site . '/' . $relative;
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
        file_put_contents($path, $contents);
    }

    private function state(?array $operation): void
    {
        $this->put('var/releases-states.json', json_encode(['schema_version' => 1, 'active_release' => 'v1_0_0',
            'available_update' => ['version' => '1.1.0'], 'active_operation' => $operation,
            'other_field' => 'conservé'], JSON_THROW_ON_ERROR));
    }

    private function readState(): array
    {
        return json_decode(file_get_contents($this->site . '/var/releases-states.json'), true, 32, JSON_THROW_ON_ERROR);
    }

    /** État d'une opération close, avec sa progression terminale. */
    private function closedState(string $status = 'completed'): void
    {
        $this->put('var/releases-states.json', json_encode(['schema_version' => 1, 'active_release' => 'v1_0_0',
            'available_update' => ['version' => '1.1.0'],
            'active_operation' => ['id' => str_repeat('e', 32), 'status' => $status,
                'source_version' => '1.0.0', 'version' => '1.1.0', 'sha256' => str_repeat('a', 64),
                'package_id' => self::PACKAGE, 'development' => false, 'entrypoint' => self::ENTRY],
            'other_field' => 'conservé'], JSON_THROW_ON_ERROR));
        $this->put('var/last_update_data.json', json_encode([
            'operation_id' => str_repeat('e', 32), 'phase' => 'completed'], JSON_THROW_ON_ERROR));
    }

    private function helper(bool $dev = false): SystemUpdatePreparationHelper
    {
        $app = $this->createApplication();
        $app->getConf()->dev = $dev;
        return new class($app, $this->site) extends SystemUpdatePreparationHelper {
            public bool $failDeletionOnce = false;
            public function __construct(Application $app, private string $root) { parent::__construct($app); }
            protected function installationRoot(): string { return $this->root; }
            protected function runningRelease(): string { return 'v1_0_0'; }
            protected function removeResetTree(string $path): void
            {
                if ($this->failDeletionOnce) {
                    $this->failDeletionOnce = false;
                    throw new RuntimeException('Suppression interrompue pour le test.');
                }
                parent::removeResetTree($path);
            }
        };
    }

    private function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (new \FilesystemIterator($path) as $entry) $this->remove($entry->getPathname());
            rmdir($path);
        } else {
            unlink($path);
        }
    }

    public function testProductionResetDeletesOnlyUpdateArtifactsAndResetsJsonWithoutChangingTheRelease(): void
    {
        self::assertSame(['reset' => true], $this->helper()->reset());
        foreach (['updater', 'public/assets_updater', 'public/' . self::ENTRY,
            'var/updates/packages/' . self::PACKAGE, 'var/updates/' . str_repeat('e', 32),
            'var/.state-' . str_repeat('d', 32)] as $relative) {
            self::assertFileDoesNotExist($this->site . '/' . $relative);
        }
        self::assertSame(['.prepare.lock', 'packages'], $this->names($this->site . '/var/updates'));
        self::assertSame(['.download.lock'], $this->names($this->site . '/var/updates/packages'));
        foreach (['public/index.php' => 'bootstrap', 'public/.htaccess' => 'protection',
            'public/medias/photo.jpg' => 'media', 'public/update_notes.php' => 'fichier distinct',
            'releases/v1_0_0/configuration.php' => 'configuration locale', 'extensions/plugin.php' => 'extension locale'] as $relative => $contents) {
            self::assertSame($contents, file_get_contents($this->site . '/' . $relative));
        }
        self::assertSame(['schema_version' => 1, 'active_release' => 'v1_0_0', 'available_update' => null,
            'active_operation' => null, 'other_field' => 'conservé'], $this->readState());
        self::assertSame("{}\n", file_get_contents($this->site . '/var/last_update_data.json'));

        // Un second reset garde les mêmes inodes : aucun autre processus ne doit contourner les verrous.
        $lockPath = $this->site . '/var/updates/.prepare.lock';
        $inode = fileinode($lockPath);
        $downloadPath = $this->site . '/var/updates/packages/.download.lock';
        $downloadInode = fileinode($downloadPath);
        $this->helper()->reset();
        clearstatcache();
        self::assertSame($inode, fileinode($lockPath));
        self::assertSame($downloadInode, fileinode($downloadPath));
    }

    private function names(string $path): array
    {
        $names = array_values(array_diff(scandir($path), ['.', '..']));
        sort($names);
        return $names;
    }

    public function testDevDeletesTheTestCopiesAndPreservesTheDevelopedEngineAndAssets(): void
    {
        $this->state(['status' => 'preparing', 'development' => true]);
        $this->helper(true)->reset();
        self::assertSame('source moteur', file_get_contents($this->site . '/updater/index.php'));
        self::assertSame('source assets', file_get_contents($this->site . '/public/assets_updater/app.js'));
        self::assertFileDoesNotExist($this->site . '/updater_test');
        self::assertFileDoesNotExist($this->site . '/public/assets_updater_test');
    }

    public function testResetDoesNotFollowLinksOutsideTheOwnedFolders(): void
    {
        mkdir($this->root . '/outside');
        file_put_contents($this->root . '/outside/keep', 'intact');
        symlink($this->root . '/outside', $this->site . '/var/updates/packages/' . self::PACKAGE . '/outside');
        $this->remove($this->site . '/updater_test');
        symlink($this->root . '/outside', $this->site . '/updater_test');
        $this->state(['status' => 'prepared', 'development' => true]);
        $this->helper(true)->reset();
        self::assertSame('intact', file_get_contents($this->root . '/outside/keep'));
        self::assertFalse(is_link($this->site . '/updater_test'));
    }

    public function testResetRefusesConcurrentPreparationAndDownloadWithoutDeletingFiles(): void
    {
        foreach (['var/updates/.prepare.lock', 'var/updates/packages/.download.lock'] as $relative) {
            $lock = fopen($this->site . '/' . $relative, 'c+b');
            flock($lock, LOCK_EX);
            try {
                $this->helper()->reset();
                self::fail('Opération concurrente ignorée');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('en cours', $exception->getMessage());
                self::assertFileExists($this->site . '/public/' . self::ENTRY);
                self::assertSame('prepared', $this->readState()['active_operation']['status']);
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    public function testResetRefusesWhenTheUpdaterHasStarted(): void
    {
        $this->put('var/last_update_data.json', '{"phase":"copying"}');
        try {
            $this->helper()->reset();
            self::fail('Réinitialisation acceptée pendant une opération engagée');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('moteur de mise à jour a commencé', $exception->getMessage());
            self::assertFileExists($this->site . '/public/' . self::ENTRY);
            self::assertSame('prepared', $this->readState()['active_operation']['status']);
        }
    }

    public function testResetRejectsUnknownStatesModeChangesAndPossibleBackups(): void
    {
        foreach ([['status' => 'migrating', 'development' => false],
            ['status' => 'prepared', 'development' => true]] as $operation) {
            $this->state($operation);
            try {
                $this->helper()->reset();
                self::fail('État non admissible accepté');
            } catch (RuntimeException) {
                self::assertSame($operation, $this->readState()['active_operation']);
                self::assertFileExists($this->site . '/public/' . self::ENTRY);
            }
        }
        $this->state(null);
        $this->put('var/updates/backup/backup.sql', 'sauvegarde');
        try {
            $this->helper()->reset();
            self::fail('Sauvegarde supprimée');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('non reconnus', $exception->getMessage());
            self::assertSame('sauvegarde', file_get_contents($this->site . '/var/updates/backup/backup.sql'));
            self::assertNull($this->readState()['active_operation']);
        }
    }

    public function testResetRefusesUnknownContentInAnOperationDirectory(): void
    {
        $operation = $this->site . '/var/updates/' . str_repeat('f', 32);
        $this->put('var/updates/' . str_repeat('f', 32) . '/backup/backup.sql', 'sauvegarde');
        $this->put('var/updates/' . str_repeat('f', 32) . '/inconnu.txt', 'contenu');
        try {
            $this->helper()->reset();
            self::fail('Dossier d’opération inconnu supprimé');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('incomplet', $exception->getMessage());
            self::assertSame('sauvegarde', file_get_contents($operation . '/backup/backup.sql'));
        }
    }

    public function testResetRemovesAnEmptyOperationDirectoryLeftAfterCleanup(): void
    {
        $operation = $this->site . '/var/updates/' . str_repeat('a', 32);
        mkdir($operation, 0700, true);

        self::assertSame(['reset' => true], $this->helper()->reset());
        self::assertDirectoryDoesNotExist($operation);
    }

    public function testResetRemovesTheWorkStageOfAnAbandonedOperation(): void
    {
        $operation = $this->site . '/var/updates/' . str_repeat('a', 32);
        $this->put('var/updates/' . str_repeat('a', 32) . '/stage/release/index.php', 'copie préparée');
        $this->put('var/updates/' . str_repeat('a', 32) . '/backup/backup.sql', 'sauvegarde');

        self::assertSame(['reset' => true], $this->helper()->reset());
        self::assertDirectoryDoesNotExist($operation);
    }

    public function testResetRefusesAClosedOperationAndKeepsItsBackup(): void
    {
        $backup = $this->site . '/var/updates/' . str_repeat('e', 32) . '/backup/backup.sql';
        $this->state(['status' => 'completed', 'development' => false]);
        try {
            $this->helper()->reset();
            self::fail('Opération close supprimée par le reset');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('dépassé la préparation', $exception->getMessage());
            self::assertSame('sauvegarde orpheline', file_get_contents($backup));
        }
    }

    public function testCleanupRemovesOldVersionsAndKeepsTheActiveRelease(): void
    {
        $this->closedState();
        $this->put('releases/v0_9_0/configuration.php', 'ancienne configuration');
        $this->put('public/assets_core_v0_9_0/index.js', 'anciens assets');
        $this->put('public/assets_core_v1_0_0/index.js', 'assets actifs');
        $this->put('releases/vdev/index.php', 'développement');
        $this->put('public/assets_core_vdev/index.js', 'assets de développement');

        self::assertSame(['cleaned' => true], $this->helper()->cleanup());

        foreach (['public/' . self::ENTRY, 'var/updates/packages/' . self::PACKAGE,
            'updater', 'public/assets_updater', 'var/updates/' . str_repeat('e', 32)] as $relative) {
            self::assertFileDoesNotExist($this->site . '/' . $relative);
        }
        self::assertFileExists($this->site . '/var/updates/.prepare-' . str_repeat('c', 32) . '/previous-engine/index.php');
        self::assertSame('moteur de test', file_get_contents($this->site . '/updater_test/index.php'));
        self::assertSame('configuration locale', file_get_contents($this->site . '/releases/v1_0_0/configuration.php'));
        self::assertDirectoryDoesNotExist($this->site . '/releases/v0_9_0');
        self::assertDirectoryDoesNotExist($this->site . '/public/assets_core_v0_9_0');
        self::assertFileExists($this->site . '/public/assets_core_v1_0_0/index.js');
        self::assertFileExists($this->site . '/releases/vdev/index.php');
        self::assertFileExists($this->site . '/public/assets_core_vdev/index.js');
        self::assertSame(['schema_version' => 1, 'active_release' => 'v1_0_0', 'available_update' => null,
            'active_operation' => null, 'other_field' => 'conservé'], $this->readState());
        self::assertSame("{}\n", file_get_contents($this->site . '/var/last_update_data.json'));
    }

    public function testCleanupRefusesMismatchedActiveReleaseOrSymbolicVersion(): void
    {
        $this->closedState();
        $state = $this->readState();
        $state['active_release'] = 'v9_9_9';
        $this->put('var/releases-states.json', json_encode($state, JSON_THROW_ON_ERROR));
        try {
            $this->helper()->cleanup();
            self::fail('Release active incohérente acceptée.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('active incohérente', $error->getMessage());
            self::assertFileExists($this->site . '/public/' . self::ENTRY);
        }

        $this->closedState();
        symlink($this->root, $this->site . '/releases/v0_9_0');
        try {
            $this->helper()->cleanup();
            self::fail('Lien de version accepté.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('Ancienne version invalide', $error->getMessage());
            self::assertFileExists($this->site . '/public/' . self::ENTRY);
        }
    }

    public function testDevelopmentCleanupAlsoRemovesOtherNumberedVersions(): void
    {
        $this->closedState();
        $state = $this->readState();
        $state['active_operation']['development'] = true;
        $this->put('var/releases-states.json', json_encode($state, JSON_THROW_ON_ERROR));
        $this->put('releases/v0_9_0/index.php', 'ancienne release');
        $this->put('public/assets_core_v0_9_0/index.js', 'anciens assets');
        $this->put('releases/vdev/index.php', 'développement');
        $this->put('public/assets_core_vdev/index.js', 'assets de développement');

        self::assertSame(['cleaned' => true], $this->helper(true)->cleanup());
        self::assertDirectoryDoesNotExist($this->site . '/releases/v0_9_0');
        self::assertDirectoryDoesNotExist($this->site . '/public/assets_core_v0_9_0');
        self::assertFileExists($this->site . '/releases/vdev/index.php');
        self::assertFileExists($this->site . '/public/assets_core_vdev/index.js');
        self::assertFileExists($this->site . '/releases/v1_0_0/configuration.php');
    }

    public function testCleanupRefusesUnknownContentInTheOperationDirectory(): void
    {
        $this->closedState();
        $this->put('var/updates/' . str_repeat('e', 32) . '/inconnu.txt', 'contenu');

        try {
            $this->helper()->cleanup();
            self::fail('Contenu inconnu supprimé par le nettoyage');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('incomplet', $exception->getMessage());
            self::assertSame('sauvegarde orpheline', file_get_contents(
                $this->site . '/var/updates/' . str_repeat('e', 32) . '/backup/backup.sql'));
            self::assertFileExists($this->site . '/public/' . self::ENTRY);
            self::assertSame('completed', $this->readState()['active_operation']['status']);
        }
    }

    public function testCleanupIsAvailableOnlyForClosedOperations(): void
    {
        self::assertFalse($this->helper()->cleanupAvailable());
        $this->closedState();
        self::assertTrue($this->helper()->cleanupAvailable());
        $this->closedState('prepared');
        self::assertFalse($this->helper()->cleanupAvailable());
    }

    public function testFailedDeletionRevokesTheLinkAndTheSameResetCanResume(): void
    {
        $helper = $this->helper();
        $helper->failDeletionOnce = true;
        try {
            $helper->reset();
            self::fail('Suppression interrompue ignorée');
        } catch (RuntimeException) {
            self::assertSame('resetting', $this->readState()['active_operation']['status']);
            self::assertFileExists($this->site . '/public/' . self::ENTRY);
        }
        $helper->reset();
        self::assertNull($this->readState()['active_operation']);
        self::assertFileDoesNotExist($this->site . '/public/' . self::ENTRY);
    }

    public function testASymlinkInTheStorageParentsIsRejected(): void
    {
        $this->remove($this->site . '/var/updates/packages');
        mkdir($this->root . '/outside');
        file_put_contents($this->root . '/outside/keep', 'intact');
        symlink($this->root . '/outside', $this->site . '/var/updates/packages');
        try {
            $this->helper()->reset();
            self::fail('Parent symbolique suivi');
        } catch (RuntimeException) {
            self::assertSame('intact', file_get_contents($this->root . '/outside/keep'));
            self::assertSame('prepared', $this->readState()['active_operation']['status']);
        }
    }
}
