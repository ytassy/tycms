<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Support\Integration;

use Configuration;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final readonly class TestEnvironment
{
    private const LOCAL_FILE = __DIR__ . '/../../test_db.local.php';

    private function __construct(
        public string $host,
        public string $database,
        public string $user,
        public string $password,
    ) {}

    public static function isConfigured(): bool
    {
        return is_file(self::LOCAL_FILE) || self::hasEnvironmentVariables();
    }

    public static function load(): self
    {
        if (self::hasEnvironmentVariables()) {
            $values = [
                'host' => getenv('TYCMS_TEST_DB_HOST'),
                'database' => getenv('TYCMS_TEST_DB_NAME'),
                'user' => getenv('TYCMS_TEST_DB_USER'),
                'password' => getenv('TYCMS_TEST_DB_PASSWORD'),
            ];
        } elseif (is_file(self::LOCAL_FILE)) {
            $values = require self::LOCAL_FILE;
        } else {
            throw new RuntimeException('Configuration de test absente : renseignez les variables TYCMS_TEST_DB_* ou tests/test_db.local.php.');
        }

        if (!is_array($values)) {
            throw new RuntimeException('La configuration de test doit retourner un tableau.');
        }

        return self::fromArray($values);
    }

    /** @param array<string,mixed> $values */
    public static function fromArray(array $values): self
    {
        foreach (['host', 'database', 'user', 'password'] as $field) {
            if (!is_string($values[$field] ?? null) || trim($values[$field]) === '') {
                throw new RuntimeException("Le paramètre de test {$field} est absent ou vide.");
            }
        }

        $host = $values['host'];
        $database = $values['database'];
        $user = $values['user'];
        // Le nom exact du service Docker est autorisé, sans ouvrir les hôtes distants.
        if (!in_array($host, ['127.0.0.1', 'localhost', '::1', 'mariadb'], true)) {
            throw new RuntimeException('La base de test doit être locale ou sur le service Docker mariadb.');
        }
        if (preg_match('/^tycms_test_[a-z0-9_]+$/', $database) !== 1) {
            throw new RuntimeException('Le nom de la base doit commencer par tycms_test_.');
        }
        if (preg_match('/^tycms_test_[a-z0-9_]+$/', $user) !== 1) {
            throw new RuntimeException('Le compte SQL doit commencer par tycms_test_.');
        }

        return new self($host, $database, $user, $values['password']);
    }

    public function runtimeRoot(): string
    {
        return dirname(__DIR__, 2) . '/.runtime/' . $this->database;
    }

    public function resetRuntime(): void
    {
        $parent = dirname($this->runtimeRoot());
        $root = $this->runtimeRoot();
        if (is_link($parent) || is_link($root)) {
            throw new RuntimeException('La racine temporaire de test ne peut pas être un lien symbolique.');
        }
        if (!is_dir($root)) return;

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            if ($item->isDir() && !$item->isLink()) {
                if (!rmdir($item->getPathname())) throw new RuntimeException('Nettoyage du runtime de test impossible.');
            } elseif (!unlink($item->getPathname())) {
                throw new RuntimeException('Nettoyage du runtime de test impossible.');
            }
        }
        if (!rmdir($root)) throw new RuntimeException('Nettoyage du runtime de test impossible.');
    }

    public function configuration(): Configuration
    {
        $root = $this->runtimeRoot();
        foreach ([$root, $root . '/medias', $root . '/tmp', $root . '/logs'] as $directory) {
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new RuntimeException("Impossible de créer le répertoire de test {$directory}.");
            }
        }

        $configuration = new Configuration();
        $configuration->debug = true;
        $configuration->dev = true;
        $configuration->RESET_DATABASE = false;
        $configuration->db_host = $this->host;
        $configuration->db_dbname = $this->database;
        $configuration->db_user = $this->user;
        $configuration->db_password = $this->password;
        $configuration->session_handler = 'database';
        $configuration->mailonline = false;
        $configuration->medias_root = $root . '/medias';
        $configuration->fonts_root = $root . '/fonts';
        $configuration->tmp_path = $root . '/tmp';
        $configuration->log_path = $root . '/logs';
        $configuration->first_admin_email = 'admin@integration.test';
        $configuration->first_admin_password = 'integration-only-password';
        $configuration->secret = 'integration-only-secret-for-test-database';

        return $configuration;
    }

    private static function hasEnvironmentVariables(): bool
    {
        foreach (['HOST', 'NAME', 'USER', 'PASSWORD'] as $suffix) {
            if (getenv('TYCMS_TEST_DB_' . $suffix) !== false) return true;
        }
        return false;
    }
}
