<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Tycms\Updater\OperationStore;
use Tycms\Updater\UpdaterService;

require_once __DIR__ . '/../../../updater/UpdaterService.php';
require_once __DIR__ . '/../../../updater/Lib/MariaDbBackup/DatabaseBackup.php';

final class UpdaterPreparationTest extends TestCase
{
    private string $root;
    private string $entry;
    private string $package;
    private UpdaterService $service;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/tycms-preparation-' . bin2hex(random_bytes(8));
        foreach (['', '/var', '/var/updates', '/var/updates/packages', '/public', '/releases', '/releases/v1_0_0'] as $path) mkdir($this->root . $path);
        $this->entry = 'update_' . str_repeat('a', 64) . '.php';
        file_put_contents($this->root . '/public/' . $this->entry, '<?php');
        // La configuration locale reste copiée ; chaque release fournit sa propre base.
        copy(APP_ROOT . '/releases/vdev/configuration.base.php', $this->root . '/releases/v1_0_0/configuration.base.php');
        file_put_contents($this->root . '/releases/v1_0_0/configuration.php', "<?php\nrequire_once __DIR__ . '/configuration.base.php';\nclass Configuration extends ConfigurationBase { public \$offline = false; }\n");
        $zipPath = $this->root . '/package.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::EXCL));
        foreach (['releases/v1_1_0/index.php', 'releases/v1_1_0/version.php', 'releases/v1_1_0/vendor/autoload.php',
            'public/assets_core_v1_1_0/main.js'] as $name) $zip->addFromString($name, 'fixture ' . $name);
        // Simuler une nouvelle option livrée uniquement avec la base cible.
        $targetBase = str_replace('public const CURRENT_CONFIG_VERSION = 1;',
            "public const CURRENT_CONFIG_VERSION = 1;\n    public string \$new_release_option = 'inherited';",
            file_get_contents(APP_ROOT . '/releases/vdev/configuration.base.php'));
        $zip->addFromString('releases/v1_1_0/configuration.base.php', $targetBase);
        for ($i = 0; $i < 50; ++$i) $zip->addFromString('releases/v1_1_0/framework/file' . $i . '.php', 'file ' . $i);
        $zip->close();
        $sha = hash_file('sha256', $zipPath);
        $this->package = $this->root . '/var/updates/packages/v1_1_0-' . $sha;
        mkdir($this->package);
        rename($zipPath, $this->package . '/package.zip');
        mkdir($this->package . '/files');
        file_put_contents($this->package . '/files/extracted.txt', 'fixture');
        file_put_contents($this->root . '/var/releases-states.json', json_encode([
            'schema_version' => 1, 'active_release' => 'v1_0_0', 'available_update' => [],
            'active_operation' => ['id' => str_repeat('c', 32), 'status' => 'prepared', 'entrypoint' => $this->entry,
                'source_version' => '1.0.0', 'version' => '1.1.0', 'development' => false,
                'sha256' => $sha, 'package_id' => 'v1_1_0-' . $sha],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($this->root . '/var/last_update_data.json', '{}');
        $this->service = new UpdaterService(new OperationStore($this->root));
    }

    public function testSeparatePreparationMaintenanceAndCancellation(): void
    {
        try {
            $this->service->dispatch($this->entry, 'maintenance');
            self::fail('Maintenance prématurée acceptée.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('Préparez les fichiers', $error->getMessage());
        }
        self::assertSame('ready', $this->service->dispatch($this->entry, 'prepare')['phase']);
        self::assertFileExists($this->root . '/releases/v1_1_0/framework/file49.php');
        self::assertFileExists($this->root . '/releases/v1_1_0/configuration.base.php');
        self::assertSame(file_get_contents($this->root . '/releases/v1_0_0/configuration.php'), file_get_contents($this->root . '/releases/v1_1_0/configuration.php'));
        self::assertStringContainsString('offline = false', file_get_contents($this->root . '/releases/v1_0_0/configuration.php'));
        self::assertSame('offline', $this->service->dispatch($this->entry, 'maintenance')['phase']);
        self::assertStringContainsString('offline = true', file_get_contents($this->root . '/releases/v1_0_0/configuration.php'));
        self::assertStringContainsString('offline = true', file_get_contents($this->root . '/releases/v1_1_0/configuration.php'));
        self::assertSame('cancelled', $this->service->dispatch($this->entry, 'cancel')['phase']);
        self::assertStringContainsString('offline = false', file_get_contents($this->root . '/releases/v1_0_0/configuration.php'));
        self::assertDirectoryDoesNotExist($this->root . '/releases/v1_1_0');
        self::assertDirectoryDoesNotExist($this->root . '/public/assets_core_v1_1_0');
        self::assertDirectoryDoesNotExist($this->package . '/files');
        self::assertFileDoesNotExist($this->root . '/public/' . $this->entry);
        $state = json_decode(file_get_contents($this->root . '/var/releases-states.json'), true);
        self::assertSame('v1_0_0', $state['active_release']);
        self::assertNull($state['active_operation']);
        self::assertSame("{}\n", file_get_contents($this->root . '/var/last_update_data.json'));
    }

    public function testCopiedLocalConfigurationUsesTheNewBaseAndKeepsMaintenance(): void
    {
        $this->service->dispatch($this->entry, 'prepare');
        $this->service->dispatch($this->entry, 'maintenance');

        // Charger la cible dans un processus isolé pour ne pas réutiliser la classe des tests.
        $code = 'define("APP_ROOT", $argv[1]); '
            . 'require $argv[1] . "/releases/v1_1_0/configuration.php"; '
            . '$config = new Configuration(); '
            . 'echo json_encode([$config->new_release_option, $config->offline, $config->configVersion]);';
        $process = proc_open([PHP_BINARY, '-r', $code, $this->root],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame(0, proc_close($process), $errors);
        self::assertSame(['inherited', true, 1], json_decode($output, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testCancellationPreservesPreviouslyOfflineSite(): void
    {
        file_put_contents($this->root . '/releases/v1_0_0/configuration.php', "<?php class Configuration { public \$offline = true; }\n");
        $this->service->dispatch($this->entry, 'prepare');
        $this->service->dispatch($this->entry, 'maintenance');
        $this->service->dispatch($this->entry, 'cancel');
        self::assertStringContainsString('offline = true', file_get_contents($this->root . '/releases/v1_0_0/configuration.php'));
    }

    public function testExistingTargetIsNotRemovedWhenCancellingBeforePreparation(): void
    {
        mkdir($this->root . '/releases/v1_1_0');
        file_put_contents($this->root . '/releases/v1_1_0/keep', 'unrelated');
        try {
            $this->service->dispatch($this->entry, 'prepare');
            self::fail('Cible existante acceptée.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('existe déjà', $error->getMessage());
        }
        $this->service->dispatch($this->entry, 'cancel');
        self::assertSame('unrelated', file_get_contents($this->root . '/releases/v1_1_0/keep'));
    }

    public function testChangedPackageIsRejected(): void
    {
        file_put_contents($this->package . '/package.zip', 'corruption');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('paquet préparé a changé');
        $this->service->dispatch($this->entry, 'prepare');
    }

    public function testInterruptedPublicationCanFinishWithoutReplacingOtherFiles(): void
    {
        $this->service->dispatch($this->entry, 'prepare');
        $progressPath = $this->root . '/var/last_update_data.json';
        $progress = json_decode(file_get_contents($progressPath), true);
        $progress['phase'] = 'publishing';
        file_put_contents($progressPath, json_encode($progress));
        self::assertSame('ready', $this->service->dispatch($this->entry, 'prepare')['phase']);
        self::assertFileExists($this->root . '/releases/v1_1_0/framework/file49.php');
    }

    public function testCancellationRefusesAnUnownedReplacement(): void
    {
        $this->service->dispatch($this->entry, 'prepare');
        $target = $this->root . '/releases/v1_1_0';
        unlink($target . '/.tycms-update-owner');
        try {
            $this->service->dispatch($this->entry, 'cancel');
            self::fail('Suppression de fichiers non reconnus acceptée.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('ne correspondent pas', $error->getMessage());
        }
        self::assertFileExists($target . '/framework/file49.php');
        self::assertSame('cancelling', $this->service->dispatch($this->entry, 'status')['phase']);
    }

    public function testInterruptedMaintenanceCanBeCancelledSafely(): void
    {
        $this->service->dispatch($this->entry, 'prepare');
        $progressPath = $this->root . '/var/last_update_data.json';
        $progress = json_decode(file_get_contents($progressPath), true);
        $progress['source_offline'] = false;
        $progress['phase'] = 'maintenance_running';
        file_put_contents($progressPath, json_encode($progress));
        file_put_contents($this->root . '/releases/v1_0_0/configuration.php', "<?php class Configuration { public \$offline = true; }\n");
        self::assertSame('cancelled', $this->service->dispatch($this->entry, 'cancel')['phase']);
        self::assertStringContainsString('offline = false', file_get_contents($this->root . '/releases/v1_0_0/configuration.php'));
    }

    public function testMaintenanceRefusesAChangedSourceConfiguration(): void
    {
        $this->service->dispatch($this->entry, 'prepare');
        file_put_contents($this->root . '/releases/v1_0_0/configuration.php', "<?php class Configuration { public \$offline = false; public \$other = 1; }\n");
        try {
            $this->service->dispatch($this->entry, 'maintenance');
            self::fail('Configuration source modifiée acceptée.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('configuration a changé', $error->getMessage());
        }
        self::assertStringContainsString('offline = false', file_get_contents($this->root . '/releases/v1_0_0/configuration.php'));
        self::assertSame('ready', $this->service->dispatch($this->entry, 'status')['phase']);
    }

    public function testSkipBackupIsExplicitAndMigrationCannotBeRelaunched(): void
    {
        $this->service->dispatch($this->entry, 'prepare');
        $this->service->dispatch($this->entry, 'maintenance');
        try {
            $this->service->dispatch($this->entry, 'migrate');
            self::fail('Migration acceptée sans décision de sauvegarde.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('ne peuvent pas', $error->getMessage());
        }
        $skipped = $this->service->dispatch($this->entry, 'skip_backup');
        self::assertSame('backup_skipped', $skipped['phase']);
        self::assertSame('skipped', $skipped['backup']);
        $path = $this->root . '/var/last_update_data.json';
        $progress = json_decode(file_get_contents($path), true);
        $progress['phase'] = 'migrating';
        file_put_contents($path, json_encode($progress));
        foreach (['migrate', 'cancel'] as $action) {
            try {
                $this->service->dispatch($this->entry, $action);
                self::fail('Action dangereuse acceptée pendant les migrations.');
            } catch (RuntimeException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
        self::assertSame('migrating', $this->service->dispatch($this->entry, 'status')['phase']);
        self::assertSame('skipped', $this->service->dispatch($this->entry, 'status')['backup']);
        self::assertStringContainsString('offline = true', file_get_contents($this->root . '/releases/v1_0_0/configuration.php'));
    }

    public function testCorruptedBackupBlocksMigrationAndCancellationPreservesIt(): void
    {
        $this->service->dispatch($this->entry, 'prepare');
        $this->service->dispatch($this->entry, 'maintenance');
        $directory = $this->root . '/var/updates/' . str_repeat('c', 32) . '/backup';
        mkdir($directory, 0700);
        file_put_contents($directory . '/backup.jsonl', 'altered');
        $path = $this->root . '/var/last_update_data.json';
        $progress = json_decode(file_get_contents($path), true);
        $progress['phase'] = 'backed_up';
        $progress['backup_size'] = 7;
        $progress['backup_sha256'] = str_repeat('d', 64);
        $progress['database_identity'] = str_repeat('e', 64);
        file_put_contents($path, json_encode($progress));
        try {
            $this->service->dispatch($this->entry, 'migrate');
            self::fail('Sauvegarde altérée acceptée.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('altérée', $error->getMessage());
        }
        self::assertSame('backed_up', $this->service->dispatch($this->entry, 'status')['phase']);
        self::assertSame('completed', $this->service->dispatch($this->entry, 'status')['backup']);
        $progress['phase'] = 'migration_failed';
        file_put_contents($path, json_encode($progress));
        self::assertSame('completed', $this->service->dispatch($this->entry, 'status')['backup']);
        $progress['phase'] = 'backed_up';
        file_put_contents($path, json_encode($progress));
        self::assertSame('cancelled', $this->service->dispatch($this->entry, 'cancel')['phase']);
        self::assertSame('altered', file_get_contents($directory . '/backup.jsonl'));
    }

    public function testPartialBackupCannotTriggerAutomaticRestore(): void
    {
        $this->service->dispatch($this->entry, 'prepare');
        $this->service->dispatch($this->entry, 'maintenance');
        $path = $this->root . '/var/last_update_data.json';
        $progress = json_decode(file_get_contents($path), true);
        $progress['phase'] = 'migration_failed';
        $progress['backup_sha256'] = str_repeat('d', 64);
        $progress['backup_complete'] = false;
        $progress['backup_missing_privileges'] = ['TRIGGER'];
        file_put_contents($path, json_encode($progress));

        $status = $this->service->dispatch($this->entry, 'status');
        self::assertSame('partial', $status['backup']);
        self::assertSame(['TRIGGER'], $status['backup_missing_privileges']);
        try {
            $this->service->dispatch($this->entry, 'restore');
            self::fail('Restauration automatique partielle acceptée.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('partielle', $error->getMessage());
        }
        self::assertSame('migration_failed', $this->service->dispatch($this->entry, 'status')['phase']);
    }

    public function testPartialArchiveIsRejectedEvenWithSufficientRestoreRights(): void
    {
        $class = new ReflectionClass(\Tycms\Updater\Lib\MariaDbBackup\DatabaseBackup::class);
        $backup = $class->newInstanceWithoutConstructor();
        $class->getProperty('database')->setValue($backup, 'fixture');
        $file = tmpfile();
        fwrite($file, json_encode(['type' => 'header', 'format' => 'tycms-mariadb-backup',
            'version' => 1, 'database' => base64_encode('fixture'), 'complete' => false], JSON_THROW_ON_ERROR) . "\n");
        rewind($file);
        try {
            $class->getMethod('validateArchive')->invoke($backup, $file);
            self::fail('Archive partielle acceptée pour restauration.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('partielle', $error->getMessage());
        } finally {
            fclose($file);
        }
    }

    protected function tearDown(): void
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($this->root);
    }
}
