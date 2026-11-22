<?php

declare(strict_types=1);

namespace Tycms\Updater\Lib\MariaDbBackup;

use PDO;
use RuntimeException;

require_once __DIR__ . '/Archive.php';

/** Produit un script pour le client MariaDB à partir du même cliché JSONL. */
final class SqlExporter
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return array{size:int,sha256:string} */
    public function export(string $archive, string $path, int $size, string $sha256): array
    {
        $directory = dirname($path);
        $permissions = @fileperms($directory);
        if (is_link($archive) || !is_file($archive) || is_link($path) || file_exists($path)
            || dirname($archive) !== $directory || is_link($directory) || realpath($directory) !== $directory
            || $permissions === false || ($permissions & 0077) !== 0) {
            throw new RuntimeException('Chemin d’export SQL invalide.');
        }
        $input = @fopen($archive, 'rb');
        if ($input === false) throw new RuntimeException('Sauvegarde JSONL illisible.');
        $oldMask = umask(0077);
        try { $output = @fopen($path, 'x+b'); } finally { umask($oldMask); }
        if ($output === false) { fclose($input); throw new RuntimeException('Création de l’export SQL impossible.'); }
        $finished = false;
        try {
            if (!chmod($path, 0600) || !flock($input, LOCK_SH)) throw new RuntimeException('Export SQL inaccessible.');
            $stat = fstat($input);
            $hash = hash_init('sha256');
            if ($stat === false || $stat['size'] !== $size || hash_update_stream($hash, $input) !== $size
                || !hash_equals($sha256, hash_final($hash)) || !rewind($input)) {
                throw new RuntimeException('Sauvegarde JSONL altérée.');
            }
            $header = Archive::read($input);
            if (($header['type'] ?? null) !== 'header' || ($header['format'] ?? null) !== Archive::FORMAT || ($header['version'] ?? null) !== Archive::VERSION) {
                throw new RuntimeException('Format de sauvegarde non pris en charge.');
            }
            $database = Archive::decode($header['database'] ?? null);
            $charset = self::word($header['charset'] ?? null);
            $collation = self::word($header['collation'] ?? null);

            // Les tables provisoires décrivent les vues dépendantes avant leur création définitive.
            $views = [];
            $ended = false;
            while (($record = Archive::read($input)) !== null) {
                if (($record['type'] ?? null) === 'object' && ($record['kind'] ?? null) === 'VIEW') $views[] = $record;
                if (($record['type'] ?? null) === 'end') { $ended = true; break; }
            }
            if (!$ended || Archive::read($input) !== null || !rewind($input)) throw new RuntimeException('Sauvegarde JSONL incomplète.');
            Archive::read($input);
            if (($header['complete'] ?? true) !== true) {
                $this->write($output, "-- ATTENTION : sauvegarde partielle, des objets peuvent manquer. Ne permet pas un retour complet.\n");
            }
            $this->write($output, "-- Sauvegarde Tycms : importer avec le client MariaDB dans la base d'origine, préalablement vidée.\n"
                . "-- Ne pas utiliser l'option --force. Les tables provisoires de vues sont remplacées pendant l'import.\n"
                . "SET NAMES utf8mb4;\nSET SESSION sql_mode = '';\nSET time_zone = '+00:00';\n"
                . "SET @tycms_guard = IF(BINARY DATABASE() = BINARY CONVERT(" . self::literal($database) . " USING utf8mb4) "
                . "AND NOT EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()) "
                . "AND NOT EXISTS (SELECT 1 FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE()) "
                . "AND NOT EXISTS (SELECT 1 FROM information_schema.EVENTS WHERE EVENT_SCHEMA = DATABASE()), 'DO 0', "
                . "'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''Base cible incorrecte ou non vide''');\n"
                . "PREPARE tycms_guard FROM @tycms_guard;\nEXECUTE tycms_guard;\nDEALLOCATE PREPARE tycms_guard;\n"
                . 'ALTER DATABASE ' . self::identifier($database) . ' CHARACTER SET ' . $charset . ' COLLATE ' . $collation . ";\n"
                . "SET FOREIGN_KEY_CHECKS = 0;\n");
            $table = null;
            $columns = [];
            $viewsStarted = false;
            $constraintsStarted = false;
            while (($record = Archive::read($input)) !== null) {
                $type = $record['type'] ?? null;
                if ($type === 'end') break;
                if ($type === 'table') {
                    $table = Archive::decode($record['name'] ?? null);
                    $columns = array_map(static fn ($column) => self::identifier(Archive::decode($column)), $record['columns']);
                    $this->statement($output, Archive::decode($record['sql']));
                } elseif ($type === 'row') {
                    if ($table === null || count($record['values']) !== count($columns)) throw new RuntimeException('Ligne JSONL invalide.');
                    $values = array_map(static fn ($value) => $value === null ? 'NULL' : self::literal(Archive::decode($value)), $record['values']);
                    $this->write($output, 'INSERT INTO ' . self::identifier($table) . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ");\n");
                } elseif ($type === 'object') {
                    if ($record['kind'] === 'VIEW' && !$viewsStarted) {
                        $this->write($output, "SET NAMES utf8mb4;\nSET SESSION sql_mode = '';\n");
                        foreach ($views as $view) $this->viewPlaceholder($output, $view);
                        $viewsStarted = true;
                    }
                    if ($record['kind'] === 'VIEW') {
                        $this->write($output, 'DROP TABLE ' . self::identifier(Archive::decode($record['name'])) . ";\n");
                    }
                    $this->object($output, $record);
                } elseif ($type === 'constraint') {
                    if (!$constraintsStarted) {
                        $this->write($output, "SET SESSION sql_mode = '';\nSET FOREIGN_KEY_CHECKS = 1;\n");
                        $constraintsStarted = true;
                    }
                    $this->statement($output, Archive::decode($record['sql']));
                } else throw new RuntimeException('Enregistrement JSONL non pris en charge.');
            }
            $this->write($output, "SET FOREIGN_KEY_CHECKS = 1;\n-- Fin de la sauvegarde Tycms.\n");
            if (!fflush($output) || (function_exists('fsync') && !fsync($output))) throw new RuntimeException('Export SQL incomplet.');
            $outStat = fstat($output);
            if ($outStat === false || !rewind($output)) throw new RuntimeException('Export SQL illisible.');
            $hash = hash_init('sha256');
            if (hash_update_stream($hash, $output) !== $outStat['size']) throw new RuntimeException('Export SQL illisible.');
            $result = ['size' => $outStat['size'], 'sha256' => hash_final($hash)];
            if (!fclose($output)) { $output = null; throw new RuntimeException('Fermeture de l’export SQL impossible.'); }
            $output = null;
            $finished = true;
            return $result;
        } finally {
            flock($input, LOCK_UN);
            fclose($input);
            if (is_resource($output)) fclose($output);
            if (!$finished) @unlink($path);
        }
    }

    private function viewPlaceholder($output, array $view): void
    {
        if (!is_array($view['view_columns'] ?? null) || $view['view_columns'] === []) throw new RuntimeException('Colonnes de vue absentes.');
        $columns = [];
        foreach ($view['view_columns'] as $column) {
            $name = $column['COLUMN_NAME'] ?? null;
            $type = $column['COLUMN_TYPE'] ?? null;
            if (!is_string($name) || $name === '' || !is_string($type) || $type === '' || str_contains($type, "\0")) {
                throw new RuntimeException('Colonne de vue invalide.');
            }
            $definition = self::identifier($name) . ' ' . $type;
            if (($column['CHARACTER_SET_NAME'] ?? null) !== null) {
                $definition .= ' CHARACTER SET ' . self::word($column['CHARACTER_SET_NAME'])
                    . ' COLLATE ' . self::word($column['COLLATION_NAME'] ?? null);
            }
            $columns[] = $definition;
        }
        $this->write($output, 'CREATE TABLE ' . self::identifier(Archive::decode($view['name'])) . ' (' . implode(', ', $columns) . ");\n");
    }

    private function object($output, array $record): void
    {
        $charset = self::word($record['charset'] ?? null);
        $collation = self::word($record['collation'] ?? null);
        if (!is_string($record['mode'] ?? null) || !is_string($record['time_zone'] ?? null)) throw new RuntimeException('Contexte SQL invalide.');
        $sql = Archive::decode($record['sql'] ?? null);

        // Les instructions CREATE sont converties vers leur jeu client d'origine par MariaDB.
        $this->pdo->exec('SET NAMES utf8mb4');
        $this->pdo->exec('SET character_set_results = binary');
        try {
            $query = $this->pdo->prepare('SELECT CONVERT(? USING ' . $charset . ')');
            $query->execute([$sql]);
            $converted = $query->fetchColumn();
            $query->closeCursor();
            $warnings = (int) $this->pdo->query('SELECT @@warning_count')->fetchColumn();
            if (!is_string($converted) || $warnings !== 0) throw new RuntimeException('Conversion de définition SQL impossible.');
        } finally {
            $this->pdo->exec('SET NAMES utf8mb4');
        }
        $this->write($output, "SET NAMES utf8mb4;\nSET SESSION sql_mode = CONVERT(" . self::literal($record['mode']) . " USING utf8mb4);\n"
            . 'SET time_zone = CONVERT(' . self::literal($record['time_zone']) . " USING utf8mb4);\n"
            . 'SET NAMES ' . $charset . ' COLLATE ' . $collation . ";\n");
        $this->statement($output, $converted);
        $this->write($output, "SET NAMES utf8mb4;\n");
    }

    private function statement($output, string $sql): void
    {
        // Le client MariaDB limite la longueur du délimiteur ; éviter toute collision avec le SQL.
        do { $delimiter = 'T' . bin2hex(random_bytes(5)) . 'X'; } while (str_contains($sql, $delimiter));
        $this->write($output, "DELIMITER $delimiter\n" . $sql . $delimiter . "\nDELIMITER ;\n");
    }

    private function write($output, string $content): void
    {
        $position = 0;
        while ($position < strlen($content)) {
            $written = fwrite($output, substr($content, $position));
            if ($written === false || $written === 0) throw new RuntimeException('Écriture de l’export SQL incomplète.');
            $position += $written;
        }
    }

    private static function literal(string $value): string { return "X'" . bin2hex($value) . "'"; }
    private static function identifier(string $name): string
    {
        if ($name === '' || str_contains($name, "\0")) throw new RuntimeException('Identifiant SQL invalide.');
        return '`' . str_replace('`', '``', $name) . '`';
    }
    private static function word(mixed $value): string
    {
        if (!is_string($value) || preg_match('/\A[a-zA-Z0-9_]+\z/', $value) !== 1) throw new RuntimeException('Contexte SQL invalide.');
        return $value;
    }
}
