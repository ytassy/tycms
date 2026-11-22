<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Tycms\Updater\Lib\MariaDbBackup\DatabaseBackup;
use Tycms\Updater\Lib\MariaDbBackup\SqlExporter;

require_once __DIR__ . '/../../../updater/Lib/MariaDbBackup/DatabaseBackup.php';
require_once __DIR__ . '/../../../updater/Lib/MariaDbBackup/SqlExporter.php';

/** Ce test exige une base temporaire dédiée et vide, distincte de toute installation. */
final class MariaDbBackupTest extends TestCase
{
    public function testRoundTripAndCorruptionRefusal(): void
    {
        $name = getenv('TYCMS_BACKUP_TEST_DB');
        $user = getenv('TYCMS_BACKUP_TEST_USER');
        $password = getenv('TYCMS_BACKUP_TEST_PASSWORD');
        if (!is_string($name) || preg_match('/\Atycms_test_backup_[a-f0-9]{16}\z/', $name) !== 1
            || !is_string($user) || preg_match('/\Abak_[a-f0-9]{16}\z/', $user) !== 1
            || !is_string($password) || $password === '') {
            self::markTestSkipped('Une base temporaire tycms_test_backup dédiée est requise.');
        }
        $pdo = new PDO('mysql:host=mariadb;dbname=' . $name . ';charset=utf8mb4', $user, $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchColumn());
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE()')->fetchColumn());
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA = DATABASE()')->fetchColumn());

        // Objets typiques et cas qui ne survivent pas à un simple SELECT *.
        $pdo->exec('CREATE TABLE `records` (`id` INT PRIMARY KEY, `payload` LONGBLOB, `hidden` VARCHAR(30) INVISIBLE, `computed` INT AS (`id` * 2) PERSISTENT) ENGINE=InnoDB');
        $pdo->prepare('INSERT INTO records (id, payload, hidden) VALUES (?, ?, ?)')->execute([7, "\x00\xff'é", 'invisible']);
        $pdo->exec('CREATE TABLE `log` (`message` VARCHAR(100)) ENGINE=InnoDB');
        $pdo->exec('CREATE TABLE `cross_a` (`id` INT PRIMARY KEY, `b_id` INT, `value` INT CHECK (`value` > 0)) ENGINE=InnoDB');
        $pdo->exec('CREATE TABLE `cross_b` (`id` INT PRIMARY KEY, `a_id` INT, CONSTRAINT `check_positive` CHECK (`id` > 0)) ENGINE=InnoDB');
        $pdo->exec('ALTER TABLE cross_a ADD CONSTRAINT fk_a_b FOREIGN KEY (b_id) REFERENCES cross_b(id)');
        $pdo->exec('ALTER TABLE cross_b ADD CONSTRAINT fk_b_a FOREIGN KEY (a_id) REFERENCES cross_a(id)');
        $pdo->exec('INSERT INTO cross_a VALUES (1, NULL, 5)');
        $pdo->exec('INSERT INTO cross_b VALUES (2, 1)');
        $pdo->exec('UPDATE cross_a SET b_id = 2');
        $pdo->exec("CREATE FUNCTION `ajoute_un`(x INT) RETURNS INT DETERMINISTIC RETURN x + 1");
        $pdo->exec('SET NAMES latin1');
        $pdo->exec("SET sql_mode = 'NO_BACKSLASH_ESCAPES'");
        $pdo->exec("CREATE FUNCTION `accent`( ) RETURNS VARCHAR(20) DETERMINISTIC RETURN 'caf\xe9'");
        $pdo->exec('SET NAMES utf8mb4');
        $pdo->exec("SET sql_mode = ''");
        $pdo->exec("CREATE PROCEDURE `ajoute_log`(IN message VARCHAR(100)) INSERT INTO log VALUES (message)");
        $pdo->exec('CREATE VIEW `z_base` AS SELECT id, ajoute_un(id) AS val FROM records');
        $pdo->exec('CREATE VIEW `a_vue` AS SELECT val FROM z_base');
        $pdo->exec("CREATE TRIGGER `ajout` AFTER INSERT ON records FOR EACH ROW INSERT INTO log VALUES ('insert')");
        $pdo->exec("CREATE TRIGGER `ajout_suivant` AFTER INSERT ON records FOR EACH ROW FOLLOWS `ajout` INSERT INTO log VALUES ('après')");
        $pdo->exec("CREATE EVENT `evenement` ON SCHEDULE AT '2038-01-01 00:00:00' DISABLE DO INSERT INTO log VALUES ('event')");
        $directory = sys_get_temp_dir() . '/tycms-backup-' . bin2hex(random_bytes(12));
        mkdir($directory, 0700);
        $path = $directory . '/backup';
        try {
            $backup = new DatabaseBackup($pdo);
            $receipt = $backup->backup($path);
            $sqlReceipt = (new SqlExporter($pdo))->export($path, $directory . '/backup.sql', $receipt['size'], $receipt['sha256']);
            self::assertSame($sqlReceipt['sha256'], hash_file('sha256', $directory . '/backup.sql'));
            self::assertSame(0600, fileperms($directory . '/backup.sql') & 0777);
            self::assertSame(4, $receipt['tables']);
            self::assertSame(0600, fileperms($path) & 0777);
            $records = array_map(static fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($path, FILE_IGNORE_NEW_LINES));
            $types = array_column($records, 'type');
            self::assertSame(['constraint', 'constraint', 'constraint', 'constraint', 'end'], array_slice($types, -5));

            // Une empreinte incorrecte ne doit toucher ni au schéma ni aux données.
            try {
                $backup->restore($path, $receipt['size'], str_repeat('0', 64));
                self::fail('Empreinte invalide acceptée.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('Intégrité', $error->getMessage());
            }
            self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM records')->fetchColumn());
            $broken = $path . '.broken';
            file_put_contents($broken, substr(file_get_contents($path), 0, -15));
            try {
                $backup->restore($broken, filesize($broken), hash_file('sha256', $broken));
                self::fail('Archive tronquée acceptée.');
            } catch (RuntimeException $error) {
                self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM records')->fetchColumn());
            } finally {
                unlink($broken);
            }

            $pdo->exec('DELETE FROM records');
            $pdo->exec('DROP VIEW a_vue');
            $backup->restore($path, $receipt['size'], $receipt['sha256']);
            $row = $pdo->query('SELECT id, payload, hidden, computed FROM records')->fetch(PDO::FETCH_ASSOC);
            self::assertSame("\x00\xff'é", $row['payload']);
            self::assertSame('invisible', $row['hidden']);
            self::assertSame(14, (int) $row['computed']);
            self::assertSame(8, (int) $pdo->query('SELECT val FROM a_vue')->fetchColumn());
            self::assertSame('café', $pdo->query('SELECT accent()')->fetchColumn());
            self::assertSame('latin1', $pdo->query("SELECT CHARACTER_SET_CLIENT FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = 'accent'")->fetchColumn());
            self::assertSame('NO_BACKSLASH_ESCAPES', $pdo->query("SELECT SQL_MODE FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = 'accent'")->fetchColumn());
            self::assertSame(2, (int) $pdo->query('SELECT b_id FROM cross_a')->fetchColumn());
            self::assertSame(1, (int) $pdo->query('SELECT a_id FROM cross_b')->fetchColumn());
            self::assertSame(2, (int) $pdo->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME IN ('fk_a_b', 'fk_b_a')")->fetchColumn());
            self::assertSame(2, (int) $pdo->query("SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME IN ('value', 'check_positive')")->fetchColumn());
            $pdo->exec("CALL ajoute_log('restored')");
            self::assertSame('restored', $pdo->query('SELECT message FROM log')->fetchColumn());
            self::assertSame(2, (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME IN ('ajout', 'ajout_suivant')")->fetchColumn());
            self::assertSame(['ajout', 'ajout_suivant'], $pdo->query("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY ACTION_ORDER")->fetchAll(PDO::FETCH_COLUMN));
            self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA = DATABASE() AND EVENT_NAME = 'evenement'")->fetchColumn());
            $pdo->exec('CREATE TABLE `history` (id INT PRIMARY KEY) WITH SYSTEM VERSIONING');
            try {
                $backup->backup($directory . '/unsupported');
                self::fail('Table versionnée acceptée sans son historique.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('historique', $error->getMessage());
                self::assertFileDoesNotExist($directory . '/unsupported');
            }
        } finally {
            if (is_file($path)) unlink($path);
            if (is_file($directory . '/backup.sql')) unlink($directory . '/backup.sql');
            rmdir($directory);
        }
    }

    public function testRestrictedAccountProducesOnlyPartialBackupAndCannotRestoreIt(): void
    {
        $name = getenv('TYCMS_BACKUP_TEST_DB');
        $user = getenv('TYCMS_BACKUP_LIMITED_USER');
        $password = getenv('TYCMS_BACKUP_TEST_PASSWORD');
        if (!is_string($name) || preg_match('/\Atycms_test_backup_[a-f0-9]{16}\z/', $name) !== 1
            || !is_string($user) || preg_match('/\Alim_[a-f0-9]{16}\z/', $user) !== 1
            || !is_string($password) || $password === '') {
            self::markTestSkipped('Compte temporaire restreint requis.');
        }
        $pdo = new PDO('mysql:host=mariadb;dbname=' . $name . ';charset=utf8mb4', $user, $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $directory = sys_get_temp_dir() . '/tycms-backup-denied-' . bin2hex(random_bytes(12));
        mkdir($directory, 0700);
        $path = $directory . '/backup';
        try {
            $backup = new DatabaseBackup($pdo);
            $receipt = $backup->backup($path);
            self::assertFalse($receipt['complete']);
            self::assertNotEmpty($receipt['missing_privileges']);
            $file = fopen($path, 'rb');
            $header = json_decode((string) fgets($file), true, flags: JSON_THROW_ON_ERROR);
            fclose($file);
            self::assertFalse($header['complete']);
            try {
                $backup->restore($path, $receipt['size'], $receipt['sha256']);
                self::fail('Restauration automatique partielle acceptée.');
            } catch (RuntimeException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        } finally {
            if (is_file($path)) unlink($path);
            rmdir($directory);
        }
    }
}
