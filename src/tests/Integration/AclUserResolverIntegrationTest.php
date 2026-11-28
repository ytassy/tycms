<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Core\Helper\Acl\AclUserResolver;
use YTassy\Tycms\Request\Request;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Session\Session;
use YTassy\Tycms\Tests\Support\Integration\TestDatabase;
use YTassy\Tycms\Tests\Support\Integration\TestEnvironment;
use YTassy\Tycms\Tests\Support\Integration\TestFixtures;

/**
 * Résolution inverse d'audience sur la base isolée.
 *
 * Chaque scénario crée ses comptes, groupes et rôles puis les supprime : les
 * fixtures de migration ne sont pas modifiées. Le type d'objet ACL est créé
 * avant le démarrage de l'application, car `AclHelper` charge le catalogue une
 * fois pour la requête.
 */
final class AclUserResolverIntegrationTest extends TestCase
{
    private TestEnvironment $environment;

    private TestDatabase $database;

    private Application $app;

    private AclUserResolver $resolver;

    private string $objectExtension;

    /** Permission propre au test : aucun rôle des fixtures ne la possède. */
    private string $permission = '';

    private int $objectTypeId = 0;

    /** @var list<int> */
    private array $users = [];

    /** @var list<int> */
    private array $groups = [];

    /** @var list<int> */
    private array $roles = [];

    protected function setUp(): void
    {
        $this->environment = TestEnvironment::load();
        $this->database = new TestDatabase($this->environment);
        TestFixtures::assertPrepared($this->database);

        // Le catalogue des types d'objet est lu au démarrage : créer le type
        // avant l'initialisation, avec une extension propre à ce test.
        $db = $this->database->connection();
        $this->objectExtension = 'plg_test_audience_' . bin2hex(random_bytes(4));
        $db->query("INSERT INTO acl_objects (extension, name) VALUES ('"
            . $this->objectExtension . "', 'widget')");
        $this->objectTypeId = (int) $db->insert_id;
        $this->permission = $this->objectExtension . '__USE';

        $this->app = new Application($this->environment->configuration());

        $session = $this->createStub(Session::class);
        $session->method('getUserId')->willReturn(1);
        $session->method('getSpaceId')->willReturn(TestFixtures::ROOT_SPACE_ID);

        $this->app->initialiseOrThrow(
            new Request(method: RequestMethod::GET, uri: '/'),
            initializeSession: false,
            session: $session,
            registerRoutes: false,
        );

        /** @var AclUserResolver $resolver */
        $resolver = $this->app->getOrAddSharedExtensionsClass(AclUserResolver::class);
        $this->resolver = $resolver;
    }

    protected function tearDown(): void
    {
        $db = $this->database->connection();

        $this->deleteWhere('acl_map', 'group_id', $this->groups);
        $this->deleteWhere('user_groups_map', 'user_id', $this->users);
        $this->deleteWhere('users', 'id', $this->users);

        // Le parent doit être détaché avant la suppression de son groupe.
        if ($this->groups !== []) {
            $db->query('UPDATE user_groups SET parent_id = NULL WHERE id IN (' . implode(',', $this->groups) . ')');
        }
        $this->deleteWhere('user_groups', 'id', $this->groups);

        $this->deleteWhere('acl_permissions', 'role_id', $this->roles);
        $this->deleteWhere('acl_roles', 'id', $this->roles);

        if ($this->objectTypeId > 0) {
            $db->query('DELETE FROM acl_objects WHERE id = ' . $this->objectTypeId);
        }
    }

    #[Test]
    public function globalPermissionFollowsSpaceBranchAndGroupHierarchy(): void
    {
        $roleId = $this->permissionRole('USE', TestFixtures::ROOT_SPACE_ID);

        // Une affectation sur le Space racine profite au Site et aux groupes enfants.
        $rootGroup = $this->group(TestFixtures::ROOT_SPACE_ID);
        $childGroup = $this->group(TestFixtures::SITE_SPACE_ID, $rootGroup);
        $this->grant($rootGroup, $roleId, TestFixtures::ROOT_SPACE_ID);

        // Une affectation propre au Site ne remonte jamais vers le Space racine.
        $siteGroup = $this->group(TestFixtures::SITE_SPACE_ID);
        $this->grant($siteGroup, $roleId, TestFixtures::SITE_SPACE_ID);

        $rootMember = $this->user(TestFixtures::ROOT_SPACE_ID);
        $childMember = $this->user(TestFixtures::SITE_SPACE_ID);
        $siteMember = $this->user(TestFixtures::SITE_SPACE_ID);
        $outsider = $this->user(TestFixtures::SITE_SPACE_ID);

        $this->membership($rootMember, $rootGroup);
        $this->membership($childMember, $childGroup);
        $this->membership($siteMember, $siteGroup);

        // Le Site (2) hérite du Space racine et le groupe enfant du groupe parent.
        self::assertSame(
            $this->sorted([$rootMember, $childMember, $siteMember]),
            $this->sorted($this->globalPermissionUsers(
                $this->permission,
                TestFixtures::SITE_SPACE_ID,
                20,
            )),
        );

        // Le Space racine ne voit pas l'affectation posée sur le Site.
        self::assertSame(
            $this->sorted([$rootMember, $childMember]),
            $this->sorted($this->globalPermissionUsers(
                $this->permission,
                TestFixtures::ROOT_SPACE_ID,
                20,
            )),
        );

        self::assertNotContains($outsider, $this->globalPermissionUsers(
            $this->permission,
            TestFixtures::SITE_SPACE_ID,
            20,
        ));
    }

    #[Test]
    public function blockedAndServiceAccountsAreExcluded(): void
    {
        $roleId = $this->permissionRole('USE', TestFixtures::SITE_SPACE_ID);
        $group = $this->group(TestFixtures::SITE_SPACE_ID);
        $this->grant($group, $roleId, TestFixtures::SITE_SPACE_ID);

        $active = $this->user(TestFixtures::SITE_SPACE_ID);
        $blocked = $this->user(TestFixtures::SITE_SPACE_ID, blocked: true);
        $service = $this->user(TestFixtures::SITE_SPACE_ID, type: 'service');

        foreach ([$active, $blocked, $service] as $userId) {
            $this->membership($userId, $group);
        }

        self::assertSame(
            [$active],
            $this->globalPermissionUsers($this->permission, TestFixtures::SITE_SPACE_ID, 20),
        );
    }

    #[Test]
    public function limitStopsTheFinalEnumerationAndLimitPlusOneRevealsOverflow(): void
    {
        $roleId = $this->permissionRole('USE', TestFixtures::SITE_SPACE_ID);
        $group = $this->group(TestFixtures::SITE_SPACE_ID);
        $this->grant($group, $roleId, TestFixtures::SITE_SPACE_ID);

        $members = [];
        for ($index = 0; $index < 4; $index++) {
            $members[] = $this->user(TestFixtures::SITE_SPACE_ID);
            $this->membership($members[$index], $group);
        }

        $all = $this->globalPermissionUsers($this->permission, TestFixtures::SITE_SPACE_ID, 10);
        self::assertSame($this->sorted($members), $all);

        // La requête finale porte un LIMIT et s'arrête à la borne demandée.
        $bounded = $this->globalPermissionUsers($this->permission, TestFixtures::SITE_SPACE_ID, 2);
        self::assertCount(2, $bounded);
        self::assertSame(array_slice($all, 0, 2), $bounded);

        // Un appelant qui passe `plafond + 1` distingue une audience complète
        // d'une audience qui dépasse le plafond de 3.
        $overflow = $this->globalPermissionUsers($this->permission, TestFixtures::SITE_SPACE_ID, 4);
        self::assertCount(4, $overflow);
        self::assertGreaterThan(3, count($overflow));
    }

    #[Test]
    public function objectPermissionCoversGlobalTypeAndInstanceAssignments(): void
    {
        $roleId = $this->permissionRole('USE', TestFixtures::SITE_SPACE_ID);

        $globalGroup = $this->group(TestFixtures::SITE_SPACE_ID);
        $typeGroup = $this->group(TestFixtures::SITE_SPACE_ID);
        $instanceGroup = $this->group(TestFixtures::SITE_SPACE_ID);
        $otherInstanceGroup = $this->group(TestFixtures::SITE_SPACE_ID);

        $this->grant($globalGroup, $roleId, TestFixtures::SITE_SPACE_ID);
        $this->grantObject($typeGroup, $roleId, TestFixtures::SITE_SPACE_ID, $this->objectTypeId, null);
        $this->grantObject($instanceGroup, $roleId, TestFixtures::SITE_SPACE_ID, $this->objectTypeId, 42);
        $this->grantObject($otherInstanceGroup, $roleId, TestFixtures::SITE_SPACE_ID, $this->objectTypeId, 43);

        $globalMember = $this->user(TestFixtures::SITE_SPACE_ID);
        $typeMember = $this->user(TestFixtures::SITE_SPACE_ID);
        $instanceMember = $this->user(TestFixtures::SITE_SPACE_ID);
        $otherInstanceMember = $this->user(TestFixtures::SITE_SPACE_ID);
        $this->membership($globalMember, $globalGroup);
        $this->membership($typeMember, $typeGroup);
        $this->membership($instanceMember, $instanceGroup);
        $this->membership($otherInstanceMember, $otherInstanceGroup);

        $onInstance = $this->permissionUsersOnObject($this->permission, 42, 20);

        self::assertSame($this->sorted([$globalMember, $typeMember, $instanceMember]), $onInstance);
        self::assertNotContains($otherInstanceMember, $onInstance);

        // Le droit d'instance ne déborde pas sur une autre instance.
        self::assertSame(
            $this->sorted([$globalMember, $typeMember, $otherInstanceMember]),
            $this->permissionUsersOnObject($this->permission, 43, 20),
        );

        // Un type d'objet inconnu ne produit aucune audience.
        self::assertSame([], $this->resolver->usersWithPermissionOnObject(
            $this->permission,
            $this->objectExtension,
            'unknown',
            42,
            TestFixtures::SITE_SPACE_ID,
            20,
        ));

        // Les rôles sur objet suivent la même réunion globale, type et instance.
        self::assertSame(
            $onInstance,
            $this->sorted($this->resolver->usersWithRoleOnObject(
                $roleId,
                $this->objectExtension,
                'widget',
                42,
                TestFixtures::SITE_SPACE_ID,
                20,
            )),
        );
    }

    #[Test]
    public function globalRoleReturnsOnlyItsOwnHolders(): void
    {
        $wantedRole = $this->permissionRole('USE', TestFixtures::SITE_SPACE_ID);
        $otherRole = $this->permissionRole('OTHER', TestFixtures::SITE_SPACE_ID);

        $wantedGroup = $this->group(TestFixtures::SITE_SPACE_ID);
        $otherGroup = $this->group(TestFixtures::SITE_SPACE_ID);
        $this->grant($wantedGroup, $wantedRole, TestFixtures::SITE_SPACE_ID);
        $this->grant($otherGroup, $otherRole, TestFixtures::SITE_SPACE_ID);

        $wanted = $this->user(TestFixtures::SITE_SPACE_ID);
        $other = $this->user(TestFixtures::SITE_SPACE_ID);
        $this->membership($wanted, $wantedGroup);
        $this->membership($other, $otherGroup);

        self::assertSame(
            [$wanted],
            $this->resolver->usersWithGlobalRole(
                $wantedRole,
                TestFixtures::SITE_SPACE_ID,
                20,
            ),
        );
    }

    /** @return list<int> */
    private function globalPermissionUsers(string $permission, int $spaceId, int $limit): array
    {
        return $this->sorted($this->resolver->usersWithGlobalPermission($permission, $spaceId, $limit));
    }

    /** @return list<int> */
    private function permissionUsersOnObject(string $permission, int $objectId, int $limit): array
    {
        return $this->sorted($this->resolver->usersWithPermissionOnObject(
            $permission,
            $this->objectExtension,
            'widget',
            $objectId,
            TestFixtures::SITE_SPACE_ID,
            $limit,
        ));
    }

    /** @param list<int> $ids @return list<int> */
    private function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }

    private function permissionRole(string $permission, int $spaceId): int
    {
        $db = $this->database->connection();
        $db->query("INSERT INTO acl_roles (space_id, user_defined, name, description, type) VALUES ("
            . $spaceId . ", 1, 'Rôle audience " . bin2hex(random_bytes(5)) . "', '', 0)");
        $roleId = (int) $db->insert_id;
        $this->roles[] = $roleId;

        $extension = $this->objectExtension;
        $statement = $db->prepare(
            'INSERT INTO acl_permissions (role_id, extension, name) VALUES (?, ?, ?)'
        );
        $statement->bind_param('iss', $roleId, $extension, $permission);
        $statement->execute();

        return $roleId;
    }

    private function group(int $spaceId, ?int $parentId = null): int
    {
        $db = $this->database->connection();
        $parent = $parentId === null ? 'NULL' : (string) $parentId;
        $db->query("INSERT INTO user_groups (space_id, parent_id, type, name) VALUES ("
            . $spaceId . ', ' . $parent . ", 0, 'Groupe audience " . bin2hex(random_bytes(4)) . "')");
        $groupId = (int) $db->insert_id;
        $this->groups[] = $groupId;

        return $groupId;
    }

    private function user(int $spaceId, bool $blocked = false, string $type = 'user'): int
    {
        $db = $this->database->connection();
        $statement = $db->prepare(
            "INSERT INTO users (space_id, type, first_name, last_name, email, password, timezone, blocked, params) "
            . "VALUES (?, ?, 'Audience', 'Test', ?, '', 'Europe/Paris', ?, '{}')"
        );
        $email = 'audience-' . bin2hex(random_bytes(6)) . '@integration.test';
        $blockedValue = $blocked ? 1 : 0;
        $statement->bind_param('issi', $spaceId, $type, $email, $blockedValue);
        $statement->execute();

        $userId = (int) $db->insert_id;
        $this->users[] = $userId;

        return $userId;
    }

    private function membership(int $userId, int $groupId): void
    {
        $this->database->connection()->query(
            'INSERT INTO user_groups_map (user_id, group_id) VALUES (' . $userId . ', ' . $groupId . ')',
        );
    }

    private function grant(int $groupId, int $roleId, int $spaceId): void
    {
        $this->database->connection()->query(
            'INSERT INTO acl_map (group_id, role_id, space_id) VALUES ('
            . $groupId . ', ' . $roleId . ', ' . $spaceId . ')',
        );
    }

    private function grantObject(int $groupId, int $roleId, int $spaceId, int $aclObjectId, ?int $objectId): void
    {
        $object = $objectId === null ? 'NULL' : (string) $objectId;
        $this->database->connection()->query(
            'INSERT INTO acl_map (group_id, role_id, space_id, acl_object_id, object_id) VALUES ('
            . $groupId . ', ' . $roleId . ', ' . $spaceId . ', ' . $aclObjectId . ', ' . $object . ')',
        );
    }

    /** @param list<int> $ids */
    private function deleteWhere(string $table, string $column, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $this->database->connection()->query(
            'DELETE FROM ' . $table . ' WHERE ' . $column . ' IN (' . implode(',', $ids) . ')',
        );
    }
}
