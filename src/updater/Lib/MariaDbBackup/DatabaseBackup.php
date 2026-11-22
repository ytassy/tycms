<?php

declare(strict_types=1);

namespace Tycms\Updater\Lib\MariaDbBackup;

use PDO;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/Archive.php';
require_once __DIR__ . '/TableDefinition.php';

/** Sauvegarde logique de la base courante et restauration de ses propres archives. */
final class DatabaseBackup
{
    private string $database;
    private string $currentUser;
    private bool $canSetUser = false;

    public function __construct(private readonly PDO $pdo)
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $version = $pdo->query('SELECT VERSION()')->fetchColumn();
        $database = $pdo->query('SELECT DATABASE()')->fetchColumn();
        if (!is_string($version) || stripos($version, 'mariadb') === false
            || !is_string($database) || $database === '') {
            throw new RuntimeException('Une base MariaDB sélectionnée est requise.');
        }
        $this->database = $database;
        $this->currentUser = (string) $pdo->query('SELECT CURRENT_USER()')->fetchColumn();
    }

    /** Produit un fichier privé neuf ; le résultat doit être conservé hors du fichier. */
    public function backup(string $path): array
    {
        $directory = dirname($path);
        $permissions = @fileperms($directory);
        if (is_link($path) || file_exists($path) || is_link($directory) || !is_dir($directory)
            || $permissions === false || ($permissions & 0077) !== 0) {
            throw new RuntimeException('Chemin de sauvegarde invalide.');
        }
        // Créer le fichier en mode privé dès son ouverture, avant toute écriture.
        $oldMask = umask(0077);
        try { $file = @fopen($path, 'x+b'); }
        finally { umask($oldMask); }
        if ($file === false) throw new RuntimeException('Impossible de créer la sauvegarde.');
        $finished = false;
        $transaction = false;
        try {
            if (!chmod($path, 0600)) throw new RuntimeException('Impossible de protéger la sauvegarde.');
            $access = $this->privileges();
            if (!in_array('SELECT', $access['effective'], true)) {
                throw new RuntimeException('Le droit SELECT sur la base est nécessaire à la sauvegarde.');
            }
            $this->pdo->exec("SET NAMES utf8mb4");
            $this->pdo->exec("SET time_zone = '+00:00'");
            $this->pdo->exec("SET SESSION sql_mode = ''");
            $this->pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
            $transaction = true;

            // Les métadonnées et les lignes sont lues dans une même connexion ; aucun DDL concurrent n'est autorisé.
            $schema = $this->row('SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$this->database]);
            if ($schema === null) throw new RuntimeException('Schéma MariaDB inaccessible.');
            Archive::write($file, ['type' => 'header', 'format' => Archive::FORMAT, 'version' => Archive::VERSION,
                'database' => base64_encode($this->database), 'charset' => $schema['DEFAULT_CHARACTER_SET_NAME'],
                'collation' => $schema['DEFAULT_COLLATION_NAME'], 'complete' => $access['missing'] === [],
                'missing_privileges' => $access['missing']]);
            $objects = $this->objects($access);
            $constraints = [];
            foreach ($objects['tables'] as $table) {
                if (strtoupper((string) $table['ENGINE']) !== 'INNODB') {
                    throw new RuntimeException('La sauvegarde exige des tables InnoDB.');
                }
                $name = $table['TABLE_NAME'];
                $columns = $this->columns($name);
                $definition = $this->definition('TABLE', $name);
                // Un SELECT ordinaire n'exporte pas l'historique des tables versionnées.
                if (preg_match('/\b(?:WITH\s+SYSTEM\s+VERSIONING|PERIOD\s+FOR\s+SYSTEM_TIME)\b/i', $definition['sql']) === 1) {
                    throw new RuntimeException('Les tables MariaDB à historique ne sont pas prises en charge.');
                }
                $separated = TableDefinition::split($definition['sql'], $name);
                array_push($constraints, ...$separated['constraints']);
                Archive::write($file, ['type' => 'table', 'name' => base64_encode($name),
                    'columns' => array_map('base64_encode', $columns), 'sql' => base64_encode($separated['create'])]);

                // Les colonnes invisibles sont nommées explicitement ; les colonnes générées ne sont pas insérées.
                $identifiers = implode(', ', array_map(self::quoteIdentifier(...), $columns));
                $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
                try {
                    $query = $this->pdo->query('SELECT ' . $identifiers . ' FROM ' . self::quoteIdentifier($name));
                    while (($values = $query->fetch(PDO::FETCH_NUM)) !== false) {
                        Archive::write($file, ['type' => 'row', 'values' => array_map(
                            static fn ($value) => $value === null ? null : base64_encode((string) $value), $values)]);
                    }
                    $query->closeCursor();
                } finally {
                    $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
                }
            }

            foreach (['FUNCTION', 'PROCEDURE', 'VIEW', 'TRIGGER', 'EVENT'] as $kind) {
                foreach ($objects[$kind] as $name) {
                    $definition = $this->definition($kind, $name);
                    $record = ['type' => 'object', 'kind' => $kind, 'name' => base64_encode($name),
                        'sql' => base64_encode($definition['sql']), 'mode' => $definition['mode'],
                        'charset' => $definition['charset'], 'collation' => $definition['collation'],
                        'time_zone' => $definition['time_zone'], 'definer' => $definition['definer']];
                    if ($kind === 'VIEW') {
                        // Les colonnes permettent au SQL manuel de résoudre les dépendances entre vues.
                        $record['view_columns'] = $this->rows('SELECT COLUMN_NAME, COLUMN_TYPE, CHARACTER_SET_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$this->database, $name]);
                        if ($record['view_columns'] === []) throw new RuntimeException('Colonnes de vue inaccessibles.');
                    }
                    Archive::write($file, $record);
                }
            }
            // Toutes les tables et leurs lignes existent avant les contraintes, y compris pour les références croisées.
            foreach ($constraints as $constraint) {
                Archive::write($file, ['type' => 'constraint', 'sql' => base64_encode($constraint)]);
            }
            Archive::write($file, ['type' => 'end']);
            if (!fflush($file) || (function_exists('fsync') && !fsync($file))) {
                throw new RuntimeException('Sauvegarde incomplète.');
            }
            $this->pdo->exec('COMMIT');
            $transaction = false;
            $stat = fstat($file);
            if ($stat === false || !rewind($file)) throw new RuntimeException('Sauvegarde illisible.');
            $hash = hash_init('sha256');
            if (hash_update_stream($hash, $file) !== $stat['size']) throw new RuntimeException('Sauvegarde illisible.');
            $result = ['size' => $stat['size'], 'sha256' => hash_final($hash), 'tables' => count($objects['tables']),
                'complete' => $access['missing'] === [], 'missing_privileges' => $access['missing']];
            if (!fclose($file)) {
                $file = null;
                throw new RuntimeException('Fermeture de la sauvegarde impossible.');
            }
            $file = null;
            $finished = true;
            return $result;
        } finally {
            if ($transaction) { try { $this->pdo->exec('ROLLBACK'); } catch (Throwable) {} }
            if (is_resource($file)) fclose($file);
            if (!$finished) @unlink($path);
        }
    }

    /** Vérifie toute l'archive avant de modifier la base, puis restaure dans la même connexion. */
    public function restore(string $path, int $size, string $sha256): void
    {
        if (is_link($path) || !is_file($path) || preg_match('/\A[a-f0-9]{64}\z/', $sha256) !== 1 || $size < 1) {
            throw new RuntimeException('Sauvegarde invalide.');
        }
        $file = @fopen($path, 'rb');
        if ($file === false) throw new RuntimeException('Sauvegarde inaccessible.');
        try {
            if (!flock($file, LOCK_SH)) throw new RuntimeException('Sauvegarde inaccessible.');
            $stat = fstat($file);
            $hash = hash_init('sha256');
            if ($stat === false || $stat['size'] !== $size || hash_update_stream($hash, $file) !== $size
                || !hash_equals($sha256, hash_final($hash)) || !rewind($file)) {
                throw new RuntimeException('Intégrité de sauvegarde invalide.');
            }
            if ($this->privileges()['missing'] !== []) {
                throw new RuntimeException('Droits MariaDB insuffisants pour la restauration automatique.');
            }
            $header = $this->validateArchive($file);
            if (!rewind($file)) throw new RuntimeException('Sauvegarde inaccessible.');

            // Le DDL MariaDB n'est pas transactionnel : toute interruption exige une nouvelle restauration complète.
            $this->pdo->exec("SET NAMES utf8mb4");
            $this->pdo->exec("SET time_zone = '+00:00'");
            $this->pdo->exec("SET SESSION sql_mode = ''");
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            try {
                $this->clearDatabase();
                $this->pdo->exec('ALTER DATABASE ' . self::quoteIdentifier($this->database)
                    . ' CHARACTER SET ' . self::safeWord($header['charset']) . ' COLLATE ' . self::safeWord($header['collation']));
                Archive::read($file);
                $statement = null;
                $views = [];
                $constraintsStarted = false;
                while (($record = Archive::read($file)) !== null) {
                    if ($record['type'] === 'end') break;
                    if ($record['type'] === 'table') {
                        $this->pdo->exec(Archive::decode($record['sql']));
                        $columns = array_map(static fn ($column) => self::quoteIdentifier(Archive::decode($column)), $record['columns']);
                        $statement = $this->pdo->prepare('INSERT INTO ' . self::quoteIdentifier(Archive::decode($record['name']))
                            . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')');
                    } elseif ($record['type'] === 'row') {
                        $values = array_map(static fn ($value) => $value === null ? null : Archive::decode($value), $record['values']);
                        $statement->execute($values);
                    } elseif ($record['type'] === 'object') {
                        if ($record['kind'] === 'VIEW') $views[] = $record;
                        else {
                            if ($views !== []) { $this->createViews($views); $views = []; }
                            $this->createObject($record);
                        }
                    } elseif ($record['type'] === 'constraint') {
                        if ($views !== []) { $this->createViews($views); $views = []; }
                        if (!$constraintsStarted) {
                            $this->pdo->exec("SET SESSION sql_mode = ''");
                            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
                            $constraintsStarted = true;
                        }
                        $this->pdo->exec(Archive::decode($record['sql']));
                    }
                }
                if ($views !== []) $this->createViews($views);
            } finally {
                $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            }
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
    }

    /** @return array<string, array> */
    private function objects(?array $access = null): array
    {
        $result = ['tables' => [], 'VIEW' => [], 'FUNCTION' => [], 'PROCEDURE' => [], 'TRIGGER' => [], 'EVENT' => []];
        $tables = $this->rows('SELECT TABLE_NAME, TABLE_TYPE, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME', [$this->database]);
        foreach ($tables as $table) {
            if ($table['TABLE_TYPE'] === 'BASE TABLE') $result['tables'][] = $table;
            elseif ($table['TABLE_TYPE'] === 'VIEW') {
                if ($access === null || in_array('SHOW VIEW', $access['effective'], true)) $result['VIEW'][] = $table['TABLE_NAME'];
            }
            elseif ($table['TABLE_TYPE'] === 'SYSTEM VERSIONED') throw new RuntimeException('Les tables MariaDB à historique ne sont pas prises en charge.');
            else throw new RuntimeException('Type de table MariaDB non pris en charge.');
        }
        $routines = $access === null || $access['routine_readable']
            ? $this->rows('SELECT ROUTINE_NAME, ROUTINE_TYPE FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ? ORDER BY ROUTINE_TYPE, ROUTINE_NAME', [$this->database]) : [];
        foreach ($routines as $routine) {
            if (!in_array($routine['ROUTINE_TYPE'], ['FUNCTION', 'PROCEDURE'], true)) {
                throw new RuntimeException('Type de routine MariaDB non pris en charge.');
            }
            $result[$routine['ROUTINE_TYPE']][] = $routine['ROUTINE_NAME'];
        }
        $triggers = $access === null || in_array('TRIGGER', $access['effective'], true)
            ? $this->rows('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? ORDER BY EVENT_OBJECT_TABLE, ACTION_TIMING, EVENT_MANIPULATION, ACTION_ORDER', [$this->database]) : [];
        foreach ($triggers as $trigger) {
            $result['TRIGGER'][] = $trigger['TRIGGER_NAME'];
        }
        $events = $access === null || in_array('EVENT', $access['effective'], true)
            ? $this->rows('SELECT EVENT_NAME FROM information_schema.EVENTS WHERE EVENT_SCHEMA = ? ORDER BY EVENT_NAME', [$this->database]) : [];
        foreach ($events as $event) {
            $result['EVENT'][] = $event['EVENT_NAME'];
        }
        return $result;
    }

    /** Identifier les limites de visibilité et de restauration sans supposer que les objets masqués sont absents. */
    private function privileges(): array
    {
        $grantee = "CONCAT(QUOTE(SUBSTRING_INDEX(CURRENT_USER(), '@', 1)), '@', QUOTE(SUBSTRING_INDEX(CURRENT_USER(), '@', -1)))";
        $global = array_column($this->rows('SELECT PRIVILEGE_TYPE FROM information_schema.USER_PRIVILEGES WHERE GRANTEE = ' . $grantee, []), 'PRIVILEGE_TYPE');
        $schema = array_column($this->rows('SELECT PRIVILEGE_TYPE FROM information_schema.SCHEMA_PRIVILEGES WHERE GRANTEE = ' . $grantee . ' AND TABLE_SCHEMA = ?', [$this->database]), 'PRIVILEGE_TYPE');
        $effective = array_unique(array_merge($global, $schema));
        $this->canSetUser = in_array('SET USER', $global, true) || in_array('SUPER', $global, true);
        $missing = [];
        foreach (['SELECT', 'SHOW VIEW', 'TRIGGER', 'EVENT', 'ALTER', 'CREATE', 'DROP', 'INSERT',
            'CREATE VIEW', 'CREATE ROUTINE', 'ALTER ROUTINE', 'REFERENCES'] as $privilege) {
            if (!in_array($privilege, $effective, true)) {
                $missing[] = $privilege;
            }
        }
        $routineReadable = in_array('SHOW CREATE ROUTINE', $effective, true);
        if (!$routineReadable) {
            $mysqlSchema = $this->row('SELECT PRIVILEGE_TYPE FROM information_schema.SCHEMA_PRIVILEGES WHERE GRANTEE = ' . $grantee . " AND TABLE_SCHEMA = 'mysql' AND PRIVILEGE_TYPE = 'SELECT'", []);
            $mysqlTable = $this->row('SELECT PRIVILEGE_TYPE FROM information_schema.TABLE_PRIVILEGES WHERE GRANTEE = ' . $grantee . " AND TABLE_SCHEMA = 'mysql' AND TABLE_NAME = 'proc' AND PRIVILEGE_TYPE = 'SELECT'", []);
            $routineReadable = in_array('SELECT', $global, true) || $mysqlSchema !== null || $mysqlTable !== null;
        }
        if (!$routineReadable) $missing[] = 'SHOW CREATE ROUTINE ou SELECT sur mysql.proc';

        return ['effective' => $effective, 'missing' => $missing, 'routine_readable' => $routineReadable];
    }

    private function columns(string $table): array
    {
        $columns = [];
        foreach ($this->rows('SELECT COLUMN_NAME, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$this->database, $table]) as $column) {
            if (preg_match('/\b(?:VIRTUAL|STORED|PERSISTENT) GENERATED\b/i', $column['EXTRA']) !== 1) {
                $columns[] = $column['COLUMN_NAME'];
            }
        }
        if ($columns === []) throw new RuntimeException('Table sans colonne exportable.');
        return $columns;
    }

    private function definition(string $kind, string $name): array
    {
        $result = $this->pdo->query('SHOW CREATE ' . $kind . ' ' . self::quoteIdentifier($name))->fetch(PDO::FETCH_ASSOC);
        if (!is_array($result)) throw new RuntimeException('Définition SQL inaccessible.');
        $fields = array_change_key_case($result, CASE_LOWER);
        $key = $kind === 'TRIGGER' ? 'sql original statement' : 'create ' . strtolower($kind);
        $sql = $fields[$key] ?? null;
        if (!is_string($sql) || $sql === '') throw new RuntimeException('Définition SQL inaccessible ou droits insuffisants.');
        $definer = null;
        if ($kind !== 'TABLE') {
            $sqlDefiner = match ($kind) {
                'VIEW' => 'SELECT DEFINER FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                'TRIGGER' => 'SELECT DEFINER FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? AND TRIGGER_NAME = ?',
                'EVENT' => 'SELECT DEFINER FROM information_schema.EVENTS WHERE EVENT_SCHEMA = ? AND EVENT_NAME = ?',
                'FUNCTION', 'PROCEDURE' => 'SELECT DEFINER FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ? AND ROUTINE_NAME = ? AND ROUTINE_TYPE = ?',
            };
            $parameters = [$this->database, $name];
            if (in_array($kind, ['FUNCTION', 'PROCEDURE'], true)) $parameters[] = $kind;
            $definer = $this->row($sqlDefiner, $parameters)['DEFINER'] ?? null;
            $this->assertDefiner($definer);
        }
        $context = $this->pdo->query('SELECT @@session.sql_mode AS mode, @@character_set_client AS charset, @@collation_connection AS collation, @@time_zone AS time_zone')->fetch(PDO::FETCH_ASSOC);
        return ['sql' => $sql, 'mode' => $fields['sql_mode'] ?? $context['mode'],
            'charset' => $fields['character_set_client'] ?? $context['charset'],
            'collation' => $fields['collation_connection'] ?? $context['collation'],
            'time_zone' => $fields['time_zone'] ?? $context['time_zone'], 'definer' => $definer];
    }

    /** Contrôle structurel avant toute opération destructive. */
    private function validateArchive($file): array
    {
        $header = Archive::read($file);
        if (($header['type'] ?? null) !== 'header' || ($header['format'] ?? null) !== Archive::FORMAT
            || ($header['version'] ?? null) !== Archive::VERSION || Archive::decode($header['database'] ?? null) !== $this->database) {
            throw new RuntimeException('Sauvegarde incompatible avec cette base.');
        }
        // Les archives anciennes ne contiennent pas ce champ et étaient forcément complètes.
        if (array_key_exists('complete', $header) && $header['complete'] !== true) {
            throw new RuntimeException('Une sauvegarde partielle ne peut pas être restaurée automatiquement.');
        }
        self::safeWord($header['charset'] ?? null);
        self::safeWord($header['collation'] ?? null);
        $names = [];
        $count = 0;
        $columns = null;
        $objectPhase = false;
        $constraintPhase = false;
        $ended = false;
        while (($record = Archive::read($file)) !== null) {
            $type = $record['type'] ?? null;
            if ($ended) throw new RuntimeException('Contenu après la fin de sauvegarde.');
            if ($type === 'end') { $ended = true; continue; }
            if ($type === 'table' && !$objectPhase) {
                $name = Archive::decode($record['name'] ?? null);
                $sql = Archive::decode($record['sql'] ?? null);
                if ($name === '' || $sql === '' || isset($names['TABLE'][$name]) || !is_array($record['columns'] ?? null)
                    || !array_is_list($record['columns']) || count($record['columns']) === 0) {
                    throw new RuntimeException('Table de sauvegarde invalide.');
                }
                $names['TABLE'][$name] = true;
                $seen = [];
                foreach ($record['columns'] as $column) {
                    $decoded = Archive::decode($column);
                    if ($decoded === '' || isset($seen[$decoded])) throw new RuntimeException('Colonnes de sauvegarde invalides.');
                    $seen[$decoded] = true;
                }
                $columns = count($record['columns']);
                ++$count;
            } elseif ($type === 'row' && !$objectPhase && $columns !== null) {
                if (!is_array($record['values'] ?? null) || !array_is_list($record['values']) || count($record['values']) !== $columns) {
                    throw new RuntimeException('Ligne de sauvegarde invalide.');
                }
                foreach ($record['values'] as $value) if ($value !== null) Archive::decode($value);
            } elseif ($type === 'object' && !$constraintPhase) {
                $objectPhase = true;
                $kind = $record['kind'] ?? null;
                if (!in_array($kind, ['FUNCTION', 'PROCEDURE', 'VIEW', 'TRIGGER', 'EVENT'], true)) throw new RuntimeException('Objet de sauvegarde invalide.');
                $name = Archive::decode($record['name'] ?? null);
                if ($name === '' || Archive::decode($record['sql'] ?? null) === '' || isset($names[$kind][$name])
                    || !is_string($record['mode'] ?? null) || !is_string($record['time_zone'] ?? null)) {
                    throw new RuntimeException('Objet de sauvegarde invalide.');
                }
                self::safeWord($record['charset'] ?? null);
                self::safeWord($record['collation'] ?? null);
                $this->assertDefiner($record['definer'] ?? null);
                $names[$kind][$name] = true;
            } elseif ($type === 'constraint') {
                $objectPhase = true;
                $constraintPhase = true;
                if (Archive::decode($record['sql'] ?? null) === '') throw new RuntimeException('Contrainte de sauvegarde invalide.');
            } else throw new RuntimeException('Ordre de sauvegarde invalide.');
        }
        if (!$ended) throw new RuntimeException('Fin de sauvegarde absente.');
        return $header;
    }

    private function clearDatabase(): void
    {
        $objects = $this->objects();
        foreach (['EVENT', 'TRIGGER', 'VIEW', 'PROCEDURE', 'FUNCTION'] as $kind) {
            foreach ($objects[$kind] as $name) $this->pdo->exec('DROP ' . $kind . ' ' . self::quoteIdentifier($name));
        }
        foreach ($objects['tables'] as $table) $this->pdo->exec('DROP TABLE ' . self::quoteIdentifier($table['TABLE_NAME']));
    }

    private function createViews(array $views): void
    {
        // Les vues peuvent dépendre d'autres vues classées après elles.
        while ($views !== []) {
            $remaining = [];
            $lastError = null;
            foreach ($views as $view) {
                try { $this->createObject($view); }
                catch (\PDOException $error) { $remaining[] = $view; $lastError = $error; }
            }
            if (count($remaining) === count($views)) throw $lastError;
            $views = $remaining;
        }
    }

    private function createObject(array $record): void
    {
        $charset = self::safeWord($record['charset']);
        $collation = self::safeWord($record['collation']);
        $this->pdo->exec('SET SESSION sql_mode = ' . $this->pdo->quote($record['mode']));
        $this->pdo->exec('SET SESSION time_zone = ' . $this->pdo->quote($record['time_zone']));

        // Le serveur convertit les octets UTF-8 vers le jeu client d'origine avant CREATE.
        $this->pdo->exec('SET NAMES utf8mb4');
        $this->pdo->exec('SET character_set_results = binary');
        $query = $this->pdo->prepare('SELECT CONVERT(? USING ' . $charset . ')');
        $query->execute([Archive::decode($record['sql'])]);
        $sql = $query->fetchColumn();
        $query->closeCursor();
        if (!is_string($sql)) throw new RuntimeException('Conversion de définition SQL impossible.');
        try {
            $this->pdo->exec('SET NAMES ' . $charset . ' COLLATE ' . $collation);
            $this->pdo->exec($sql);
        } finally {
            $this->pdo->exec('SET NAMES utf8mb4');
            $this->pdo->exec("SET time_zone = '+00:00'");
        }
    }

    private function rows(string $sql, array $values): array
    {
        $query = $this->pdo->prepare($sql);
        $query->execute($values);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    private function row(string $sql, array $values): ?array
    {
        $rows = $this->rows($sql, $values);
        return $rows[0] ?? null;
    }

    private static function quoteIdentifier(string $name): string
    {
        if ($name === '' || str_contains($name, "\0")) throw new RuntimeException('Identifiant SQL invalide.');
        return '`' . str_replace('`', '``', $name) . '`';
    }

    private static function safeWord(mixed $word): string
    {
        if (!is_string($word) || preg_match('/\A[a-zA-Z0-9_]+\z/', $word) !== 1) {
            throw new RuntimeException('Paramètre MariaDB invalide.');
        }
        return $word;
    }

    private function assertDefiner(mixed $definer): void
    {
        if (!is_string($definer) || $definer === '' || ($definer !== $this->currentUser && !$this->canSetUser)) {
            throw new RuntimeException('Droits insuffisants pour restaurer un DEFINER MariaDB.');
        }
    }
}
