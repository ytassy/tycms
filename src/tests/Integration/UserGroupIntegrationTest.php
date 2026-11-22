<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\Application;
use YTassy\Tycms\Request\Request;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Tests\Support\Integration\TestDatabase;
use YTassy\Tycms\Tests\Support\Integration\TestEnvironment;
use YTassy\Tycms\Tests\Support\Integration\TestFixtures;

final class UserGroupIntegrationTest extends TestCase
{
    private TestEnvironment $environment;
    private TestDatabase $database;
    private array $sessions = [];
    private array $createdGroups = [];
    private array $createdRoleIds = [];
    private array $createdSystemMapIds = [];
    private ?int $editorUserId = null;
    private ?int $editorPersonalGroupId = null;
    private ?int $editorRoleId = null;
    private string $originalParams;
    private bool $addedRootGroupRoles = false;

    protected function setUp(): void
    {
        $this->environment = TestEnvironment::load();
        $this->database = new TestDatabase($this->environment);
        TestFixtures::assertPrepared($this->database);
        $this->originalParams = $this->database->connection()->query('SELECT params FROM spaces WHERE id = 2')
            ->fetch_assoc()['params'];

        // Les fixtures anciennes peuvent précéder GROUP_ROLES_ASSIGN : accorder la
        // permission au rôle Root pour tester le nouveau contrat sans migration.
        $db = $this->database->connection();
        $exists = $db->query("SELECT 1 FROM acl_permissions WHERE role_id = 2 "
            . "AND extension = 'plg_core' AND name = 'GROUP_ROLES_ASSIGN'")->fetch_row();
        if ($exists === null) {
            $db->query("INSERT INTO acl_permissions (role_id, extension, name) "
                . "VALUES (2, 'plg_core', 'GROUP_ROLES_ASSIGN')");
            $this->addedRootGroupRoles = true;
        }
    }

    protected function tearDown(): void
    {
        $db = $this->database->connection();
        $statement = $db->prepare('UPDATE spaces SET params = ? WHERE id = 2');
        $statement->bind_param('s', $this->originalParams);
        $statement->execute();

        if ($this->addedRootGroupRoles) {
            $db->query("DELETE FROM acl_permissions WHERE role_id = 2 "
                . "AND extension = 'plg_core' AND name = 'GROUP_ROLES_ASSIGN'");
        }

        foreach (array_reverse($this->createdGroups) as $groupId) {
            $db->query('DELETE FROM user_groups WHERE id = ' . $groupId);
        }
        foreach ($this->createdSystemMapIds as $mapId) {
            $db->query('DELETE FROM acl_map WHERE id = ' . $mapId);
        }
        // Retirer l'attribution de l'éditeur avant de supprimer son rôle.
        if ($this->editorPersonalGroupId !== null) {
            $db->query('DELETE FROM acl_map WHERE group_id = ' . $this->editorPersonalGroupId);
        }
        foreach ($this->createdRoleIds as $roleId) {
            $db->query('DELETE FROM acl_roles WHERE id = ' . $roleId);
        }
        if ($this->editorUserId !== null) {
            $db->query('DELETE FROM user_groups_map WHERE user_id = ' . $this->editorUserId);
            $db->query('DELETE FROM user_groups WHERE id = ' . $this->editorPersonalGroupId);
            $db->query('DELETE FROM users WHERE id = ' . $this->editorUserId);
        }
        foreach ($this->sessions as $key) {
            $statement = $db->prepare('DELETE FROM sessions WHERE `key` = ?');
            $statement->bind_param('s', $key);
            $statement->execute();
        }
    }

    #[Test]
    public function directoryExcludesPersonalGroupsAndProtectsSystemGroups(): void
    {
        $root = $this->session(1);
        $directory = $this->api('', RequestMethod::GET, $root);
        self::assertFalse($directory['error']);
        self::assertContains(1, array_column($directory['groups'], 'id'));
        self::assertNotContains(100, array_column($directory['groups'], 'id'));

        $systemChange = $this->api('update', RequestMethod::POST, $root, [
            'groupId' => 3,
            'data' => ['parent_id' => 0, 'name' => 'Autre', 'description' => ''],
        ]);
        self::assertTrue($systemChange['is_message']);

        $guest = $this->session(null);
        self::assertTrue($this->api('', RequestMethod::GET, $guest)['is_message']);
    }

    #[Test]
    public function createUpdateAndDeleteRespectHierarchyAndSpaceConfiguration(): void
    {
        $root = $this->session(1);
        $parent = $this->create($root, 2, 0, 'Parent test');
        $child = $this->create($root, 2, $parent, 'Enfant test');

        $invalidParent = $this->api('update', RequestMethod::POST, $root, [
            'groupId' => $parent,
            'data' => ['parent_id' => $child, 'name' => 'Parent test', 'description' => ''],
        ]);
        self::assertTrue($invalidParent['is_message']);

        $otherSpace = $this->api('update', RequestMethod::POST, $root, [
            'groupId' => $child,
            'data' => ['parent_id' => 3, 'name' => 'Enfant test', 'description' => ''],
        ]);
        self::assertTrue($otherSpace['is_message']);

        $params = json_decode($this->originalParams, true, flags: JSON_THROW_ON_ERROR);
        $params['registration_groups'] = [$child];
        $json = json_encode($params, JSON_THROW_ON_ERROR);
        $statement = $this->database->connection()->prepare('UPDATE spaces SET params = ? WHERE id = 2');
        $statement->bind_param('s', $json);
        $statement->execute();

        $protected = $this->api('delete', RequestMethod::POST, $root, ['groupId' => $child]);
        self::assertTrue($protected['is_message']);

        $statement->bind_param('s', $this->originalParams);
        $statement->execute();
        self::assertTrue($this->api('delete', RequestMethod::POST, $root, ['groupId' => $child])['deleted']);
        array_pop($this->createdGroups);
        self::assertTrue($this->api('delete', RequestMethod::POST, $root, ['groupId' => $parent])['deleted']);
        array_pop($this->createdGroups);
    }

    #[Test]
    public function groupCrudUsesTheOwnerSpaceAndRequiresCsrf(): void
    {
        $db = $this->database->connection();
        $db->query("INSERT INTO users (space_id, first_name, last_name, email, password, params) "
            . "VALUES (2, 'Groupe', 'Éditeur', 'group-editor-" . bin2hex(random_bytes(6))
            . "@integration.test', 'unused', '{}')");
        $this->editorUserId = (int) $db->insert_id;
        $db->query("INSERT INTO user_groups (space_id, parent_id, type, name) VALUES "
            . "(2, 2, 1, 'group-editor-" . $this->editorUserId . "')");
        $this->editorPersonalGroupId = (int) $db->insert_id;
        $db->query('INSERT INTO user_groups_map (user_id, group_id) VALUES ('
            . $this->editorUserId . ', ' . $this->editorPersonalGroupId . ')');
        $db->query('INSERT INTO acl_map (role_id, group_id, space_id) VALUES (4, '
            . $this->editorPersonalGroupId . ', 2)');

        $editor = $this->session($this->editorUserId);
        $groupId = $this->create($editor, 2, 0, 'Groupe Site');
        $directory = $this->api('', RequestMethod::GET, $editor);
        self::assertContains($groupId, array_column($directory['groups'], 'id'));
        self::assertNotContains(3, array_column($directory['groups'], 'id'));

        $rootCreate = $this->api('create', RequestMethod::POST, $editor, [
            'data' => ['space_id' => 1, 'parent_id' => 0, 'name' => 'Intrus', 'description' => ''],
        ]);
        self::assertTrue($rootCreate['is_message']);

        $withoutCsrf = $this->api('delete', RequestMethod::POST, $editor,
            ['groupId' => $groupId], false);
        self::assertTrue($withoutCsrf['error']);
        self::assertNotNull($db->query('SELECT id FROM user_groups WHERE id = ' . $groupId)->fetch_assoc());

        $rootRoles = $this->api('roles/update', RequestMethod::POST, $editor,
            ['groupId' => 3, 'roleIds' => [2, 4]]);
        self::assertTrue($rootRoles['is_message']);
    }

    #[Test]
    public function ordinaryGroupRolesMustBeGlobalAndOwnedByItsSpace(): void
    {
        $db = $this->database->connection();
        $db->query("INSERT INTO acl_roles (space_id, user_defined, name, description, type) "
            . "VALUES (2, 1, 'Rôle de groupe test', '', 0)");
        $globalRoleId = (int) $db->insert_id;
        $this->createdRoleIds[] = $globalRoleId;
        $db->query("INSERT INTO acl_roles (space_id, user_defined, name, description, type) "
            . "VALUES (2, 1, 'Rôle local test', '', 1)");
        $localRoleId = (int) $db->insert_id;
        $this->createdRoleIds[] = $localRoleId;
        $db->query("INSERT INTO acl_roles (space_id, user_defined, name, description, type) "
            . "VALUES (2, 1, 'Rôle mixte test', '', 2)");
        $mixedRoleId = (int) $db->insert_id;
        $this->createdRoleIds[] = $mixedRoleId;

        $root = $this->session(1);
        $created = $this->api('create', RequestMethod::POST, $root, [
            'data' => ['space_id' => 2, 'parent_id' => 0, 'name' => 'Groupe avec rôle',
                'description' => '', 'role_ids' => [$globalRoleId]],
        ]);
        self::assertFalse($created['error']);
        $groupId = (int) $created['group']['id'];
        $this->createdGroups[] = $groupId;
        self::assertSame([$globalRoleId], $created['group']['role_ids']);

        $directory = $this->api('', RequestMethod::GET, $root);
        self::assertContains($globalRoleId, array_column($directory['roles'], 'id'));
        self::assertContains($mixedRoleId, array_column($directory['roles'], 'id'));
        self::assertNotContains($localRoleId, array_column($directory['roles'], 'id'));
        $group = array_values(array_filter($directory['groups'],
            static fn (array $candidate): bool => $candidate['id'] === $groupId))[0];
        self::assertSame('Rôle de groupe test', $group['roles'][0]['name']);

        foreach ([$localRoleId, 2] as $invalidRoleId) {
            $rejected = $this->api('update', RequestMethod::POST, $root, [
                'groupId' => $groupId,
                'data' => ['parent_id' => 0, 'name' => 'Groupe avec rôle',
                    'description' => '', 'role_ids' => [$invalidRoleId]],
            ]);
            self::assertTrue($rejected['is_message']);
        }
        self::assertSame(1, (int) $db->query('SELECT COUNT(*) AS total FROM acl_map WHERE group_id = '
            . $groupId . ' AND role_id = ' . $globalRoleId)->fetch_assoc()['total']);

        $mixed = $this->api('update', RequestMethod::POST, $root, [
            'groupId' => $groupId,
            'data' => ['parent_id' => 0, 'name' => 'Groupe avec rôle',
                'description' => '', 'role_ids' => [$globalRoleId, $mixedRoleId]],
        ]);
        self::assertFalse($mixed['error']);
        self::assertSame(1, (int) $db->query('SELECT COUNT(*) AS total FROM acl_map WHERE group_id = '
            . $groupId . ' AND role_id = ' . $globalRoleId)->fetch_assoc()['total']);

        // Une attribution historique hors du Space propriétaire reste intacte.
        $db->query('INSERT INTO acl_map (role_id, group_id, space_id) VALUES (2, '
            . $groupId . ', 2)');
        $updated = $this->api('update', RequestMethod::POST, $root, [
            'groupId' => $groupId,
            'data' => ['parent_id' => 0, 'name' => 'Groupe avec rôle',
                'description' => '', 'role_ids' => []],
        ]);
        self::assertFalse($updated['error']);
        self::assertSame([], $updated['group']['role_ids']);
        self::assertContains(2, array_column($updated['group']['roles'], 'id'));
        self::assertSame(1, (int) $db->query('SELECT COUNT(*) AS total FROM acl_map WHERE group_id = '
            . $groupId . ' AND role_id = 2')->fetch_assoc()['total']);
    }

    #[Test]
    public function groupRolesUseTheirOwnPermissionAndDelegation(): void
    {
        $root = $this->session(1);
        $groupId = $this->create($root, 2, 0, 'Groupe délégation');
        $harmless = $this->createRole([]);
        [$editor] = $this->createEditor(['USER_GROUPS_READ', 'USER_GROUPS_CRUD', 'ROLE_READ',
            'GROUP_ROLES_ASSIGN']);

        // Sans GROUP_ROLES_ASSIGN, le groupe reste éditable mais ses rôles sont figés.
        $this->setEditorPermissions(['USER_GROUPS_READ', 'USER_GROUPS_CRUD', 'ROLE_READ']);
        $renamed = $this->api('update', RequestMethod::POST, $editor, [
            'groupId' => $groupId,
            'data' => ['parent_id' => 0, 'name' => 'Groupe renommé', 'description' => ''],
        ]);
        self::assertFalse($renamed['error']);
        self::assertSame('Groupe renommé', $renamed['group']['name']);
        self::assertTrue($this->api('roles/update', RequestMethod::POST, $editor,
            ['groupId' => $groupId, 'roleIds' => [$harmless]])['is_message']);

        // Sans USER_GROUPS_CRUD, les rôles restent modifiables mais le groupe est figé.
        $this->setEditorPermissions(['USER_GROUPS_READ', 'ROLE_READ', 'GROUP_ROLES_ASSIGN']);
        self::assertTrue($this->api('update', RequestMethod::POST, $editor, [
            'groupId' => $groupId,
            'data' => ['parent_id' => 0, 'name' => 'Encore renommé', 'description' => ''],
        ])['is_message']);
        $assigned = $this->api('roles/update', RequestMethod::POST, $editor,
            ['groupId' => $groupId, 'roleIds' => [$harmless]]);
        self::assertFalse($assigned['error']);
        self::assertSame([$harmless], $assigned['group']['role_ids']);

        // Un rôle plus puissant que l'éditeur ne peut pas être délégué.
        $powerful = $this->createRole(['USERS_READ', 'USERS_CRUD']);
        self::assertTrue($this->api('roles/update', RequestMethod::POST, $editor,
            ['groupId' => $groupId, 'roleIds' => [$harmless, $powerful]])['is_message']);

        // Le déplacement sous un parent plus puissant est également refusé.
        $this->setEditorPermissions(['USER_GROUPS_READ', 'USER_GROUPS_CRUD', 'ROLE_READ',
            'GROUP_ROLES_ASSIGN']);
        $parent = $this->create($root, 2, 0, 'Parent puissant');
        $this->api('roles/update', RequestMethod::POST, $root,
            ['groupId' => $parent, 'roleIds' => [$powerful]]);
        self::assertTrue($this->api('create', RequestMethod::POST, $editor, ['data' => [
            'space_id' => 2, 'parent_id' => $parent, 'name' => 'Enfant interdit',
            'description' => '',
        ]])['is_message']);

        // Sans ROLE_READ, les rôles attribués ne sont plus transmis.
        $this->setEditorPermissions(['USER_GROUPS_READ']);
        $directory = $this->api('', RequestMethod::GET, $editor);
        $visible = array_values(array_filter($directory['groups'],
            static fn (array $group): bool => $group['id'] === $groupId));
        self::assertCount(1, $visible);
        self::assertSame([], $visible[0]['roles']);
        self::assertSame([], $visible[0]['role_ids']);
        self::assertFalse($visible[0]['can_assign_roles']);
    }

    #[Test]
    public function implicitGroupLinksAreFixedAndTheirRolesCannotBeAssignedElsewhere(): void
    {
        $root = $this->session(1);
        $directory = $this->api('', RequestMethod::GET, $root);
        $groups = array_column($directory['groups'], null, 'id');
        foreach ([1 => 1, 2 => 3] as $groupId => $roleId) {
            self::assertFalse($groups[$groupId]['can_edit']);
            self::assertFalse($groups[$groupId]['can_assign_roles']);
            self::assertNotEmpty($groups[$groupId]['assignment_notice']);
            self::assertContains($roleId, $groups[$groupId]['role_ids']);
            // Ni le retrait du lien système, ni un rôle supplémentaire ne sont autorisés.
            foreach ([[], [$roleId], [$roleId, 5]] as $roleIds) {
                self::assertTrue($this->api('roles/update', RequestMethod::POST, $root,
                    ['groupId' => $groupId, 'roleIds' => $roleIds])['is_message']);
            }
        }
        foreach ([1, 3] as $roleId) {
            self::assertNotContains($roleId, array_column($directory['roles'], 'id'));
            self::assertTrue($this->api('create', RequestMethod::POST, $root, ['data' => [
                'space_id' => 1, 'parent_id' => 0, 'name' => 'Interdit ' . $roleId,
                'description' => '', 'role_ids' => [$roleId],
            ]])['is_message']);
        }
        $groupId = $this->create($root, 1, 0, 'Groupe sans rôle implicite');
        foreach ([1, 3] as $roleId) {
            self::assertTrue($this->api('roles/update', RequestMethod::POST, $root,
                ['groupId' => $groupId, 'roleIds' => [$roleId]])['is_message']);
            self::assertTrue($this->api('update', RequestMethod::POST, $root, ['groupId' => $groupId, 'data' => [
                'parent_id' => 0, 'name' => 'Groupe sans rôle implicite', 'description' => '', 'role_ids' => [$roleId],
            ]])['is_message']);
        }
        $rejected = $this->api('roles/update', RequestMethod::POST, $root, ['groupId' => 3, 'roleIds' => [4]]);
        self::assertTrue($rejected['is_message']);
        foreach ([1 => 1, 2 => 3, 3 => 2] as $groupId => $roleId) {
            self::assertSame(1, (int) $this->database->connection()->query(
                'SELECT COUNT(*) AS total FROM acl_map WHERE group_id = ' . $groupId
                . ' AND role_id = ' . $roleId . ' AND space_id = 1')->fetch_assoc()['total']);
        }
    }

    /** @param list<string> $permissions @return array{array, int} */
    private function createEditor(array $permissions, int $spaceId = 2): array
    {
        $db = $this->database->connection();
        $email = 'group-editor-' . bin2hex(random_bytes(6)) . '@integration.test';
        $db->query("INSERT INTO users (space_id, first_name, last_name, email, password, params) "
            . "VALUES (" . $spaceId . ", 'Groupe', 'Éditeur', '" . $email . "', 'unused', '{}')");
        $this->editorUserId = (int) $db->insert_id;

        $db->query("INSERT INTO user_groups (space_id, parent_id, type, name) VALUES ("
            . $spaceId . ", 2, 1, 'group-editor-" . $this->editorUserId . "')");
        $this->editorPersonalGroupId = (int) $db->insert_id;
        $db->query('INSERT INTO user_groups_map (user_id, group_id) VALUES ('
            . $this->editorUserId . ', ' . $this->editorPersonalGroupId . ')');

        $db->query("INSERT INTO acl_roles (space_id, user_defined, name, description, type) "
            . "VALUES (" . $spaceId . ", 1, 'Rôle éditeur groupes " . $this->editorUserId . "', '', 0)");
        $this->editorRoleId = (int) $db->insert_id;
        $this->createdRoleIds[] = $this->editorRoleId;
        $this->setEditorPermissions($permissions);

        $db->query('INSERT INTO acl_map (role_id, group_id, space_id) VALUES ('
            . $this->editorRoleId . ', ' . $this->editorPersonalGroupId . ', ' . $spaceId . ')');

        return [$this->session($this->editorUserId), $this->editorRoleId];
    }

    /** @param list<string> $permissions */
    private function setEditorPermissions(array $permissions): void
    {
        $db = $this->database->connection();
        if ($this->editorRoleId === null) return;

        $db->query('DELETE FROM acl_permissions WHERE role_id = ' . $this->editorRoleId);
        foreach ($permissions as $permission) {
            $db->query("INSERT INTO acl_permissions (role_id, extension, name) VALUES ("
                . $this->editorRoleId . ", 'plg_core', '" . $permission . "')");
        }
    }

    /** @param list<string> $permissions */
    private function createRole(array $permissions, int $spaceId = 2): int
    {
        $db = $this->database->connection();
        $db->query("INSERT INTO acl_roles (space_id, user_defined, name, description, type) "
            . "VALUES (" . $spaceId . ", 1, 'Rôle test " . bin2hex(random_bytes(5)) . "', '', 0)");
        $roleId = (int) $db->insert_id;
        $this->createdRoleIds[] = $roleId;
        foreach ($permissions as $permission) {
            $db->query("INSERT INTO acl_permissions (role_id, extension, name) VALUES ("
                . $roleId . ", 'plg_core', '" . $permission . "')");
        }

        return $roleId;
    }

    private function create(array $session, int $spaceId, int $parentId, string $name): int
    {
        $response = $this->api('create', RequestMethod::POST, $session, [
            'data' => ['space_id' => $spaceId, 'parent_id' => $parentId,
                'name' => $name, 'description' => ''],
        ]);
        self::assertFalse($response['error']);
        $groupId = (int) $response['group']['id'];
        $this->createdGroups[] = $groupId;

        return $groupId;
    }

    private function session(?int $userId): array
    {
        $key = bin2hex(random_bytes(24));
        $csrf = bin2hex(random_bytes(32));
        $data = serialize(['user_id' => $userId, 'current_space_id' => 2, 'csrf_token' => $csrf]);
        $statement = $this->database->connection()->prepare(
            'INSERT INTO sessions (`key`, `user_id`, `data`) VALUES (?, ?, ?)'
        );
        $statement->bind_param('sis', $key, $userId, $data);
        $statement->execute();
        $this->sessions[] = $key;

        return [$key, $csrf];
    }

    private function api(string $path, RequestMethod $method, array $session, array $body = [],
        bool $withCsrf = true): array
    {
        [$key, $csrf] = $session;
        if (isset($body['data'])) {
            $body['data'] = json_encode($body['data'], JSON_THROW_ON_ERROR);
        }
        if (isset($body['roleIds'])) {
            $body['roleIds'] = json_encode($body['roleIds'], JSON_THROW_ON_ERROR);
        }

        $request = new Request(
            method: $method,
            uri: '/administrator/api/user-groups' . ($path === '' ? '' : '/' . $path),
            body: $body,
            headers: $withCsrf ? ['X-CSRF-Token' => $csrf] : [],
            cookies: ['session_key' => $key],
            host: TestFixtures::SITE_HOST,
        );
        $application = new Application($this->environment->configuration());
        $application->initialiseOrThrow($request);

        return json_decode($application->executeAndGetResponse()->body ?? '', true, flags: JSON_THROW_ON_ERROR);
    }
}
