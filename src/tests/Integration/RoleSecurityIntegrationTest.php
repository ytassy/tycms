<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Core\Helper\Acl\AclHelper;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\BasePermissions;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\PermDef;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\UiGroup;
use YTassy\Tycms\CoreExts\Core\WF\WFRole;
use YTassy\Tycms\Request\Request;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Tests\Support\Integration\TestDatabase;
use YTassy\Tycms\Tests\Support\Integration\TestEnvironment;
use YTassy\Tycms\Tests\Support\Integration\TestFixtures;

final class RoleSecurityIntegrationTest extends TestCase
{
    private TestEnvironment $environment;
    private TestDatabase $database;
    private int $editorUserId;
    private int $editorGroupId;
    private array $sessionKeys = [];
    private array $createdRoleIds = [];
    private array $rootSession;
    private array $editorSession;

    protected function setUp(): void
    {
        $this->environment = TestEnvironment::load();
        $this->database = new TestDatabase($this->environment);
        TestFixtures::assertPrepared($this->database);

        $db = $this->database->connection();
        $db->query("INSERT INTO users (space_id, first_name, last_name, email, password, params) "
            . "VALUES (2, 'Role', 'Editor', 'role-editor-" . bin2hex(random_bytes(6)) . "@integration.test', 'unused', '{}')");
        $this->editorUserId = (int) $db->insert_id;
        $db->query("INSERT INTO user_groups (space_id, parent_id, type, name) VALUES "
            . "(2, 2, 1, 'role-editor-" . $this->editorUserId . "')");
        $this->editorGroupId = (int) $db->insert_id;
        $db->query('INSERT INTO user_groups_map (user_id, group_id) VALUES ('
            . $this->editorUserId . ', ' . $this->editorGroupId . ')');
        $db->query('INSERT INTO acl_map (role_id, group_id, space_id) VALUES (4, '
            . $this->editorGroupId . ', 2)');

        $this->rootSession = $this->session(1);
        $this->editorSession = $this->session($this->editorUserId);
    }

    protected function tearDown(): void
    {
        $db = $this->database->connection();

        // Nettoyer les seules données créées par ce scénario.
        foreach ($this->createdRoleIds as $roleId) {
            $db->query('DELETE FROM acl_permissions WHERE role_id = ' . $roleId);
            $db->query('DELETE FROM acl_map WHERE role_id = ' . $roleId);
            $db->query('DELETE FROM acl_roles WHERE id = ' . $roleId);
        }
        foreach ($this->sessionKeys as $key) {
            $statement = $db->prepare('DELETE FROM sessions WHERE `key` = ?');
            $statement->bind_param('s', $key);
            $statement->execute();
        }
        $db->query('DELETE FROM acl_map WHERE group_id = ' . $this->editorGroupId);
        $db->query('DELETE FROM user_groups_map WHERE group_id = ' . $this->editorGroupId);
        $db->query('DELETE FROM user_groups WHERE id = ' . $this->editorGroupId);
        $db->query('DELETE FROM users WHERE id = ' . $this->editorUserId);
    }

    #[Test]
    public function implicitRolesKeepTheirIdentityButTheirPermissionsRemainEditable(): void
    {
        foreach ([1, 3] as $roleId) {
            $role = $this->role($roleId);
            self::assertTrue($role['can_edit']);
            self::assertFalse($role['can_edit_details']);
            self::assertNotEmpty($role['assignment_notice']);
            foreach (['name', 'description'] as $field) {
                $data = ['name' => $role['name'], 'description' => $role['description'], 'permissions' => $role['permissions']];
                $data[$field] .= ' modifié';
                self::assertTrue($this->api('update', RequestMethod::POST, $this->rootSession,
                    ['roleId' => $roleId, 'data' => $data])['is_message']);
            }
            $permissions = $role['permissions'];
            $permission = Permissions::AI_USE;
            $changed = in_array($permission, $permissions, true)
                ? array_values(array_diff($permissions, [$permission])) : [...$permissions, $permission];
            try {
                $saved = $this->api('update', RequestMethod::POST, $this->rootSession, ['roleId' => $roleId, 'data' => [
                    'name' => $role['name'], 'description' => $role['description'], 'permissions' => $changed,
                ]]);
                self::assertFalse($saved['is_message'], json_encode($saved));
                sort($changed);
                self::assertSame($changed, $saved['role']['permissions']);
            } finally {
                // Restaurer le rôle système du banc, même si une assertion échoue.
                $db = $this->database->connection();
                $db->query('DELETE FROM acl_permissions WHERE role_id = ' . $roleId);
                foreach ($permissions as $fullName) {
                    [$extension, $name] = explode('__', $fullName, 2);
                    $statement = $db->prepare('INSERT INTO acl_permissions (role_id, extension, name) VALUES (?, ?, ?)');
                    $statement->bind_param('iss', $roleId, $extension, $name);
                    $statement->execute();
                }
            }
        }
    }

    #[Test]
    public function directoryKeepsServerAlphabeticalOrder(): void
    {
        $directory = $this->api('', RequestMethod::GET, $this->rootSession);
        $systemNames = [];
        foreach ($directory['roles'] as $role) {
            if ($role['technical_name'] !== null) $systemNames[] = $role['name'];
        }

        self::assertSame([
            'Administrateur', 'Invité', 'Membre', 'Super Admin', 'Utilisateur',
        ], $systemNames);

        $catalog = array_column($directory['permissions'], null, 'name');
        self::assertSame('Administration', $catalog[Permissions::ROOT]['tab']);
        self::assertSame('Accès système', $catalog[Permissions::ROOT]['group']);
        self::assertSame('Site', $catalog[Permissions::MENU_CRUD]['tab']);
        self::assertSame('Menus', $catalog[Permissions::MENU_CRUD]['group']);
    }

    #[Test]
    public function componentPermissionsUseTheirUiGroupOrTheExtensionFallback(): void
    {
        $request = new Request(uri: '/administrator/api/roles',
            host: TestFixtures::SITE_HOST, cookies: ['session_key' => $this->rootSession[0]]);
        $application = new Application($this->environment->configuration());
        $application->initialiseOrThrow($request);

        // Simuler une définition fournie par un composant d'application.
        $definitions = (new ComponentUiPermissions($application))->getPermissions();
        $application->getOrAddSharedExtensionsClass(AclHelper::class)
            ->definePermissionsDefinition('comp_demo', $definitions);
        $directory = $application->getOrAddSharedExtensionsClass(WFRole::class)->getDirectory();
        $catalog = array_column($directory['permissions'], null, 'name');

        self::assertSame('Rapports', $catalog[ComponentUiPermissions::READ]['tab']);
        self::assertSame('Consultation', $catalog[ComponentUiPermissions::READ]['group']);
        self::assertSame('Extensions', $catalog[ComponentUiPermissions::LEGACY]['tab']);
        self::assertSame('comp_demo', $catalog[ComponentUiPermissions::LEGACY]['group']);
    }

    #[Test]
    public function rootCanManageRoleButCannotRemoveMemberFromRoleFive(): void
    {
        $created = $this->api('create', RequestMethod::POST, $this->rootSession, [
            'data' => ['space_id' => 2, 'type' => 0, 'name' => 'Test rôle',
                'description' => 'Essai', 'permissions' => [Permissions::MEMBER]],
        ]);
        self::assertFalse($created['error']);
        self::assertNull($created['role']['technical_name']);
        $roleId = (int) $created['role']['id'];
        $this->createdRoleIds[] = $roleId;

        $updated = $this->api('update', RequestMethod::POST, $this->rootSession, [
            'roleId' => $roleId,
            'data' => ['name' => 'Test rôle modifié', 'description' => '',
                'permissions' => [Permissions::MEMBER, Permissions::PAGE_READ]],
        ]);
        self::assertFalse($updated['error']);
        self::assertSame([Permissions::MEMBER, Permissions::PAGE_READ], $updated['role']['permissions']);

        $member = $this->role(5);
        self::assertSame('ROLE_MEMBER', $member['technical_name']);
        $rejected = $this->api('update', RequestMethod::POST, $this->rootSession, [
            'roleId' => 5,
            'data' => ['name' => $member['name'], 'description' => $member['description'],
                'permissions' => []],
        ]);
        self::assertTrue($rejected['is_message']);
        self::assertContains(Permissions::MEMBER, $this->role(5)['permissions']);

        $tampered = $this->api('update', RequestMethod::POST, $this->rootSession, [
            'roleId' => 5,
            'data' => ['name' => $member['name'], 'description' => $member['description'],
                'permissions' => $member['permissions'], 'space_id' => 2, 'type' => 1],
        ]);
        self::assertTrue(($tampered['error'] ?? false) || ($tampered['is_message'] ?? false));
        self::assertSame(1, $this->role(5)['space_id']);
        self::assertSame(0, $this->role(5)['type']);

        $rootRole = $this->role(2);
        $rootRemoval = $this->api('update', RequestMethod::POST, $this->rootSession, [
            'roleId' => 2,
            'data' => ['name' => $rootRole['name'], 'description' => $rootRole['description'],
                'permissions' => array_values(array_diff($rootRole['permissions'], [Permissions::ROOT]))],
        ]);
        self::assertTrue($rootRemoval['is_message']);
        self::assertContains(Permissions::ROOT, $this->role(2)['permissions']);

        $systemDelete = $this->api('delete', RequestMethod::POST, $this->rootSession, ['roleId' => 5]);
        self::assertTrue($systemDelete['is_message']);
        self::assertNotNull($this->database->connection()->query('SELECT id FROM acl_roles WHERE id = 5')->fetch_assoc());

        $deleted = $this->api('delete', RequestMethod::POST, $this->rootSession, ['roleId' => $roleId]);
        self::assertTrue($deleted['deleted']);
    }

    #[Test]
    public function roleCrudCannotGrantOrRemoveReservedPermissions(): void
    {
        $created = $this->api('create', RequestMethod::POST, $this->editorSession, [
            'data' => ['space_id' => 2, 'type' => 0, 'name' => 'Édition limitée',
                'description' => '', 'permissions' => [Permissions::MENU_READ,
                    Permissions::PAGE_READ, Permissions::MENU_CRUD]],
        ]);
        self::assertFalse($created['error']);
        $roleId = (int) $created['role']['id'];
        $this->createdRoleIds[] = $roleId;

        foreach ([Permissions::ROOT, Permissions::ROLE_CRUD, Permissions::USERS_CRUD,
            Permissions::SPACE_ADMIN] as $forbidden) {
            $attempt = $this->api('update', RequestMethod::POST, $this->editorSession, [
                'roleId' => $roleId,
                'data' => ['name' => 'Édition limitée', 'description' => '',
                    'permissions' => [Permissions::MENU_READ, Permissions::PAGE_READ,
                        Permissions::MENU_CRUD, $forbidden]],
            ]);
            self::assertTrue($attempt['is_message'], $forbidden);
            self::assertSame([Permissions::MENU_CRUD, Permissions::MENU_READ, Permissions::PAGE_READ],
                $this->role($roleId)['permissions']);
        }

        // ROLE_CRUD sur le Site ne donne aucun droit sur les rôles de Root.
        $rootRole = $this->role(5);
        $outside = $this->api('update', RequestMethod::POST, $this->editorSession, [
            'roleId' => 5,
            'data' => ['name' => $rootRole['name'], 'description' => $rootRole['description'],
                'permissions' => $rootRole['permissions']],
        ]);
        self::assertTrue($outside['is_message']);
    }

    #[Test]
    public function roleCrudCannotRemoveItsOwnReservedGrantOrBypassCsrf(): void
    {
        $created = $this->api('create', RequestMethod::POST, $this->rootSession, [
            'data' => ['space_id' => 2, 'type' => 0, 'name' => 'Rôle protégé',
                'description' => '', 'permissions' => [Permissions::ROLE_READ,
                    Permissions::ROLE_CRUD, Permissions::MENU_READ, Permissions::PAGE_READ,
                    Permissions::MENU_CRUD]],
        ]);
        $roleId = (int) $created['role']['id'];
        $this->createdRoleIds[] = $roleId;

        $attempt = $this->api('update', RequestMethod::POST, $this->editorSession, [
            'roleId' => $roleId,
            'data' => ['name' => 'Rôle protégé', 'description' => '',
                'permissions' => [Permissions::MENU_READ, Permissions::PAGE_READ,
                    Permissions::MENU_CRUD]],
        ]);
        self::assertTrue($attempt['is_message']);
        self::assertContains(Permissions::ROLE_CRUD, $this->role($roleId)['permissions']);

        $noCsrf = $this->api('delete', RequestMethod::POST, $this->rootSession,
            ['roleId' => $roleId], false);
        self::assertTrue($noCsrf['error']);
        self::assertNotNull($this->database->connection()->query('SELECT id FROM acl_roles WHERE id = '
            . $roleId)->fetch_assoc());
    }

    #[Test]
    public function assignedRoleCannotBeDeletedAndForeignAssignmentBlocksPermissionChange(): void
    {
        $created = $this->api('create', RequestMethod::POST, $this->rootSession, [
            'data' => ['space_id' => 2, 'type' => 0, 'name' => 'Rôle attribué',
                'description' => '', 'permissions' => [Permissions::MENU_READ,
                    Permissions::PAGE_READ, Permissions::MENU_CRUD]],
        ]);
        $roleId = (int) $created['role']['id'];
        $this->createdRoleIds[] = $roleId;

        // Une attribution forgée sur Root ne doit pas permettre d'y étendre
        // les permissions via ROLE_CRUD possédé uniquement sur le Site.
        $this->database->connection()->query('INSERT INTO acl_map (role_id, group_id, space_id) VALUES ('
            . $roleId . ', 3, 1)');

        $change = $this->api('update', RequestMethod::POST, $this->editorSession, [
            'roleId' => $roleId,
            'data' => ['name' => 'Rôle attribué', 'description' => '',
                'permissions' => [Permissions::MENU_READ, Permissions::PAGE_READ,
                    Permissions::MENU_CRUD, Permissions::PAGE_CRUD]],
        ]);
        self::assertTrue($change['is_message']);
        self::assertSame([Permissions::MENU_CRUD, Permissions::MENU_READ, Permissions::PAGE_READ],
            $this->role($roleId)['permissions']);

        $delete = $this->api('delete', RequestMethod::POST, $this->rootSession, ['roleId' => $roleId]);
        self::assertTrue($delete['is_message']);
        self::assertNotNull($this->database->connection()->query('SELECT id FROM acl_roles WHERE id = '
            . $roleId)->fetch_assoc());
    }

    #[Test]
    public function anonymousCallerCannotReadOrCreateRoles(): void
    {
        $guest = $this->session(null);
        $read = $this->api('', RequestMethod::GET, $guest);
        self::assertTrue($read['is_message']);

        $write = $this->api('create', RequestMethod::POST, $guest, [
            'data' => ['space_id' => 2, 'type' => 0, 'name' => 'Intrus',
                'description' => '', 'permissions' => []],
        ]);
        self::assertTrue($write['is_message']);
    }

    private function role(int $id): array
    {
        $directory = $this->api('', RequestMethod::GET, $this->rootSession);
        foreach ($directory['roles'] as $role) {
            if ($role['id'] === $id) return $role;
        }
        self::fail('Rôle introuvable dans le répertoire.');
    }

    /** @return array{string,string} */
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
        $this->sessionKeys[] = $key;

        return [$key, $csrf];
    }

    private function api(string $path, RequestMethod $method, array $session, array $body = [], bool $withCsrf = true): array
    {
        [$key, $csrf] = $session;
        if (isset($body['data'])) {
            $body['data'] = json_encode($body['data'], JSON_THROW_ON_ERROR);
        }

        $request = new Request(
            method: $method,
            uri: '/administrator/api/roles' . ($path === '' ? '' : '/' . $path),
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

final class ComponentUiPermissions extends BasePermissions
{
    #[PermDef('Voir les rapports', '')]
    #[UiGroup('Rapports', 'Consultation')]
    public const READ = 'comp_demo__READ';

    #[PermDef('Permission héritée', '')]
    public const LEGACY = 'comp_demo__LEGACY';
}
