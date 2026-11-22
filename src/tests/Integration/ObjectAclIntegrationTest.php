<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Integration;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Core\Helper\Acl\AclHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Acl\UserGroupAdminHelper;
use YTassy\Tycms\DB\Database;
use YTassy\Tycms\Session\Session;
use YTassy\Tycms\Tests\Support\Integration\TestDatabase;
use YTassy\Tycms\Tests\Support\Integration\TestEnvironment;
use YTassy\Tycms\Tests\Support\Integration\TestFixtures;

/** Vraies requêtes sur la base isolée ; chaque scénario est annulé sans DDL. */
final class ObjectAclIntegrationTest extends TestCase
{
    private Application $app;
    private Database $db;
    private int $userId;
    private int $groupId;
    private int $objectTypeId;
    private int $managerRoleId;
    private int $uploadRoleId;
    private int $publishRoleId;
    private string $extension;

    protected function setUp(): void
    {
        $environment = TestEnvironment::load();
        TestFixtures::assertPrepared(new TestDatabase($environment));
        $this->app = new Application($environment->configuration());
        $this->db = new Database($this->app);
        new ReflectionProperty(Application::class, 'database')->setValue($this->app, $this->db);
        $this->db->execute('START TRANSACTION');

        // L'utilisateur n'a que les droits locaux attribués dans ce scénario.
        $suffix = bin2hex(random_bytes(6));
        $this->extension = 'plg_acl_' . $suffix;
        $this->db->execute("INSERT INTO users (space_id, email, password, params) VALUES (2, %, 'unused', '{}')",
            ['acl-' . $suffix . '@integration.test']);
        $this->userId = $this->db->getLastInsertId();
        $this->db->execute('INSERT INTO user_groups (space_id, type, name) VALUES (2, 0, %)', ['ACL ' . $suffix]);
        $this->groupId = $this->db->getLastInsertId();
        $this->db->execute('INSERT INTO user_groups_map (user_id, group_id) VALUES (%, %)', [$this->userId, $this->groupId]);
        $this->db->execute('INSERT INTO acl_objects (extension, name) VALUES (%, %)', [$this->extension, 'catalog']);
        $this->objectTypeId = $this->db->getLastInsertId();
        $this->managerRoleId = $this->role(2, 1, ['ACCESS_MANAGE', 'UPLOAD']);
        $this->uploadRoleId = $this->role(2, 1, ['UPLOAD']);
        $this->publishRoleId = $this->role(2, 2, ['PUBLISH']);
        $this->map($this->managerRoleId, 2, $this->objectTypeId, 101);

        $session = $this->createStub(Session::class);
        $session->method('getUserId')->willReturnCallback(fn (): int => $this->userId);
        new ReflectionProperty(Application::class, 'session')->setValue($this->app, $session);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) $this->db->execute('ROLLBACK');
    }

    public function testObjectRightsAndDelegationStayWithinTheObjectAndHeldPermissions(): void
    {
        $acl = $this->acl();
        self::assertSame($this->objectTypeId, $acl->getObjectTypeId($this->extension, 'catalog'));
        self::assertFalse($acl->hasGlobalPermission($this->permission('UPLOAD'), 2));
        self::assertFalse($acl->hasPermissionOn('UPLOAD', $this->extension, 'catalog', 2));
        self::assertTrue($acl->hasPermissionOn('UPLOAD', $this->extension, 'catalog', 2, 101));
        self::assertFalse($acl->hasPermissionOn('UPLOAD', $this->extension, 'catalog', 2, 102));
        self::assertFalse($acl->hasPermissionOn('UPLOAD', $this->extension, 'other', 2, 101));
        self::assertFalse($acl->hasPermissionOn('UPLOAD', $this->extension, 'catalog', 1, 101));
        self::assertFalse($acl->hasPermissionOn('UPLOAD', $this->extension, 'catalog', 2, 0));
        self::assertTrue($this->canDelegate(['UPLOAD'], 101));
        self::assertFalse($this->canDelegate(['PUBLISH'], 101));
        self::assertFalse($this->canDelegate(['UPLOAD'], 102));
        self::assertFalse($acl->canDelegatePermissionsOn($this->permission('ACCESS_MANAGE'),
            ['plg_core__ROOT'], $this->extension, 'catalog', 101, 2));

        // Changer de compte ne réutilise pas les groupes ni les droits du gestionnaire.
        $this->userId = 0;
        self::assertFalse($this->canDelegate(['UPLOAD'], 101));
        $this->userId = 1;
        self::assertTrue($this->canDelegate(['PUBLISH'], 101));
    }

    public function testTypeAndGlobalGrantsKeepTheirSpaceInheritanceWithoutLeakingMalformedMaps(): void
    {
        $this->map($this->uploadRoleId, 1, $this->objectTypeId, null);
        // Une ligne incohérente sans type ACL mais avec un ID ne devient pas globale.
        $this->map($this->publishRoleId, 2, null, 101);
        $acl = $this->acl();
        self::assertTrue($acl->hasPermissionOn('UPLOAD', $this->extension, 'catalog', 2, 102));
        self::assertTrue($acl->hasPermissionOn('UPLOAD', $this->extension, 'catalog', 2));
        self::assertFalse($acl->hasGlobalPermission($this->permission('UPLOAD'), 2));
        self::assertFalse($acl->hasGlobalPermission($this->permission('PUBLISH'), 2));

        $this->map($this->publishRoleId, 1, null, null);
        $acl->invalidateUser($this->userId);
        self::assertTrue($acl->hasPermissionOn('PUBLISH', $this->extension, 'catalog', 2, 102));
        self::assertFalse($acl->hasPermissionOn('PUBLISH', $this->extension, 'catalog', 2, -1));
    }

    public function testReplacingAndRevokingObjectRolesPreservesOtherScopesAndIsImmediatelyVisible(): void
    {
        $this->map($this->publishRoleId, 2, $this->objectTypeId, 102);
        $this->map($this->uploadRoleId, 1, $this->objectTypeId, null);
        $acl = $this->acl();
        $groups = new UserGroupAdminHelper($this->app);
        self::assertTrue($this->canDelegate(['UPLOAD'], 101));

        $groups->replaceObjectRoleAssignments($this->groupId, 2, $this->objectTypeId, 101, [$this->uploadRoleId]);
        self::assertSame([$this->uploadRoleId], $groups->getObjectRoleIds($this->groupId, 2, $this->objectTypeId, 101));
        self::assertSame([$this->publishRoleId], $groups->getObjectRoleIds($this->groupId, 2, $this->objectTypeId, 102));
        self::assertFalse($this->canDelegate(['UPLOAD'], 101));
        self::assertTrue($acl->hasPermissionOn('UPLOAD', $this->extension, 'catalog', 2, 101));

        $groups->replaceObjectRoleAssignments($this->groupId, 2, $this->objectTypeId, 101, []);
        self::assertSame([], $groups->getObjectRoleIds($this->groupId, 2, $this->objectTypeId, 101));
        self::assertTrue($acl->hasPermissionOn('PUBLISH', $this->extension, 'catalog', 2, 102));
        self::assertTrue($acl->hasPermissionOn('UPLOAD', $this->extension, 'catalog', 2));
    }

    public function testIneligibleRolesGroupsAndDuplicateIdsAreRejectedBeforeReplacingAnything(): void
    {
        $globalRole = $this->role(2, 0, ['UPLOAD']);
        $outsideRole = $this->role(1, 1, ['UPLOAD']);
        $groups = new UserGroupAdminHelper($this->app);
        foreach ([$globalRole, $outsideRole, 1, 3] as $roleId) {
            try {
                $groups->replaceObjectRoleAssignments($this->groupId, 2, $this->objectTypeId, 101, [$roleId]);
                self::fail('Rôle incompatible accepté.');
            } catch (InvalidArgumentException) {
                self::assertSame([$this->managerRoleId], $groups->getObjectRoleIds($this->groupId, 2, $this->objectTypeId, 101));
            }
        }
        foreach ([[1, 1, []], [2, 1, []], [$this->groupId, 1, []],
            [$this->groupId, 2, [$this->uploadRoleId, $this->uploadRoleId]]] as [$groupId, $spaceId, $roleIds]) {
            try {
                $groups->replaceObjectRoleAssignments($groupId, $spaceId, $this->objectTypeId, 101, $roleIds);
                self::fail('Périmètre ou doublon accepté.');
            } catch (InvalidArgumentException) {
                self::assertSame([$this->managerRoleId], $groups->getObjectRoleIds($this->groupId, 2, $this->objectTypeId, 101));
            }
        }
    }

    private function role(int $spaceId, int $type, array $permissions): int
    {
        $this->db->execute('INSERT INTO acl_roles (space_id, type, user_defined, name) VALUES (%, %, 1, %)',
            [$spaceId, $type, 'ACL ' . bin2hex(random_bytes(6))]);
        $id = $this->db->getLastInsertId();
        foreach ($permissions as $permission) {
            $this->db->execute('INSERT INTO acl_permissions (role_id, extension, name) VALUES (%, %, %)',
                [$id, $this->extension, $permission]);
        }
        return $id;
    }

    private function map(int $roleId, int $spaceId, ?int $aclObjectId, ?int $objectId): void
    {
        $this->db->execute('INSERT INTO acl_map (role_id, space_id, group_id, acl_object_id, object_id) VALUES (%, %, %, %, %)',
            [$roleId, $spaceId, $this->groupId, $aclObjectId, $objectId]);
    }

    private function acl(): AclHelper { return $this->app->getOrAddSharedExtensionsClass(AclHelper::class); }

    private function permission(string $name): string { return $this->extension . '__' . $name; }

    private function canDelegate(array $permissions, int $objectId): bool
    {
        return $this->acl()->canDelegatePermissionsOn($this->permission('ACCESS_MANAGE'),
            array_map($this->permission(...), $permissions), $this->extension, 'catalog', $objectId, 2);
    }
}
