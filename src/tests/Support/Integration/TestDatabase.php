<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Support\Integration;

use mysqli;
use RuntimeException;

final class TestDatabase
{
    private mysqli $connection;

    public function __construct(private readonly TestEnvironment $environment)
    {
        $this->connection = new mysqli(
            $environment->host,
            $environment->user,
            $environment->password,
            $environment->database,
        );
        $this->connection->set_charset('utf8mb4');

        $identity = $this->connection->query('SELECT DATABASE() AS db_name, CURRENT_USER() AS sql_user')->fetch_assoc();
        if ($identity['db_name'] !== $environment->database
            || explode('@', $identity['sql_user'], 2)[0] !== $environment->user) {
            throw new RuntimeException('La connexion SQL ne correspond pas à la base et au compte de test autorisés.');
        }
    }

    public function connection(): mysqli
    {
        return $this->connection;
    }

    /** Efface uniquement les objets du schéma de test déjà validé. */
    public function resetSchema(): void
    {
        $objects = $this->connection->query(
            'SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
        )->fetch_all(MYSQLI_ASSOC);

        $this->connection->query('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach (['VIEW', 'BASE TABLE'] as $type) {
                foreach ($objects as $object) {
                    if ($object['TABLE_TYPE'] !== $type) continue;
                    $name = '`' . str_replace('`', '``', $object['TABLE_NAME']) . '`';
                    $this->connection->query(($type === 'VIEW' ? 'DROP VIEW IF EXISTS ' : 'DROP TABLE IF EXISTS ') . $name);
                }
            }
        } finally {
            $this->connection->query('SET FOREIGN_KEY_CHECKS = 1');
        }
    }
}
