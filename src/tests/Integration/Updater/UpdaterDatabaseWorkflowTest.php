<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Parcours SQL exécuté par des requêtes PHP distinctes et sur une base jetable. */
final class UpdaterDatabaseWorkflowTest extends TestCase
{
    private string $root;
    private string $entry;
    private PDO $pdo;
    private bool $ownsFixture = false;

    protected function setUp(): void
    {
        $name = getenv('TYCMS_BACKUP_TEST_DB');
        $user = getenv('TYCMS_BACKUP_TEST_USER');
        $password = getenv('TYCMS_BACKUP_TEST_PASSWORD');
        if (!is_string($name) || preg_match('/\Atycms_test_backup_[a-f0-9]{16}\z/', $name) !== 1
            || !is_string($user) || preg_match('/\Abak_[a-f0-9]{16}\z/', $user) !== 1 || !is_string($password) || $password === '') {
            self::markTestSkipped('Base MariaDB temporaire dédiée requise.');
        }
        $this->pdo = new PDO('mysql:host=mariadb;dbname=' . $name . ';charset=utf8mb4', $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchColumn());
        $this->ownsFixture = true;
        $this->pdo->exec('CREATE TABLE source_data (id INT PRIMARY KEY) ENGINE=InnoDB');
        $this->pdo->exec('INSERT INTO source_data VALUES (42)');
        $this->root = sys_get_temp_dir() . '/tycms-workflow-' . bin2hex(random_bytes(8));
        foreach (['', '/var', '/var/updates', '/public', '/releases', '/releases/v1_0_0', '/releases/v1_1_0', '/releases/v1_1_0/vendor',
            '/public/assets_core_v1_1_0', '/var/updates/' . str_repeat('c', 32), '/var/updates/packages',
            '/var/updates/packages/v1_1_0-' . str_repeat('b', 64), '/var/updates/packages/v1_1_0-' . str_repeat('b', 64) . '/files'] as $path) mkdir($this->root . $path, 0700);
        $this->entry = 'update_' . str_repeat('a', 64) . '.php';
        file_put_contents($this->root . '/public/' . $this->entry, '<?php');
        $config = <<<'PHP_CONFIG'
<?php
class Configuration {
    public $offline = true;
    public $RESET_DATABASE = false;
    public $db_type = 'mysqli';
    public $db_host = 'mariadb';
    public $db_dbname;
    public $db_user;
    public $db_password;
    public $db_encryption = 0;
    public function __construct() {
        $this->db_dbname = getenv('TYCMS_BACKUP_TEST_DB');
        $this->db_user = getenv('TYCMS_BACKUP_TEST_USER');
        $this->db_password = getenv('TYCMS_BACKUP_TEST_PASSWORD');
    }
}
PHP_CONFIG;
        file_put_contents($this->root . '/releases/v1_0_0/configuration.php', $config);
        file_put_contents($this->root . '/releases/v1_1_0/configuration.php', $config);
        file_put_contents($this->root . '/releases/v1_1_0/version.php', "<?php define('APP_VERSION', '1.1.0'); define('APP_VERSION_STRING', '1_1_0');");
        file_put_contents($this->root . '/releases/v1_1_0/index.php', '<?php');
        file_put_contents($this->root . '/releases/v1_1_0/vendor/autoload.php', <<<'PHP_AUTOLOAD'
<?php
namespace YTassy\Tycms;
class Application {
    public function __construct(private object $config) {}
    public function runMigrationsOrThrow(): void {
        $db = new \PDO('mysql:host=' . $this->config->db_host . ';dbname=' . $this->config->db_dbname, $this->config->db_user, $this->config->db_password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $db->exec('CREATE TABLE workflow_migration (id INT PRIMARY KEY) ENGINE=InnoDB');
        $db->exec('CREATE TABLE migrations (id INT PRIMARY KEY) ENGINE=InnoDB');
        echo 'SORTIE PRIVÉE';
        if (getenv('TYCMS_MIGRATION_TEST_FAIL') === '1') throw new \RuntimeException('SECRET TEST');
    }
    public function initialiseOrThrow($request, bool $session): void {
        if ($session) throw new \RuntimeException('Session inattendue');
    }
}
namespace YTassy\Tycms\Request;
class Request {}
PHP_AUTOLOAD);
        foreach (['/releases/v1_1_0', '/public/assets_core_v1_1_0'] as $path) file_put_contents($this->root . $path . '/.tycms-update-owner', str_repeat('c', 32));
        $sha = str_repeat('b', 64);
        file_put_contents($this->root . '/var/releases-states.json', json_encode([
            'schema_version' => 1, 'active_release' => 'v1_0_0', 'active_operation' => [
                'id' => str_repeat('c', 32), 'status' => 'prepared', 'entrypoint' => $this->entry,
                'source_version' => '1.0.0', 'version' => '1.1.0', 'development' => false,
                'sha256' => $sha, 'package_id' => 'v1_1_0-' . $sha,
            ],
        ]));
        file_put_contents($this->root . '/var/last_update_data.json', json_encode([
            'operation_id' => str_repeat('c', 32), 'phase' => 'offline', 'source_release' => 'v1_0_0',
            'source_offline' => false, 'owns_targets' => true,
        ]));
    }

    private function action(string $action, bool $failMigration = false): array
    {
        $script = ($failMigration ? 'putenv("TYCMS_MIGRATION_TEST_FAIL=1"); ' : '')
            . 'require ' . var_export(__DIR__ . '/../../../updater/UpdaterService.php', true) . '; '
            . '$s = new Tycms\\Updater\\UpdaterService(new Tycms\\Updater\\OperationStore(' . var_export($this->root, true) . ')); '
            . 'try { echo json_encode($s->dispatch(' . var_export($this->entry, true) . ', ' . var_export($action, true) . '), JSON_THROW_ON_ERROR); } '
            . 'catch (Throwable $e) { echo json_encode(["error" => $e->getMessage()]); }';
        $process = proc_open([PHP_BINARY, '-r', $script], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error);
        self::assertTrue($error === '' || in_array($error, ["Tycms updater : échec des migrations (RuntimeException)\n", "Tycms updater : échec du contrôle de démarrage (RuntimeException)\n"], true), $error);
        return json_decode($output, true, 32, JSON_THROW_ON_ERROR);
    }

    public function testBackupThenMigrationInSeparateRequests(): void
    {
        $receipt = $this->action('backup');
        self::assertSame('backed_up', $receipt['phase']);
        $progress = json_decode(file_get_contents($this->root . '/var/last_update_data.json'), true);
        $backup = $this->root . '/var/updates/' . str_repeat('c', 32) . '/backup/backup.jsonl';
        self::assertSame($backup, $receipt['backup_paths']['jsonl']);
        self::assertSame(dirname($backup) . '/backup.sql', $receipt['backup_paths']['sql']);
        self::assertSame($receipt['backup_paths'], $this->action('status')['backup_paths']);
        self::assertSame(hash_file('sha256', dirname($backup) . '/backup.sql'), $progress['backup_sql_sha256']);
        self::assertSame(hash_file('sha256', $backup), $progress['backup_sha256']);
        self::assertSame(filesize($backup), $progress['backup_size']);
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workflow_migration'")->fetchColumn());
        self::assertSame('migrated', $this->action('migrate')['phase']);
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workflow_migration'")->fetchColumn());
        self::assertArrayHasKey('error', $this->action('migrate'));
        self::assertArrayHasKey('error', $this->action('cancel'));
        self::assertSame('migrated', $this->action('status')['phase']);
        self::assertStringContainsString('offline = true', file_get_contents($this->root . '/releases/v1_0_0/configuration.php'));
    }

    public function testAlteredSqlBackupBlocksMigration(): void
    {
        self::assertSame('backed_up', $this->action('backup')['phase']);
        $path = $this->root . '/var/updates/' . str_repeat('c', 32) . '/backup/backup.sql';
        file_put_contents($path, "-- altération\n", FILE_APPEND);
        self::assertArrayHasKey('error', $this->action('migrate'));
        self::assertSame('backed_up', $this->action('status')['phase']);
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workflow_migration'")->fetchColumn());
    }

    public function testBackupRemainsAvailableAfterCancellation(): void
    {
        self::assertSame('backed_up', $this->action('backup')['phase']);
        $directory = $this->root . '/var/updates/' . str_repeat('c', 32) . '/backup';
        $metadata = json_decode(file_get_contents($directory . '/metadata.json'), true);
        self::assertSame(hash_file('sha256', $directory . '/backup.jsonl'), $metadata['sha256']);
        self::assertSame('cancelled', $this->action('cancel')['phase']);
        self::assertFileExists($directory . '/backup.jsonl');
        self::assertFileExists($directory . '/backup.sql');
        self::assertFileExists($directory . '/metadata.json');
    }

    public function testActivationAndCompletion(): void
    {
        self::assertSame('backup_skipped', $this->action('skip_backup')['phase']);
        self::assertSame('migrated', $this->action('migrate')['phase']);
        self::assertSame('activated', $this->action('activate')['phase']);
        $state = json_decode(file_get_contents($this->root . '/var/releases-states.json'), true);
        self::assertSame('v1_1_0', $state['active_release']);
        self::assertStringContainsString('offline = true', file_get_contents($this->root . '/releases/v1_1_0/configuration.php'));
        $result = $this->action('online');
        self::assertSame('completed', $result['phase']);
        self::assertTrue($result['closed']);
        self::assertStringContainsString('offline = false', file_get_contents($this->root . '/releases/v1_1_0/configuration.php'));
        $state = json_decode(file_get_contents($this->root . '/var/releases-states.json'), true);
        self::assertSame('completed', $state['active_operation']['status']);
        self::assertArrayHasKey('error', $this->action('status'));
    }

    public function testRestoreAndReturnToSourceAfterActivation(): void
    {
        self::assertSame('backed_up', $this->action('backup')['phase']);
        self::assertSame('migrated', $this->action('migrate')['phase']);
        self::assertSame('activated', $this->action('activate')['phase']);
        // La restauration interne ne dépend pas du fichier d'export manuel.
        file_put_contents($this->root . '/var/updates/' . str_repeat('c', 32) . '/backup/backup.sql', 'altéré');
        self::assertSame('restored', $this->action('restore')['phase']);
        self::assertSame('42', (string) $this->pdo->query('SELECT id FROM source_data')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workflow_migration'")->fetchColumn());
        self::assertSame('rolled_back', $this->action('rollback')['phase']);
        $state = json_decode(file_get_contents($this->root . '/var/releases-states.json'), true);
        self::assertSame('v1_0_0', $state['active_release']);
        self::assertNull($state['active_operation']);
        self::assertFileDoesNotExist($this->root . '/releases/v1_1_0');
        self::assertFileExists($this->root . '/var/updates/' . str_repeat('c', 32) . '/backup/backup.jsonl');
    }

    public function testStartupFailureKeepsSourceInMaintenance(): void
    {
        self::assertSame('backup_skipped', $this->action('skip_backup')['phase']);
        self::assertSame('migrated', $this->action('migrate')['phase']);
        $this->pdo->exec('DROP TABLE migrations');
        self::assertArrayHasKey('error', $this->action('activate'));
        self::assertSame('activation_failed', $this->action('status')['phase']);
        $state = json_decode(file_get_contents($this->root . '/var/releases-states.json'), true);
        self::assertSame('v1_0_0', $state['active_release']);
        self::assertStringContainsString('offline = true', file_get_contents($this->root . '/releases/v1_0_0/configuration.php'));
    }

    public function testSiteAlreadyOfflineRemainsOffline(): void
    {
        $path = $this->root . '/var/last_update_data.json';
        $progress = json_decode(file_get_contents($path), true);
        $progress['source_offline'] = true;
        file_put_contents($path, json_encode($progress));
        self::assertSame('backup_skipped', $this->action('skip_backup')['phase']);
        self::assertSame('migrated', $this->action('migrate')['phase']);
        self::assertSame('activated', $this->action('activate')['phase']);
        self::assertTrue($this->action('online')['source_offline']);
        self::assertStringContainsString('offline = true', file_get_contents($this->root . '/releases/v1_1_0/configuration.php'));
    }

    public function testChangedConfigurationBlocksPuttingTargetOnline(): void
    {
        self::assertSame('backup_skipped', $this->action('skip_backup')['phase']);
        self::assertSame('migrated', $this->action('migrate')['phase']);
        self::assertSame('activated', $this->action('activate')['phase']);
        file_put_contents($this->root . '/releases/v1_1_0/configuration.php', "\n// modification\n", FILE_APPEND);
        self::assertArrayHasKey('error', $this->action('online'));
        self::assertSame('activated', $this->action('status')['phase']);
        self::assertStringContainsString('offline = true', file_get_contents($this->root . '/releases/v1_1_0/configuration.php'));
    }

    public function testExternalRestoreRequiresExplicitConfirmation(): void
    {
        self::assertArrayHasKey('error', $this->action('external_restore'));
        self::assertSame('backup_skipped', $this->action('skip_backup')['phase']);
        self::assertSame('migrated', $this->action('migrate')['phase']);
        // Simuler la restauration externe effectuée par l'opérateur.
        $this->pdo->exec('DROP TABLE workflow_migration');
        $this->pdo->exec('DROP TABLE migrations');
        self::assertSame('rolled_back', $this->action('external_restore')['phase']);
        self::assertStringContainsString('offline = false', file_get_contents($this->root . '/releases/v1_0_0/configuration.php'));
    }

    public function testFailedMigrationCannotBeRelaunchedOrSimplyCancelled(): void
    {
        self::assertSame('backup_skipped', $this->action('skip_backup')['phase']);
        $result = $this->action('migrate', true);
        self::assertStringNotContainsString('SECRET TEST', $result['error']);
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workflow_migration'")->fetchColumn());
        self::assertSame('migration_failed', $this->action('status')['phase']);
        self::assertArrayHasKey('error', $this->action('migrate'));
        self::assertArrayHasKey('error', $this->action('cancel'));
        self::assertStringContainsString('offline = true', file_get_contents($this->root . '/releases/v1_0_0/configuration.php'));
    }

    public function testRealMigrationApiOnAnIsolatedDatabase(): void
    {
        // Charger les vraies classes de vdev, sans charger la configuration réelle du site.
        $release = realpath(__DIR__ . '/../../../releases/vdev');
        $configuration = <<<'PHP_CONFIG'
<?php
require_once __DIR__ . '/configuration.base.php';

class Configuration extends ConfigurationBase {
    public $offline = true;

    public function __construct() {
        $this->db_host = 'mariadb';
        $this->db_dbname = getenv('TYCMS_BACKUP_TEST_DB');
        $this->db_user = getenv('TYCMS_BACKUP_TEST_USER');
        $this->db_password = getenv('TYCMS_BACKUP_TEST_PASSWORD');

        parent::__construct();
    }
}
PHP_CONFIG;
        foreach (['v1_0_0', 'v1_1_0'] as $version) {
            copy($release . '/configuration.base.php', $this->root . '/releases/' . $version . '/configuration.base.php');
            file_put_contents($this->root . '/releases/' . $version . '/configuration.php', $configuration);
        }
        file_put_contents($this->root . '/releases/v1_1_0/vendor/autoload.php', '<?php require ' . var_export($release . '/vendor/autoload.php', true) . ';');
        mkdir($this->root . '/extensions');
        foreach (['plugins', 'modules', 'templates'] as $kind) mkdir($this->root . '/extensions/' . $kind);
        mkdir($this->root . '/tmp');
        mkdir($this->root . '/logs');
        try {
            self::assertSame('backed_up', $this->action('backup')['phase']);
            self::assertSame('migrated', $this->action('migrate')['phase']);
            self::assertGreaterThan(0, (int) $this->pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn());
            self::assertSame('42', (string) $this->pdo->query('SELECT id FROM source_data')->fetchColumn());
            self::assertSame('activated', $this->action('activate')['phase']);
        } finally {
            // Cette base nommée et créée pour le test était vide au départ.
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach ($this->pdo->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"')->fetchAll(PDO::FETCH_NUM) as $table) {
                $this->pdo->exec('DROP TABLE `' . str_replace('`', '``', $table[0]) . '`');
            }
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    protected function tearDown(): void
    {
        if ($this->ownsFixture) {
            $this->pdo->exec('DROP TABLE IF EXISTS workflow_migration');
            $this->pdo->exec('DROP TABLE IF EXISTS migrations');
            $this->pdo->exec('DROP TABLE IF EXISTS source_data');
        }
        if (!isset($this->root) || !is_dir($this->root)) return;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($this->root);
    }
}
