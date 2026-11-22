<?php

declare(strict_types=1);

// Banc CLI réservé à une base temporaire nommée explicitement ; jamais la base du site.
if (PHP_SAPI !== 'cli') exit(1);
$name = getenv('TYCMS_BACKUP_TEST_DB');
$user = getenv('TYCMS_BACKUP_TEST_USER');
$password = getenv('TYCMS_BACKUP_TEST_PASSWORD');
$id = getenv('TYCMS_BACKUP_TEST_ID');
if (!is_string($name) || preg_match('/\Atycms_test_backup_[a-f0-9]{16}\z/', $name) !== 1
    || !is_string($user) || preg_match('/\Abak_[a-f0-9]{16}\z/', $user) !== 1
    || !is_string($id) || preg_match('/\A[a-f0-9]{16}\z/', $id) !== 1 || !is_string($password) || $password === '') {
    throw new RuntimeException('Base temporaire requise.');
}
$directory = '/tmp/tycms-sql-fixture-' . $id;
$pdo = new PDO('mysql:host=mariadb;dbname=' . $name . ';charset=utf8mb4', $user, $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$mode = $argv[1] ?? '';
if ($mode === 'prepare') {
    if ((int) $pdo->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchColumn() !== 0
        || file_exists($directory) || is_link($directory)) throw new RuntimeException('La base et le dossier doivent être neufs.');
    mkdir($directory, 0700);
    $pdo->exec('CREATE TABLE `a` (`id` INT PRIMARY KEY, `b_id` INT, `blob` LONGBLOB, `invisible` VARCHAR(20) INVISIBLE, `g` INT AS (`id` * 2) PERSISTENT, `n` INT CHECK (`n` > 0)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE `b` (`id` INT PRIMARY KEY, `a_id` INT, CONSTRAINT ck_b CHECK (`id` > 0)) ENGINE=InnoDB');
    $pdo->exec('ALTER TABLE a ADD CONSTRAINT fk_a FOREIGN KEY (b_id) REFERENCES b(id)');
    $pdo->exec('ALTER TABLE b ADD CONSTRAINT fk_b FOREIGN KEY (a_id) REFERENCES a(id)');
    $pdo->prepare('INSERT INTO a (`id`,`b_id`,`blob`,`invisible`,`n`) VALUES (?,NULL,?,?,?)')->execute([1, "\x00\xff'é", 'caché', 5]);
    $pdo->exec('INSERT INTO b VALUES (2, 1)');
    $pdo->exec('UPDATE a SET b_id = 2');
    $pdo->exec('SET NAMES latin1');
    $pdo->exec("SET sql_mode = 'NO_BACKSLASH_ESCAPES'");
    $pdo->exec("CREATE FUNCTION accent() RETURNS VARCHAR(20) DETERMINISTIC RETURN 'caf\xe9'");
    $pdo->exec('SET NAMES utf8mb4');
    $pdo->exec("SET sql_mode = ''");
    $pdo->exec("CREATE PROCEDURE maj() BEGIN UPDATE a SET n = n + 1; UPDATE a SET n = n - 1; END");
    $pdo->exec('CREATE VIEW z_base AS SELECT id, `invisible` FROM a');
    $pdo->exec('CREATE VIEW a_vue AS SELECT `invisible` FROM z_base');
    $pdo->exec('CREATE TRIGGER a_trigger BEFORE INSERT ON a FOR EACH ROW SET NEW.n = NEW.n + 1');
    $pdo->exec("CREATE EVENT test_event ON SCHEDULE AT '2038-01-01 00:00:00' DISABLE DO UPDATE a SET n = n");
    require_once __DIR__ . '/../../../updater/Lib/MariaDbBackup/DatabaseBackup.php';
    require_once __DIR__ . '/../../../updater/Lib/MariaDbBackup/SqlExporter.php';
    $result = (new Tycms\Updater\Lib\MariaDbBackup\DatabaseBackup($pdo))->backup($directory . '/backup.jsonl');
    (new Tycms\Updater\Lib\MariaDbBackup\SqlExporter($pdo))->export($directory . '/backup.jsonl', $directory . '/backup.sql', $result['size'], $result['sha256']);
    echo "Cliché SQL préparé.\n";
} elseif ($mode === 'clear') {
    $pdo->exec('DROP EVENT test_event');
    $pdo->exec('DROP TRIGGER a_trigger');
    $pdo->exec('DROP VIEW a_vue');
    $pdo->exec('DROP VIEW z_base');
    $pdo->exec('DROP PROCEDURE maj');
    $pdo->exec('DROP FUNCTION accent');
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    $pdo->exec('DROP TABLE a');
    $pdo->exec('DROP TABLE b');
    echo "Base de test vidée.\n";
} elseif ($mode === 'verify') {
    $row = $pdo->query('SELECT id,b_id,`blob`,`invisible`,g,n FROM a')->fetch(PDO::FETCH_ASSOC);
    if ((int) $row['b_id'] !== 2 || $row['blob'] !== "\x00\xff'é" || $row['invisible'] !== 'caché'
        || (int) $row['g'] !== 2 || (int) $row['n'] !== 5 || $pdo->query('SELECT accent()')->fetchColumn() !== 'café'
        || $pdo->query('SELECT `invisible` FROM a_vue')->fetchColumn() !== 'caché'
        || (int) $pdo->query('SELECT a_id FROM b')->fetchColumn() !== 1
        || (int) $pdo->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE()")->fetchColumn() !== 2
        || (int) $pdo->query("SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE()")->fetchColumn() !== 2
        || (int) $pdo->query("SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA = DATABASE()")->fetchColumn() !== 1
        || (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()")->fetchColumn() !== 1
        || $pdo->query("SELECT CHARACTER_SET_CLIENT FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = 'accent'")->fetchColumn() !== 'latin1') {
        throw new RuntimeException('La restauration SQL ne correspond pas au cliché.');
    }
    $pdo->exec('CALL maj()');
    echo "Import SQL vérifié.\n";
} elseif ($mode === 'cleanup') {
    if (is_link($directory) || realpath($directory) !== $directory) throw new RuntimeException('Dossier de test invalide.');
    foreach (['backup.sql', 'backup.jsonl'] as $file) if (is_file($directory . '/' . $file)) unlink($directory . '/' . $file);
    rmdir($directory);
} else throw new RuntimeException('Mode de test inconnu.');
