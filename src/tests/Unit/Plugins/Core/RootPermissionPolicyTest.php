<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\PermissionRequirements;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\RootPermissionPolicy;
use YTassy\Tycms\CoreExts\Core\Helper\Acl\AclHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Acl\RoleAdminHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Acl\UserGroupAdminHelper;
use YTassy\Tycms\CoreExts\Core\Models\AclRole;
use YTassy\Tycms\CoreExts\Core\Models\UserGroup;
use YTassy\Tycms\DB\Database;
use YTassy\Tycms\DB\FormatIn;
use YTassy\Tycms\Exceptions\AccessDeniedException;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class RootPermissionPolicyTest extends FrameworkTestCase
{
    public static function roleScopes(): iterable
    {
        yield 'rôle global personnalisé à la racine' => [1, 0, 1000, true];
        yield 'rôle système Root' => [1, 0, 2, true];
        yield 'espace enfant' => [2, 0, 1000, false];
        yield 'rôle local' => [1, 1, 1000, false];
        yield 'rôle mixte' => [1, 2, 1000, false];
        yield 'invité' => [1, 0, 1, false];
        yield 'tous les utilisateurs' => [1, 0, 3, false];
        yield 'membre automatique' => [1, 0, 5, false];
    }

    #[DataProvider('roleScopes')]
    public function testRootRoleScope(int $space, int $type, int $role, bool $allowed): void
    {
        self::assertSame($allowed, RootPermissionPolicy::allowsRole([Permissions::ROOT], $space, $type, $role));
        self::assertTrue(RootPermissionPolicy::allowsRole([Permissions::PAGE_READ], $space, $type, $role));
    }

    public static function assignments(): iterable
    {
        yield 'transmission manuelle par Root' => [1, 10, true, false, false, true];
        yield 'gestionnaire sans Root' => [1, 10, false, false, false, false];
        yield 'space enfant' => [2, 10, true, false, false, false];
        yield 'sur un objet' => [1, 10, true, false, true, false];
        yield 'inscription même par Root' => [1, 10, true, true, false, false];
        yield 'groupe Invité' => [1, 1, true, false, false, false];
        yield 'groupe Utilisateurs' => [1, 2, true, false, false, false];
    }

    #[DataProvider('assignments')]
    public function testOnlyExplicitGlobalRootAssignmentsAreAllowed(int $space, int $group,
        bool $actor, bool $automatic, bool $object, bool $allowed): void
    {
        self::assertSame($allowed, RootPermissionPolicy::allowsAssignment([Permissions::ROOT],
            $space, $group, $actor, $automatic, $object));
    }

    public function testRoleWithAnExistingLocalAssignmentCannotAcquireRoot(): void
    {
        [$app, $db] = $this->applicationWithActor(true);
        $db->expects(self::once())->method('selectOneOrNull')
            ->with(self::stringContains('object_id IS NOT NULL'), [1000])->willReturn((object) ['id' => 7]);
        $db->expects(self::never())->method('execute');

        $this->expectException(AccessDeniedException::class);
        (new RoleAdminHelper($app))->assertRootRoleScope([Permissions::ROOT], 1, 0, 1000);
    }

    public function testAssignmentGuardReadsLockedRolePermissionsBeforeRefusingAutomation(): void
    {
        [$app, $db] = $this->applicationWithActor(true);
        $role = new AclRole($app);
        $role->id = 1000;
        $role->space_id = 1;
        $role->type = 0;
        $db->expects(self::once())->method('selectOrmOneOrNull')
            ->with(AclRole::class, self::stringEndsWith('FOR UPDATE'), [1000])->willReturn($role);
        $db->expects(self::once())->method('select')->with(self::stringEndsWith('FOR UPDATE'), [1000])
            ->willReturn([(object) ['extension' => 'plg_core', 'name' => 'ROOT']]);
        $db->expects(self::never())->method('execute');

        $this->expectException(AccessDeniedException::class);
        (new RoleAdminHelper($app))->assertRootAssignmentAllowed(1000, 1, 10, automatic: true);
    }

    public static function rootGroupActors(): iterable
    {
        yield 'gestionnaire sans ROOT' => [false, false, false];
        yield 'ROOT manuel' => [true, false, true];
        yield 'ROOT en inscription' => [true, true, false];
    }

    public function testInternalRoleWriteCannotRemoveRootWithoutRootActor(): void
    {
        [$app, $db, $acl] = $this->applicationWithActor(false);
        $acl->method('getPermissionRequirements')->willReturn(new PermissionRequirements([]));
        $role = new AclRole($app);
        $role->id = 1000;
        $role->space_id = 1;
        $role->type = 0;
        $db->expects(self::once())->method('selectOrmOneOrNull')->willReturn($role);
        $db->expects(self::once())->method('select')->with(self::stringEndsWith('FOR UPDATE'), [1000])
            ->willReturn([(object) ['extension' => 'plg_core', 'name' => 'ROOT']]);
        $db->expects(self::never())->method('execute');

        $this->expectException(AccessDeniedException::class);
        (new RoleAdminHelper($app))->replacePermissions(1000, []);
    }

    #[DataProvider('rootGroupActors')]
    public function testInheritedRootGroupMembershipIsProtected(bool $actor, bool $automatic, bool $allowed): void
    {
        [$app, $db, $acl] = $this->applicationWithActor($actor);
        $child = new UserGroup($app);
        $child->id = 11;
        $child->parent_id = 10;
        $parent = new UserGroup($app);
        $parent->id = 10;
        $parent->parent_id = null;
        $db->expects(self::exactly(2))->method('selectOrmOneOrNull')
            ->with(UserGroup::class, self::stringEndsWith('FOR UPDATE'), self::anything())
            ->willReturnOnConsecutiveCalls($child, $parent);
        $db->expects(self::once())->method('select')->with(self::stringContains('map.space_id = 1'),
            [FormatIn::get([11, 10])])->willReturn([(object) ['id' => 1000]]);
        $roles = $this->createMock(RoleAdminHelper::class);
        $roles->expects(self::once())->method('getPermissions')->with(1000, true)->willReturn([Permissions::ROOT]);
        new ReflectionProperty(Application::class, 'sharedExtensionsClasses')->setValue($app,
            [AclHelper::class => $acl, RoleAdminHelper::class => $roles]);

        if (!$allowed) $this->expectException(AccessDeniedException::class);
        (new UserGroupAdminHelper($app))->assertRootGroupAssignmentAllowed(11, $automatic);
        if ($allowed) self::assertTrue(true);
    }

    private function applicationWithActor(bool $root): array
    {
        $app = $this->createApplication();
        $db = $this->createMock(Database::class);
        $acl = $this->createStub(AclHelper::class);
        $acl->method('isRoot')->willReturn($root);
        new ReflectionProperty(Application::class, 'database')->setValue($app, $db);
        new ReflectionProperty(Application::class, 'sharedExtensionsClasses')->setValue($app, [AclHelper::class => $acl]);

        return [$app, $db, $acl];
    }
}
