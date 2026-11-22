<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use ReflectionProperty;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\PermissionRequirements;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Roles;
use YTassy\Tycms\CoreExts\Core\Helper\Acl\InitialRolePermissions;
use YTassy\Tycms\CoreExts\Core\Migration\Mig_1_0_0_p2;
use YTassy\Tycms\DB\Database;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class InitialRolePermissionsTest extends FrameworkTestCase
{
    public function testInitialRolesAreCompleteAndSpaceAdminExcludesInstallationPrivileges(): void
    {
        $definitions = (new Permissions($this->createApplication()))->getPermissions();
        $requirements = new PermissionRequirements(['plg_core' => $definitions]);
        $roles = InitialRolePermissions::fromCoreCatalog($definitions);
        foreach ($roles as $permissions) {
            self::assertSame([], $requirements->missingFrom($permissions));
            self::assertCount(count(array_unique($permissions)), $permissions);
        }
        self::assertCount(count($definitions), $roles[Roles::ROLE_ROOT]);
        self::assertSame([Permissions::MEMBER], $roles[Roles::ROLE_MEMBER]);
        self::assertContains(Permissions::SPACE_ADMIN, $roles[Roles::ROLE_ADMIN_SPACE]);
        self::assertContains(Permissions::GROUP_ROLES_ASSIGN, $roles[Roles::ROLE_ADMIN_SPACE]);
        foreach ([Permissions::ROOT, Permissions::USERS_ADMIN, Permissions::EXTENSION_EDIT,
            Permissions::FONT_MANAGE, Permissions::SSO_CRUD] as $reserved) {
            self::assertNotContains($reserved, $roles[Roles::ROLE_ADMIN_SPACE]);
        }
    }

    public function testFreshInstallationPersistsTheExplicitCatalogWithoutReadingAnExistingDatabase(): void
    {
        $app = $this->createApplication();
        $app->getConf()->dev = false;
        $expected = InitialRolePermissions::fromCoreCatalog((new Permissions($app))->getPermissions());
        $inserted = [];
        $mediaDirectories = [];
        $db = $this->createMock(Database::class);
        $db->expects(self::never())->method('select');
        $db->expects(self::never())->method('selectOneOrNull');
        $db->method('executeMultiple')->willReturn([]);
        $db->method('execute')->willReturnCallback(static function (string $query, ?array $values) use (&$inserted, &$mediaDirectories): int {
            if (str_starts_with($query, 'INSERT INTO `spaces`')) $mediaDirectories[] = $values[4];
            if (str_starts_with($query, 'INSERT INTO acl_permissions')) {
                [$roleId, $extension, $name] = $values;
                $inserted[$roleId][] = $extension . '__' . $name;
            }
            return 1;
        });
        new ReflectionProperty(Application::class, 'database')->setValue($app, $db);

        (new Mig_1_0_0_p2($app))->run();

        self::assertSame($expected, $inserted);
        self::assertCount(2, array_unique($mediaDirectories), 'Les dossiers initiaux doivent respecter leur contrainte d’unicité.');
    }
}
