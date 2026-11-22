<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use ReflectionProperty;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\CoreExts\Core\Helper\Acl\AclHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Space\SpaceHelper;
use YTassy\Tycms\DB\Database;
use YTassy\Tycms\DB\FormatIn;
use YTassy\Tycms\Session\Session;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class AclHelperTest extends FrameworkTestCase
{
    public function testRootIsGlobalOnlyWhenGrantedOnSpaceOneAndDoesNotImplyOtherPermissions(): void
    {
        $userId = 42;
        $db = $this->createMock(Database::class);
        $db->expects(self::once())->method('select')->willReturn([
            (object) ['group_id' => 10, 'space_id' => 1, 'role_id' => 17],
        ]);
        $acl = $this->acl($db, $userId);
        new ReflectionProperty(AclHelper::class, 'allPermissionsByRoles')->setValue($acl, [17 => ['plg_core' => ['ROOT']]]);

        self::assertTrue($acl->isRoot());
        self::assertTrue($acl->hasGlobalPermission(Permissions::ROOT, 999));
        self::assertTrue($acl->userHasGlobalPermission(42, Permissions::ROOT, 999));
        self::assertFalse($acl->hasGlobalRole(2, 1));
        self::assertFalse($acl->hasGlobalPermission(Permissions::PAGE_CRUD, 2));
    }

    public function testRootOnAChildOrObjectNeverBecomesInstallationRoot(): void
    {
        $userId = 42;
        $db = $this->createMock(Database::class);
        $db->expects(self::once())->method('select')->willReturn([
            (object) ['group_id' => 10, 'space_id' => 2, 'role_id' => 17],
        ]);
        $db->expects(self::never())->method('selectOneOrNull');
        $acl = $this->acl($db, $userId);
        new ReflectionProperty(AclHelper::class, 'allPermissionsByRoles')->setValue($acl, [17 => ['plg_core' => ['ROOT']]]);

        self::assertFalse($acl->isRoot());
        self::assertFalse($acl->hasGlobalPermission(Permissions::ROOT, 2));
        self::assertFalse($acl->hasPermissionOn('ROOT', 'plg_core', 'page', 2, 123));
    }

    public function testRequirementsNeverGrantPermissionsAtReadTime(): void
    {
        $userId = 42;
        $db = $this->createMock(Database::class);
        $db->expects(self::once())->method('select')->willReturn([
            (object) ['group_id' => 10, 'space_id' => 2, 'role_id' => 17],
        ]);
        $acl = $this->acl($db, $userId);
        $acl->definePermissionsDefinition('plg_core', (new Permissions($this->createApplication()))->getPermissions());
        new ReflectionProperty(AclHelper::class, 'allPermissionsByRoles')->setValue($acl,
            [17 => ['plg_core' => ['PAGE_CRUD']]]);

        self::assertSame([Permissions::PAGE_READ], $acl->getPermissionRequirements()->forPermission(Permissions::PAGE_CRUD));
        self::assertTrue($acl->hasGlobalPermission(Permissions::PAGE_CRUD, 2));
        self::assertFalse($acl->hasGlobalPermission(Permissions::PAGE_READ, 2));
    }

    public function testSessionAndExplicitUserShareOneLoadAndTheSameInheritance(): void
    {
        $userId = 42;
        $db = $this->createMock(Database::class);
        $db->expects(self::once())->method('select')->with(self::anything(), [42])->willReturn([
            (object) ['group_id' => 10, 'space_id' => 2, 'role_id' => 17],
            (object) ['group_id' => 11, 'space_id' => 2, 'role_id' => 17],
        ]);
        $acl = $this->acl($db, $userId);

        self::assertTrue($acl->hasGlobalPermission(Permissions::MEMBER, 2));
        self::assertTrue($acl->userHasGlobalPermission(42, Permissions::MEMBER, 3));
        self::assertFalse($acl->hasGlobalPermission(Permissions::MEMBER, 1));
        self::assertFalse($acl->userHasGlobalPermission(42, Permissions::MEMBER, 4));
        self::assertFalse($acl->isRoot());
        self::assertTrue($acl->hasGlobalRole(17, 3));
        self::assertFalse($acl->hasGlobalRoleOnSpace(17, 3));
        self::assertTrue($acl->userHasGlobalRoleOnSpace(42, 17, 2));
        self::assertSame([2 => [17]], $acl->getUserGlobalRoles());
        self::assertSame([Permissions::MEMBER], $acl->getUserGlobalPermissionsOfSpace(2));
    }

    public function testInvalidationReloadsGrantsAndRevocationsForTheTargetUserOnly(): void
    {
        $userId = 42;
        $db = $this->createMock(Database::class);
        $db->expects(self::exactly(4))->method('select')->willReturnOnConsecutiveCalls(
            [], [], [(object) ['group_id' => 10, 'space_id' => 2, 'role_id' => 17]], []
        );
        $acl = $this->acl($db, $userId);

        self::assertFalse($acl->hasGlobalPermission(Permissions::MEMBER, 2));
        self::assertFalse($acl->userHasGlobalPermission(43, Permissions::MEMBER, 2));
        $acl->invalidateUser(42);
        self::assertTrue($acl->hasGlobalPermission(Permissions::MEMBER, 2));
        self::assertTrue($acl->userHasGlobalPermission(42, Permissions::MEMBER, 3));
        self::assertFalse($acl->userHasGlobalPermission(43, Permissions::MEMBER, 2));
        $acl->invalidateUser(42);
        self::assertFalse($acl->hasGlobalPermission(Permissions::MEMBER, 2));
    }

    public function testSessionChangesAndNonexistentUsersNeverReuseGuestRights(): void
    {
        $userId = null;
        $db = $this->createMock(Database::class);
        $db->expects(self::exactly(3))->method('select')->willReturnCallback(
            static fn (string $query, array $values): array => $values === []
                ? [(object) ['group_id' => 1, 'space_id' => 1, 'role_id' => 17]] : []
        );
        $acl = $this->acl($db, $userId);

        self::assertTrue($acl->hasGlobalPermission(Permissions::MEMBER, 3));
        self::assertFalse($acl->userHasGlobalPermission(0, Permissions::MEMBER, 3));
        $userId = 42;
        self::assertFalse($acl->hasGlobalPermission(Permissions::MEMBER, 3));
        self::assertFalse($acl->hasPermissionOn('MEMBER', 'plg_core', 'page', 2));
        $userId = null;
        self::assertTrue($acl->hasGlobalPermission(Permissions::MEMBER, 3));
    }

    public function testGroupsWithoutGlobalRolesStillAllowSpecificObjectChecks(): void
    {
        $userId = 42;
        $db = $this->createMock(Database::class);
        $db->expects(self::once())->method('select')->willReturn([
            (object) ['group_id' => 10, 'space_id' => null, 'role_id' => null],
        ]);
        $db->expects(self::once())->method('selectOneOrNull')->with(self::anything(), [
            FormatIn::get([10]), 99, 'plg_core', 'PAGE_READ', FormatIn::get([2, 1]),
        ])->willReturn((object) ['allowed' => 1]);
        $acl = $this->acl($db, $userId);
        new ReflectionProperty(AclHelper::class, 'allObjects')->setValue($acl, ['plg_core' => ['page' => 99]]);

        self::assertFalse($acl->hasGlobalPermission(Permissions::PAGE_READ, 2));
        self::assertTrue($acl->hasPermissionOn('PAGE_READ', 'plg_core', 'page', 2));
    }

    private function acl(Database $db, ?int &$userId): AclHelper
    {
        $app = $this->createApplication();
        $db->method('selectOrm')->willReturn([]);
        $session = $this->createStub(Session::class);
        $session->method('getUserId')->willReturnCallback(static function () use (&$userId): ?int {
            return $userId;
        });
        $spaces = $this->createStub(SpaceHelper::class);
        $spaces->method('getSpaceIdsWithParents')->willReturnCallback(static fn (?int $id): array => match ($id) {
            1 => [1], 2 => [2, 1], 3 => [3, 2, 1], 4 => [4, 1], default => [],
        });
        new ReflectionProperty(Application::class, 'database')->setValue($app, $db);
        new ReflectionProperty(Application::class, 'session')->setValue($app, $session);
        new ReflectionProperty(Application::class, 'sharedExtensionsClasses')->setValue($app, [SpaceHelper::class => $spaces]);
        $acl = new AclHelper($app);
        new ReflectionProperty(AclHelper::class, 'allPermissionsByRoles')->setValue($acl, [17 => ['plg_core' => ['MEMBER']]]);

        return $acl;
    }
}
