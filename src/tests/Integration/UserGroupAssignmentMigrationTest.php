<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Integration;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Core\Migration\Mig_1_0_14;
use YTassy\Tycms\DB\Database;
use YTassy\Tycms\Tests\Support\Integration\TestEnvironment;

final class UserGroupAssignmentMigrationTest extends TestCase
{
    public function testMigrationCopiesOnlyCoreAssignmentPermissionAndIsIdempotent(): void
    {
        $app = new Application(TestEnvironment::load()->configuration());
        $db = new Database($app);
        new ReflectionProperty(Application::class, 'database')->setValue($app, $db);

        // La table temporaire masque la vraie table uniquement sur cette connexion.
        $db->execute('CREATE TEMPORARY TABLE acl_permissions (
            role_id INT, extension VARCHAR(50), name VARCHAR(100)
        )');
        try {
            $db->execute("INSERT INTO acl_permissions VALUES
                (2, 'plg_core', 'USER_ROLES_CRUD'),
                (4, 'plg_core', 'USER_ROLES_CRUD'),
                (4, 'plg_core', 'USER_GROUPS_ASSIGN'),
                (8, 'plg_core', 'USER_GROUPS_CRUD'),
                (9, 'other', 'USER_ROLES_CRUD')");

            new Mig_1_0_14($app)->run();
            new Mig_1_0_14($app)->run();

            $rows = $db->select("SELECT role_id FROM acl_permissions
                WHERE extension = 'plg_core' AND name = 'USER_GROUPS_ASSIGN' ORDER BY role_id");
            self::assertSame([2, 4], array_map(static fn (object $row): int => (int) $row->role_id, $rows));
            self::assertSame(6, (int) $db->selectOneOrNull('SELECT COUNT(*) AS total FROM acl_permissions')->total);
        } finally {
            $db->execute('DROP TEMPORARY TABLE acl_permissions');
        }
    }
}
