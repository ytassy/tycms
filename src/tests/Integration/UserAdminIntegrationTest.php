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

final class UserAdminIntegrationTest extends TestCase
{
    private TestEnvironment $environment;
    private TestDatabase $database;
    private array $sessions = [];
    private array $users = [];
    private array $groups = [];
    private array $roles = [];
    private array $rootSession;
    private array $editorSession;
    private int $editorUserId;

    protected function setUp(): void
    {
        $this->environment = TestEnvironment::load();
        $this->database = new TestDatabase($this->environment);
        TestFixtures::assertPrepared($this->database);
        $this->rootSession = $this->session(1);

        // L'éditeur du Site possède USERS_CRUD et USER_ROLES_CRUD sur le Site,
        // mais aucun droit d'attribution sur l'espace racine.
        $editor = $this->create($this->rootSession, 'editeur-' . bin2hex(random_bytes(4)) . '@integration.test');
        $editorId = (int) $editor['id'];
        $this->editorUserId = $editorId;
        $groupId = $this->personalGroup($editorId);
        $this->database->connection()->query('INSERT INTO acl_map (group_id, role_id, space_id) VALUES ('
            . $groupId . ', 4, 2)');
        $this->editorSession = $this->session($editorId);
    }

    protected function tearDown(): void
    {
        $db = $this->database->connection();
        foreach ($this->sessions as $key) {
            $statement = $db->prepare('DELETE FROM sessions WHERE `key` = ?');
            $statement->bind_param('s', $key);
            $statement->execute();
        }
        foreach ($this->users as $id) {
            $personalGroup = $db->query('SELECT ug.id FROM user_groups ug '
                . 'INNER JOIN user_groups_map gm ON gm.group_id = ug.id '
                . 'WHERE ug.type = 1 AND gm.user_id = ' . $id)->fetch_assoc();
            $db->query('DELETE FROM users WHERE id = ' . $id);
            if ($personalGroup !== null) $db->query('DELETE FROM user_groups WHERE id = ' . (int) $personalGroup['id']);
        }
        foreach ($this->groups as $id) $db->query('DELETE FROM user_groups WHERE id = ' . $id);
        foreach ($this->roles as $id) $db->query('DELETE FROM acl_roles WHERE id = ' . $id);
    }

    #[Test]
    public function userAttributionDataRequiresTheirReadPermissions(): void
    {
        $target = $this->create($this->rootSession,
            'lecture-attributions-' . bin2hex(random_bytes(5)) . '@integration.test');
        $groupId = $this->matrixGroup(2);
        $db = $this->database->connection();
        $db->query('INSERT INTO acl_map (group_id, role_id, space_id) VALUES (' . $groupId . ', 4, 2)');
        self::assertFalse($this->api('group', RequestMethod::POST, $this->rootSession,
            ['userId' => $target['id'], 'groupId' => $groupId, 'assigned' => '1'])['is_message']);

        $manager = $this->create($this->rootSession,
            'lecteur-attributions-' . bin2hex(random_bytes(5)) . '@integration.test');
        $managerId = (int) $manager['id'];
        $roleId = $this->matrixRole(['USERS_READ']);
        $personal = $this->personalGroup($managerId);
        $db->query('DELETE FROM acl_map WHERE group_id = ' . $personal);
        $db->query('INSERT INTO acl_map (group_id, role_id, space_id) VALUES ('
            . $personal . ', ' . $roleId . ', 2)');
        $managerSession = $this->session($managerId);

        // La fiche et la liste restent accessibles avec USERS_READ, sans les attributions.
        $read = $this->api('read', RequestMethod::GET, $managerSession, ['userId' => $target['id']]);
        self::assertFalse($read['error']);
        self::assertSame([], $read['user']['groups']);
        self::assertSame([], $read['user']['roles']);
        self::assertSame([], $read['user']['spaces']);
        $list = $this->api('', RequestMethod::GET, $managerSession,
            ['page' => '1', 'perPage' => '10', 'search' => $target['email']]);
        self::assertSame([], $list['users'][0]['groups']);
        self::assertSame([], $list['users'][0]['roles']);

        // USER_GROUPS_READ ouvre les groupes sans les rôles.
        $db->query("INSERT INTO acl_permissions (role_id, extension, name) VALUES ("
            . $roleId . ", 'plg_core', 'USER_GROUPS_READ')");
        $read = $this->api('read', RequestMethod::GET, $managerSession, ['userId' => $target['id']]);
        self::assertNotSame([], $read['user']['groups']);
        self::assertSame([], $read['user']['roles']);

        // USER_ROLES_READ ouvre les rôles et les espaces d'appartenance.
        $db->query("INSERT INTO acl_permissions (role_id, extension, name) VALUES ("
            . $roleId . ", 'plg_core', 'USER_ROLES_READ')");
        $read = $this->api('read', RequestMethod::GET, $managerSession, ['userId' => $target['id']]);
        self::assertNotSame([], $read['user']['roles']);
        self::assertNotSame([], $read['user']['spaces']);
    }

    #[Test]
    public function roleAndGroupAssignmentsRequireDelegation(): void
    {
        $target = $this->create($this->rootSession,
            'cible-delegation-' . bin2hex(random_bytes(5)) . '@integration.test');
        $manager = $this->create($this->rootSession,
            'delegue-' . bin2hex(random_bytes(5)) . '@integration.test');
        $managerId = (int) $manager['id'];
        $roleId = $this->matrixRole(['USERS_READ', 'USER_ROLES_READ', 'USER_ROLES_CRUD',
            'USER_GROUPS_READ', 'USER_GROUPS_ASSIGN', 'ROLE_READ']);
        $personal = $this->personalGroup($managerId);
        $db = $this->database->connection();
        $db->query('DELETE FROM acl_map WHERE group_id = ' . $personal);
        $db->query('INSERT INTO acl_map (group_id, role_id, space_id) VALUES ('
            . $personal . ', ' . $roleId . ', 2)');
        $managerSession = $this->session($managerId);

        // Un rôle plus puissant que le gestionnaire ne peut pas être attribué.
        $powerful = $this->matrixRole(['USERS_READ', 'USERS_CRUD']);
        self::assertTrue($this->api('role', RequestMethod::POST, $managerSession, [
            'userId' => $target['id'], 'roleId' => $powerful, 'spaceId' => 2, 'assigned' => '1',
        ])['is_message']);

        // Un rôle sans permission reste délégable.
        $harmless = $this->matrixRole([]);
        $allowed = $this->api('role', RequestMethod::POST, $managerSession, [
            'userId' => $target['id'], 'roleId' => $harmless, 'spaceId' => 2, 'assigned' => '1',
        ]);
        self::assertFalse($allowed['is_message'] ?? false);
        self::assertContains($harmless, array_column($allowed['user']['roles'], 'id'));

        // Un groupe porteur d'un rôle puissant est refusé.
        $powerfulGroup = $this->matrixGroup(2);
        $db->query('INSERT INTO acl_map (group_id, role_id, space_id) VALUES ('
            . $powerfulGroup . ', ' . $powerful . ', 2)');
        self::assertTrue($this->api('group', RequestMethod::POST, $managerSession, [
            'userId' => $target['id'], 'groupId' => $powerfulGroup, 'assigned' => '1',
        ])['is_message']);

        // Un groupe sans rôle reste attribuable.
        $harmlessGroup = $this->matrixGroup(2);
        $allowed = $this->api('group', RequestMethod::POST, $managerSession, [
            'userId' => $target['id'], 'groupId' => $harmlessGroup, 'assigned' => '1',
        ]);
        self::assertFalse($allowed['is_message'] ?? false);
        self::assertContains($harmlessGroup, array_column($allowed['user']['groups'], 'id'));
    }

    #[Test]
    public function pageIsFilteredOnOriginSpaceAndHidesPersonalGroups(): void
    {
        $batch = 'liste-' . bin2hex(random_bytes(5));
        $email = $batch . '-0@integration.test';
        $created = $this->create($this->rootSession, $email);
        $statement = $this->database->connection()->prepare(<<<SQL
            INSERT INTO users (space_id, first_name, last_name, email, password, params)
            VALUES (2, 'Compte', 'Test', ?, 'unused', '{}')
            SQL);
        for ($index = 1; $index <= 10; $index++) {
            $otherEmail = $batch . '-' . $index . '@integration.test';
            $statement->bind_param('s', $otherEmail);
            $statement->execute();
            $this->users[] = (int) $this->database->connection()->insert_id;
        }

        $result = $this->api('', RequestMethod::GET, $this->rootSession,
            ['page' => '1', 'perPage' => '10', 'search' => $batch]);

        self::assertFalse($result['error']);
        self::assertSame(11, $result['total'], json_encode($result));
        self::assertCount(10, $result['users']);
        self::assertSame((int) $created['id'], $result['users'][0]['id']);
        self::assertSame(2, $result['users'][0]['space_id']);
        self::assertSame([2], $result['users'][0]['spaces']);
        self::assertSame([], $result['users'][0]['groups']);
        self::assertContains(5, array_column($result['users'][0]['roles'], 'id'));

        $secondPage = $this->api('', RequestMethod::GET, $this->rootSession,
            ['page' => '2', 'perPage' => '10', 'search' => $batch]);
        self::assertSame(11, $secondPage['total']);
        self::assertCount(1, $secondPage['users']);

        // La grande page doit être autorisée par les deux endpoints qui partagent la validation.
        $largePage = $this->api('', RequestMethod::GET, $this->rootSession,
            ['page' => '1', 'perPage' => '500', 'search' => $batch]);
        self::assertFalse($largePage['is_message'], json_encode($largePage));
        self::assertSame(500, $largePage['per_page']);
        self::assertCount(11, $largePage['users']);
        $largeMatrix = $this->matrixApi('', RequestMethod::GET, $this->rootSession,
            ['spaceId' => '1', 'perPage' => '500', 'search' => $batch]);
        self::assertFalse($largeMatrix['is_message'], json_encode($largeMatrix));
        self::assertSame(500, $largeMatrix['per_page']);
        self::assertCount(11, $largeMatrix['users']);

        foreach ([1 => 10, 2 => 1] as $page => $count) {
            $matrix = $this->matrixApi('', RequestMethod::GET, $this->rootSession,
                ['spaceId' => '1', 'page' => (string) $page, 'perPage' => '10', 'search' => $batch]);
            self::assertSame(11, $matrix['total']);
            self::assertCount($count, $matrix['users']);
        }
    }

    #[Test]
    public function membershipFollowsRolesWithoutDeletingOtherAssignments(): void
    {
        $user = $this->create($this->rootSession, 'acces-' . bin2hex(random_bytes(5)) . '@integration.test');
        $userId = (int) $user['id'];
        $db = $this->database->connection();
        $db->query("INSERT INTO user_groups (space_id, parent_id, type, name) VALUES "
            . "(2, NULL, 0, 'Groupe test " . $userId . "')");
        $groupId = (int) $db->insert_id;
        $this->groups[] = $groupId;

        $assignedGroup = $this->api('group', RequestMethod::POST, $this->rootSession,
            ['userId' => $userId, 'groupId' => $groupId, 'assigned' => '1']);
        self::assertFalse($assignedGroup['error'], json_encode($assignedGroup));
        self::assertFalse($assignedGroup['is_message'], json_encode($assignedGroup));
        self::assertFalse($this->api('role', RequestMethod::POST, $this->rootSession,
            ['userId' => $userId, 'roleId' => 4, 'spaceId' => 2, 'assigned' => '1'])['error']);
        self::assertFalse($this->api('role', RequestMethod::POST, $this->rootSession,
            ['userId' => $userId, 'roleId' => 5, 'spaceId' => 1, 'assigned' => '1'])['error']);

        // La suppression du compte exige toujours les droits sur chaque appartenance.
        self::assertTrue($this->api('delete', RequestMethod::POST, $this->editorSession,
            ['userId' => $userId])['is_message']);

        // Administrateur conserve MEMBER localement, même après le retrait du rôle Membre.
        $removed = $this->api('role', RequestMethod::POST, $this->rootSession,
            ['userId' => $userId, 'roleId' => 5, 'spaceId' => 2, 'assigned' => '0']);
        self::assertFalse($removed['error']);
        self::assertSame([1, 2], $removed['user']['spaces']);
        self::assertSame([], $removed['user']['inherited_spaces']);
        self::assertSame([$groupId], array_column($removed['user']['groups'], 'id'));
        self::assertContains(4, array_column($removed['user']['roles'], 'id'));

        $removed = $this->api('role', RequestMethod::POST, $this->rootSession,
            ['userId' => $userId, 'roleId' => 5, 'spaceId' => 1, 'assigned' => '0']);
        self::assertSame([2], $removed['user']['spaces']);
        self::assertSame([], $removed['user']['inherited_spaces']);
        self::assertSame([$groupId], array_column($removed['user']['groups'], 'id'));
        self::assertContains(4, array_column($removed['user']['roles'], 'id'));

        self::assertTrue($this->api('delete', RequestMethod::POST, $this->rootSession,
            ['userId' => $userId])['deleted']);
    }

    #[Test]
    public function adminPasswordAndCsrfAreEnforcedOnTheServer(): void
    {
        $user = $this->create($this->rootSession, 'securite-' . bin2hex(random_bytes(5)) . '@integration.test');
        $userId = (int) $user['id'];

        $denied = $this->api('password', RequestMethod::POST, $this->editorSession,
            ['userId' => $userId, 'data' => ['password' => 'UnMotDePasseLong123!']]);
        self::assertTrue($denied['is_message']);

        // Le schéma filtre la longueur en caractères ; le WF contrôle aussi les octets UTF-8.
        foreach ([str_repeat('a', 73), str_repeat('é', 37), "mot-de-passe\0invalide"] as $password) {
            $invalid = $this->api('password', RequestMethod::POST, $this->rootSession,
                ['userId' => $userId, 'data' => ['password' => $password]]);
            self::assertTrue($invalid['is_message']);
        }

        $changed = $this->api('password', RequestMethod::POST, $this->rootSession,
            ['userId' => $userId, 'data' => ['password' => 'UnMotDePasseLong123!']]);
        self::assertTrue($changed['updated']);

        $withoutCsrf = $this->api('delete', RequestMethod::POST, $this->rootSession,
            ['userId' => $userId], false);
        self::assertTrue($withoutCsrf['error']);
        self::assertNotNull($this->database->connection()->query(
            'SELECT id FROM users WHERE id = ' . $userId)->fetch_assoc());

        $targetSession = $this->session($userId);
        $blocked = $this->api('update', RequestMethod::POST, $this->rootSession, [
            'userId' => $userId,
            'data' => ['first_name' => 'Compte', 'last_name' => 'Test', 'email' => $user['email'],
                'timezone' => 'Europe/Paris', 'blocked' => true, 'send_email' => false],
        ]);
        self::assertTrue($blocked['user']['blocked']);
        $statement = $this->database->connection()->prepare('SELECT id FROM sessions WHERE `key` = ?');
        $statement->bind_param('s', $targetSession[0]);
        $statement->execute();
        self::assertNull($statement->get_result()->fetch_assoc());
    }

    #[Test]
    public function memberRoleRequiresAssignmentRightsOnItsOwnerSpace(): void
    {
        $user = $this->create($this->rootSession, 'retrait-' . bin2hex(random_bytes(5)) . '@integration.test');
        $userId = (int) $user['id'];

        // L'éditeur du Site ne peut pas attribuer un rôle appartenant à la racine.
        foreach ([true, false] as $assigned) {
            $denied = $this->api('role', RequestMethod::POST, $this->editorSession,
                ['userId' => $userId, 'roleId' => 5, 'spaceId' => 2, 'assigned' => $assigned]);
            self::assertTrue($denied['is_message']);
        }
        $read = $this->api('read', RequestMethod::GET, $this->rootSession, ['userId' => (string) $userId]);
        self::assertSame([2], $read['user']['spaces']);
        self::assertSame([], $read['user']['inherited_spaces']);
    }

    #[Test]
    public function anyMemberPermissionFromAParentGroupCountsForMembershipAndDeletion(): void
    {
        $user = $this->create($this->rootSession, 'heritage-' . bin2hex(random_bytes(5)) . '@integration.test');
        $userId = (int) $user['id'];
        $db = $this->database->connection();
        $db->query("INSERT INTO user_groups (space_id, parent_id, type, name) VALUES "
            . "(1, NULL, 0, 'Adhésion test " . $userId . "')");
        $groupId = (int) $db->insert_id;
        $this->groups[] = $groupId;
        $roleId = $this->createMemberRole();
        $db->query('INSERT INTO acl_map (group_id, role_id, space_id) VALUES (' . $groupId . ', ' . $roleId . ', 1)');
        $db->query("INSERT INTO user_groups (space_id, parent_id, type, name) VALUES "
            . "(1, " . $groupId . ", 0, 'Sous-groupe test " . $userId . "')");
        $childGroupId = (int) $db->insert_id;
        array_unshift($this->groups, $childGroupId);
        $db->query('INSERT INTO user_groups_map (user_id, group_id) VALUES (' . $userId . ', ' . $childGroupId . ')');

        $read = $this->api('read', RequestMethod::GET, $this->rootSession,
            ['userId' => (string) $userId]);
        self::assertSame([1, 2], $read['user']['spaces']);
        self::assertTrue($this->api('delete', RequestMethod::POST, $this->editorSession,
            ['userId' => $userId])['is_message']);

        $removed = $this->api('group', RequestMethod::POST, $this->rootSession,
            ['userId' => $userId, 'groupId' => $childGroupId, 'assigned' => '0']);
        self::assertSame([2], $removed['user']['spaces']);
        self::assertSame([], $removed['user']['groups']);
    }

    #[Test]
    public function assignmentModalsSaveBatchesAndRejectStaleSnapshots(): void
    {
        $user = $this->create($this->rootSession, 'modales-' . bin2hex(random_bytes(5)) . '@integration.test');
        $userId = (int) $user['id'];
        $db = $this->database->connection();
        $db->query("INSERT INTO user_groups (space_id, parent_id, type, name) VALUES "
            . "(2, NULL, 0, 'Modale test " . $userId . "')");
        $groupId = (int) $db->insert_id;
        $this->groups[] = $groupId;

        // Un lot dont la deuxième attribution est invalide ne doit rien conserver.
        $invalid = $this->api('groups/save', RequestMethod::POST, $this->rootSession,
            ['userId' => $userId, 'data' => ['before' => [], 'after' => [$groupId, 99999999]]]);
        self::assertTrue($invalid['is_message']);
        self::assertNull($db->query('SELECT id FROM user_groups_map WHERE user_id = '
            . $userId . ' AND group_id = ' . $groupId)->fetch_assoc());

        $groups = $this->api('groups/save', RequestMethod::POST, $this->rootSession,
            ['userId' => $userId, 'data' => ['before' => [], 'after' => [$groupId]]]);
        self::assertFalse($groups['error']);
        self::assertSame([$groupId], array_column($groups['user']['groups'], 'id'));

        $stale = $this->api('groups/save', RequestMethod::POST, $this->rootSession,
            ['userId' => $userId, 'data' => ['before' => [], 'after' => []]]);
        self::assertTrue($stale['is_message']);

        $roles = $this->api('roles/save', RequestMethod::POST, $this->rootSession,
            ['userId' => $userId, 'data' => ['before' => [['role_id' => 5, 'space_id' => 2]],
                'after' => [['role_id' => 5, 'space_id' => 2], ['role_id' => 4, 'space_id' => 2]]]]);
        self::assertFalse($roles['error']);
        self::assertContains(4, array_column($roles['user']['roles'], 'id'));

        // Le rôle Membre fait partie du même brouillon que tous les autres rôles.
        $removed = $this->api('roles/save', RequestMethod::POST, $this->rootSession,
            ['userId' => $userId, 'data' => [
                'before' => [['role_id' => 5, 'space_id' => 2], ['role_id' => 4, 'space_id' => 2]],
                'after' => [['role_id' => 4, 'space_id' => 2]],
            ]]);
        self::assertFalse($removed['error']);
        self::assertSame([2], $removed['user']['spaces']);
        self::assertSame([$groupId], array_column($removed['user']['groups'], 'id'));
        self::assertContains(4, array_column($removed['user']['roles'], 'id'));

        $added = $this->api('roles/save', RequestMethod::POST, $this->rootSession,
            ['userId' => $userId, 'data' => [
                'before' => [['role_id' => 4, 'space_id' => 2]],
                'after' => [['role_id' => 4, 'space_id' => 2], ['role_id' => 5, 'space_id' => 1]],
            ]]);
        self::assertFalse($added['error']);
        self::assertSame([1, 2], $added['user']['spaces']);
        self::assertSame([], $added['user']['inherited_spaces']);
    }

    #[Test]
    public function rolesAndGroupsCanBeAssignedBeforeMembershipInTheirSpace(): void
    {
        $user = $this->create($this->rootSession, 'sans-membre-' . bin2hex(random_bytes(5)) . '@integration.test');
        $userId = (int) $user['id'];
        $db = $this->database->connection();
        $db->query("INSERT INTO user_groups (space_id, parent_id, type, name) VALUES "
            . "(1, NULL, 0, 'Groupe sans membre " . $userId . "')");
        $groupId = (int) $db->insert_id;
        $this->groups[] = $groupId;

        // Le compte n'a que MEMBER sur le Site ; les attributions à la racine
        // dépendent des droits de l'éditeur, pas de son adhésion préalable.
        self::assertSame([2], $user['spaces']);

        $groups = $this->api('groups/save', RequestMethod::POST, $this->rootSession,
            ['userId' => $userId, 'data' => ['before' => [], 'after' => [$groupId]]]);
        self::assertFalse($groups['error']);
        self::assertSame([$groupId], array_column($groups['user']['groups'], 'id'));
        self::assertSame([2], $groups['user']['spaces']);

        // Supprimer le compte retirerait aussi son groupe racine, même sans MEMBER sur ce Space.
        $deniedDeletion = $this->api('delete', RequestMethod::POST, $this->editorSession, ['userId' => $userId]);
        self::assertTrue($deniedDeletion['is_message']);

        // Utiliser un rôle sans MEMBER : Administrateur le contient désormais explicitement.
        $roleId = $this->matrixRole(['PAGE_READ'], 1);
        $roles = $this->api('roles/save', RequestMethod::POST, $this->rootSession,
            ['userId' => $userId, 'data' => ['before' => [['role_id' => 5, 'space_id' => 2]],
                'after' => [['role_id' => 5, 'space_id' => 2], ['role_id' => $roleId, 'space_id' => 1]]]]);
        self::assertFalse($roles['error']);
        self::assertContains($roleId, array_column($roles['user']['roles'], 'id'));
        self::assertSame([2], $roles['user']['spaces']);
    }

    #[Test]
    public function currentUserCannotLeaveSuperAdminGroupThroughAnyRoute(): void
    {
        $read = $this->api('read', RequestMethod::GET, $this->rootSession, ['userId' => '1']);
        $groupIds = array_column($read['user']['groups'], 'id');
        self::assertContains(3, $groupIds);

        $direct = $this->api('group', RequestMethod::POST, $this->rootSession,
            ['userId' => 1, 'groupId' => 3, 'assigned' => '0']);
        self::assertTrue($direct['is_message']);

        $batch = $this->api('groups/save', RequestMethod::POST, $this->rootSession,
            ['userId' => 1, 'data' => ['before' => $groupIds,
                'after' => array_values(array_diff($groupIds, [3]))]]);
        self::assertTrue($batch['is_message']);

        $stillMember = $this->database->connection()->query(
            'SELECT id FROM user_groups_map WHERE user_id = 1 AND group_id = 3')->fetch_assoc();
        self::assertNotNull($stillMember);
    }

    #[Test]
    public function welcomeMailFailureDoesNotUndoAdminAccountCreation(): void
    {
        $email = 'welcome-' . bin2hex(random_bytes(5)) . '@integration.test';
        $response = $this->api('create', RequestMethod::POST, $this->rootSession, ['data' => [
            'space_id' => 2,
            'first_name' => 'Bienvenue',
            'last_name' => 'Test',
            'email' => $email,
            'timezone' => 'Europe/Paris',
            'blocked' => false,
            'send_email' => false,
            'password' => 'MotDePasseInitial123!',
            'send_welcome_email' => true,
        ]]);

        self::assertFalse($response['error']);
        self::assertFalse($response['user']['welcome_email_sent']);
        $userId = (int) $response['user']['id'];
        $this->users[] = $userId;
        $stored = $this->database->connection()->query(
            'SELECT password FROM users WHERE id = ' . $userId,
        )->fetch_assoc();
        self::assertNotNull($stored);
        self::assertTrue(password_verify('MotDePasseInitial123!', $stored['password']));
    }

    #[Test]
    public function customRoleGrantsMembershipToChildrenButNotToParents(): void
    {
        $user = $this->create($this->rootSession, 'permission-' . bin2hex(random_bytes(5)) . '@integration.test');
        $userId = (int) $user['id'];
        $roleId = $this->createMemberRole();
        $this->api('role', RequestMethod::POST, $this->rootSession,
            ['userId' => $userId, 'roleId' => 5, 'spaceId' => 2, 'assigned' => '0']);

        $site = $this->api('role', RequestMethod::POST, $this->rootSession,
            ['userId' => $userId, 'roleId' => $roleId, 'spaceId' => 2, 'assigned' => '1']);
        self::assertFalse($site['error']);
        self::assertSame([2], $site['user']['spaces']);
        self::assertSame([], $site['user']['inherited_spaces']);

        $root = $this->api('role', RequestMethod::POST, $this->rootSession,
            ['userId' => $userId, 'roleId' => $roleId, 'spaceId' => 1, 'assigned' => '1']);
        self::assertSame([1, 2], $root['user']['spaces']);
        $inherited = $this->api('role', RequestMethod::POST, $this->rootSession,
            ['userId' => $userId, 'roleId' => $roleId, 'spaceId' => 2, 'assigned' => '0']);
        self::assertSame([1, 2], $inherited['user']['spaces']);
        self::assertSame([2], $inherited['user']['inherited_spaces']);

        $page = $this->api('', RequestMethod::GET, $this->rootSession,
            ['search' => $user['email']]);
        self::assertSame([1, 2], $page['users'][0]['spaces']);
        self::assertSame([2], $page['users'][0]['inherited_spaces']);
    }

    #[Test]
    public function matrixSpaceFiltersColumnsButKeepsAllReadableUsersAndOnlyDirectAssignments(): void
    {
        $batch = 'matrice-' . bin2hex(random_bytes(5));
        $site = $this->create($this->rootSession, $batch . '-site@integration.test');
        $root = $this->create($this->rootSession, $batch . '-root@integration.test');
        $db = $this->database->connection();
        $db->query('UPDATE users SET space_id = 1 WHERE id = ' . $root['id']);
        $roleId = $this->matrixRole(['MEMBER']);
        $groupId = $this->matrixGroup(2);
        $db->query('INSERT INTO acl_map (group_id, role_id, space_id) VALUES (' . $groupId . ', ' . $roleId . ', 2)');
        $db->query('INSERT INTO user_groups_map (user_id, group_id) VALUES (' . $site['id'] . ', ' . $groupId . ')');
        // Le second compte ne possède aucune attribution dans le Space sélectionné.
        $db->query('DELETE FROM acl_map WHERE group_id = ' . $this->personalGroup($root['id']));

        $result = $this->matrixApi('', RequestMethod::GET, $this->rootSession,
            ['spaceId' => '2', 'search' => $batch, 'perPage' => '10']);
        self::assertFalse($result['is_message'], json_encode($result));
        self::assertSame(2, $result['total']);
        $users = array_column($result['users'], null, 'id');
        self::assertArrayHasKey($root['id'], $users);
        self::assertSame([], $users[$root['id']]['role_ids']);
        self::assertSame([], $users[$root['id']]['group_ids']);
        self::assertSame([$groupId], $users[$site['id']]['group_ids']);
        self::assertSame([], $users[$site['id']]['role_ids']);
        self::assertContains($roleId, array_column($result['roles'], 'id'));
        self::assertNotContains(5, array_column($result['roles'], 'id'));
        self::assertNotContains($this->personalGroup($site['id']), array_column($result['groups'], 'id'));
        self::assertArrayNotHasKey('spaces', $users[$site['id']]);
        self::assertArrayNotHasKey('can_delete', $users[$site['id']]);

        $page = $this->matrixApi('', RequestMethod::GET, $this->editorSession,
            ['spaceId' => '2', 'search' => $batch]);
        self::assertSame(1, $page['total']);
        self::assertSame($site['id'], $page['users'][0]['id']);
        $directory = $this->matrixApi('directory', RequestMethod::GET, $this->editorSession);
        self::assertSame([2], array_column($directory['spaces'], 'id'));

        foreach ([['spaceId' => '1'], ['spaceId' => '2', 'page' => '0'],
            ['spaceId' => '2', 'perPage' => '501'], ['spaceId' => '2', 'perPage' => '1000'], ['spaceId' => '2', 'search' => str_repeat('a', 101)]] as $params) {
            $denied = $this->matrixApi('', RequestMethod::GET, $this->editorSession, $params);
            self::assertTrue($denied['is_message'] || $denied['error'], json_encode($denied));
        }
    }

    #[Test]
    public function matrixSavesSingleCellsAndRejectsStaleStateWrongSpaceAndMissingCsrf(): void
    {
        $user = $this->create($this->rootSession, 'case-' . bin2hex(random_bytes(5)) . '@integration.test');
        $roleId = $this->matrixRole([]);
        $groupId = $this->matrixGroup(2);
        foreach (['role' => $roleId, 'group' => $groupId] as $kind => $id) {
            $data = ['user_id' => $user['id'], 'space_id' => 2, 'kind' => $kind,
                'item_id' => $id, 'before' => false, 'assigned' => true];
            $noCsrf = $this->matrixApi('save', RequestMethod::POST, $this->rootSession, ['data' => $data], false);
            self::assertTrue($noCsrf['error']);
            $saved = $this->matrixApi('save', RequestMethod::POST, $this->rootSession, ['data' => $data]);
            self::assertFalse($saved['is_message'], json_encode($saved));
            self::assertTrue($saved['assigned']);
            self::assertArrayNotHasKey('user', $saved);
            $stale = $this->matrixApi('save', RequestMethod::POST, $this->rootSession, ['data' => $data]);
            self::assertTrue($stale['is_message']);
            $data['before'] = true;
            $data['assigned'] = false;
            self::assertFalse($this->matrixApi('save', RequestMethod::POST, $this->rootSession, ['data' => $data])['is_message']);
        }

        foreach ([['kind' => 'role', 'item_id' => 5, 'space_id' => 2],
            ['kind' => 'group', 'item_id' => $groupId, 'space_id' => 1],
            ['kind' => 'group', 'item_id' => $this->personalGroup($user['id']), 'space_id' => 2]] as $item) {
            $denied = $this->matrixApi('save', RequestMethod::POST, $this->rootSession, ['data' => [
                ...$item, 'user_id' => $user['id'], 'before' => false, 'assigned' => true,
            ]]);
            self::assertTrue($denied['is_message'], json_encode($denied));
        }
        $selfRemoval = $this->matrixApi('save', RequestMethod::POST, $this->rootSession, ['data' => [
            'user_id' => 1, 'space_id' => 1, 'kind' => 'group', 'item_id' => 3,
            'before' => true, 'assigned' => false,
        ]]);
        self::assertTrue($selfRemoval['is_message']);
    }

    #[Test]
    public function groupAssignmentPermissionIsIndependentOnEveryUserEndpoint(): void
    {
        $user = $this->create($this->rootSession, 'droits-groupes-' . bin2hex(random_bytes(5)) . '@integration.test');
        $groupId = $this->matrixGroup(2);
        $db = $this->database->connection();
        // Remplacer le rôle administrateur de l'éditeur par un rôle de délégation limité.
        $roleId = $this->matrixRole(['USERS_READ', 'ROLE_READ', 'USER_ROLES_CRUD', 'USER_GROUPS_READ']);
        $personal = $this->personalGroup($this->editorUserId);
        $db->query('DELETE FROM acl_map WHERE group_id = ' . $personal);
        $db->query('INSERT INTO acl_map (group_id, role_id, space_id) VALUES (' . $personal . ', ' . $roleId . ', 2)');
        $directory = $this->api('directory', RequestMethod::GET, $this->editorSession);
        self::assertSame([], $directory['groups']);
        $matrix = $this->matrixApi('', RequestMethod::GET, $this->editorSession, ['spaceId' => '2']);
        self::assertSame([], $matrix['groups']);
        self::assertNotEmpty($matrix['roles']);
        $cell = ['user_id' => $user['id'], 'space_id' => 2, 'kind' => 'group',
            'item_id' => $groupId, 'before' => false, 'assigned' => true];
        self::assertTrue($this->matrixApi('save', RequestMethod::POST, $this->editorSession, ['data' => $cell])['is_message']);
        self::assertTrue($this->api('group', RequestMethod::POST, $this->editorSession,
            ['userId' => $user['id'], 'groupId' => $groupId, 'assigned' => '1'])['is_message']);
        self::assertTrue($this->api('groups/save', RequestMethod::POST, $this->editorSession,
            ['userId' => $user['id'], 'data' => ['before' => [], 'after' => [$groupId]]])['is_message']);

        $db->query("DELETE FROM acl_permissions WHERE role_id = " . $roleId . " AND name = 'USER_ROLES_CRUD'");
        $db->query("INSERT INTO acl_permissions (role_id, extension, name) VALUES (" . $roleId . ", 'plg_core', 'USER_GROUPS_ASSIGN')");
        $matrix = $this->matrixApi('', RequestMethod::GET, $this->editorSession, ['spaceId' => '2']);
        self::assertSame([], $matrix['roles']);
        self::assertContains($groupId, array_column($matrix['groups'], 'id'));
        self::assertFalse($this->matrixApi('save', RequestMethod::POST, $this->editorSession, ['data' => $cell])['is_message']);
        $removed = $this->api('groups/save', RequestMethod::POST, $this->editorSession,
            ['userId' => $user['id'], 'data' => ['before' => [$groupId], 'after' => []]]);
        self::assertFalse($removed['is_message'], json_encode($removed));
        self::assertFalse($removed['user']['can_assign']);
        self::assertTrue($removed['user']['can_assign_groups']);
        self::assertTrue($this->api('role', RequestMethod::POST, $this->editorSession,
            ['userId' => $user['id'], 'roleId' => $roleId, 'spaceId' => 2, 'assigned' => '1'])['is_message']);
    }

    #[Test]
    public function matrixRequiresReadPermissionsAndAssignmentRightsOnTheUserOrigin(): void
    {
        $user = $this->create($this->rootSession, 'lecture-' . bin2hex(random_bytes(5)) . '@integration.test');
        $groupId = $this->matrixGroup(2);
        $localRole = $this->matrixRole([]);
        $roleId = $this->matrixRole(['USER_GROUPS_ASSIGN', 'USER_ROLES_CRUD']);
        $db = $this->database->connection();
        $personal = $this->personalGroup($this->editorUserId);
        $db->query('DELETE FROM acl_map WHERE group_id = ' . $personal);
        $db->query('INSERT INTO acl_map (group_id, role_id, space_id) VALUES (' . $personal . ', ' . $roleId . ', 2)');
        self::assertTrue($this->matrixApi('directory', RequestMethod::GET, $this->editorSession)['is_message']);
        $db->query("INSERT INTO acl_permissions (role_id, extension, name) VALUES (" . $roleId . ", 'plg_core', 'USERS_READ')");
        $matrix = $this->matrixApi('', RequestMethod::GET, $this->editorSession, ['spaceId' => '2']);
        self::assertSame([], $matrix['roles']);
        self::assertSame([], $matrix['groups']);
        foreach (['role' => $localRole, 'group' => $groupId] as $kind => $id) {
            self::assertTrue($this->matrixApi('save', RequestMethod::POST, $this->editorSession, ['data' => [
                'user_id' => $user['id'], 'space_id' => 2, 'kind' => $kind, 'item_id' => $id,
                'before' => false, 'assigned' => true,
            ]])['is_message']);
        }

        // Lire un compte d'un autre Space n'autorise pas à y modifier ses attributions.
        $readRole = $this->matrixRole(['USERS_READ'], 1);
        $db->query('INSERT INTO acl_map (group_id, role_id, space_id) VALUES (' . $personal . ', ' . $readRole . ', 1)');
        $db->query("INSERT INTO acl_permissions (role_id, extension, name) VALUES (" . $roleId . ", 'plg_core', 'ROLE_READ'), (" . $roleId . ", 'plg_core', 'USER_GROUPS_READ')");
        $db->query('UPDATE users SET space_id = 1 WHERE id = ' . $user['id']);
        $matrix = $this->matrixApi('', RequestMethod::GET, $this->editorSession,
            ['spaceId' => '2', 'search' => $user['email']]);
        self::assertCount(1, $matrix['users']);
        self::assertFalse($matrix['users'][0]['can_assign_roles']);
        self::assertFalse($matrix['users'][0]['can_assign_groups']);
        foreach (['role' => $localRole, 'group' => $groupId] as $kind => $id) {
            self::assertTrue($this->matrixApi('save', RequestMethod::POST, $this->editorSession, ['data' => [
                'user_id' => $user['id'], 'space_id' => 2, 'kind' => $kind, 'item_id' => $id,
                'before' => false, 'assigned' => true,
            ]])['is_message']);
        }
    }

    #[Test]
    public function matrixChecksCurrentStateDespiteAnEarlierTransactionSnapshot(): void
    {
        $user = $this->create($this->rootSession, 'instantane-' . bin2hex(random_bytes(5)) . '@integration.test');
        $roleId = $this->matrixRole([]);
        $groupId = $this->matrixGroup(2);
        $personal = $this->personalGroup($user['id']);
        foreach (['role' => $roleId, 'group' => $groupId] as $kind => $id) {
            $app = new Application($this->environment->configuration());
            $app->initialiseOrThrow(new Request(uri: '/administrator/api/user-assignments/directory',
                host: TestFixtures::SITE_HOST, cookies: ['session_key' => $this->rootSession[0]]));
            $db = $app->getDatabase();
            $helper = $app->getOrAddSharedExtensionsClass(\YTassy\Tycms\CoreExts\Core\Helper\UserAdminHelper::class);
            $workflow = $app->getOrAddSharedExtensionsClass(\YTassy\Tycms\CoreExts\Core\WF\WFAdminUser::class);
            $db->execute('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $db->execute('START TRANSACTION');
            try {
                // Créer un instantané avant la modification validée sur une autre connexion.
                $helper->getDirectAssignments([$user['id']], 2, true, true);
                $sql = $kind === 'role'
                    ? 'INSERT INTO acl_map (group_id, role_id, space_id) VALUES (' . $personal . ', ' . $id . ', 2)'
                    : 'INSERT INTO user_groups_map (user_id, group_id) VALUES (' . $user['id'] . ', ' . $id . ')';
                $this->database->connection()->query($sql);
                try {
                    $workflow->saveAssignmentCell((object) ['user_id' => $user['id'], 'space_id' => 2,
                        'kind' => $kind, 'item_id' => $id, 'before' => false, 'assigned' => true]);
                    self::fail('Le serveur doit refuser cet état devenu obsolète.');
                } catch (\YTassy\Tycms\Response\ExceptionResponse $exception) {
                    $response = json_decode($exception->response->body, true, flags: JSON_THROW_ON_ERROR);
                    self::assertStringContainsString('a changé', $response['message']);
                }
            } finally {
                $db->execute('ROLLBACK');
            }
        }
    }

    #[Test]
    public function implicitRolesAndGroupsAreAbsentFromAssignmentCatalogsAndRejectedByAllUserWrites(): void
    {
        $user = $this->create($this->rootSession, 'implicite-' . bin2hex(random_bytes(5)) . '@integration.test');
        $directory = $this->api('directory', RequestMethod::GET, $this->rootSession);
        $matrix = $this->matrixApi('', RequestMethod::GET, $this->rootSession, ['spaceId' => '1']);
        foreach ([$directory, $matrix] as $catalog) {
            foreach ([1, 3] as $roleId) self::assertNotContains($roleId, array_column($catalog['roles'], 'id'));
            foreach ([1, 2] as $groupId) self::assertNotContains($groupId, array_column($catalog['groups'], 'id'));
        }

        foreach ([1, 3] as $roleId) {
            $direct = $this->api('role', RequestMethod::POST, $this->rootSession,
                ['userId' => $user['id'], 'spaceId' => 1, 'roleId' => $roleId, 'assigned' => '1']);
            self::assertTrue($direct['is_message']);
            $batch = $this->api('roles/save', RequestMethod::POST, $this->rootSession, ['userId' => $user['id'], 'data' => [
                'before' => [['role_id' => 5, 'space_id' => 2]],
                'after' => [['role_id' => 5, 'space_id' => 2], ['role_id' => $roleId, 'space_id' => 1]],
            ]]);
            self::assertTrue($batch['is_message']);
            self::assertTrue($this->matrixApi('save', RequestMethod::POST, $this->rootSession, ['data' => [
                'user_id' => $user['id'], 'space_id' => 1, 'kind' => 'role', 'item_id' => $roleId,
                'before' => false, 'assigned' => true,
            ]])['is_message']);
        }
        foreach ([1, 2] as $groupId) {
            self::assertTrue($this->api('group', RequestMethod::POST, $this->rootSession,
                ['userId' => $user['id'], 'groupId' => $groupId, 'assigned' => '1'])['is_message']);
            self::assertTrue($this->api('groups/save', RequestMethod::POST, $this->rootSession,
                ['userId' => $user['id'], 'data' => ['before' => [], 'after' => [$groupId]]])['is_message']);
            self::assertTrue($this->matrixApi('save', RequestMethod::POST, $this->rootSession, ['data' => [
                'user_id' => $user['id'], 'space_id' => 1, 'kind' => 'group', 'item_id' => $groupId,
                'before' => false, 'assigned' => true,
            ]])['is_message']);
        }
        $read = $this->api('read', RequestMethod::GET, $this->rootSession, ['userId' => (string) $user['id']]);
        self::assertSame([], $read['user']['groups']);
        self::assertSame([5], array_column($read['user']['roles'], 'id'));
    }

    #[Test]
    public function delegatedAdminCannotAcquireRootByRoleOrInheritedGroup(): void
    {
        $db = $this->database->connection();
        $personal = $this->personalGroup($this->editorUserId);
        $delegation = $this->matrixRole(['USERS_READ', 'USER_ROLES_CRUD', 'USER_GROUPS_ASSIGN', 'USER_GROUPS_CRUD'], 1);
        $db->query('INSERT INTO acl_map (group_id, role_id, space_id) VALUES (' . $personal . ', ' . $delegation . ', 1)');

        // Un groupe ordinaire et son enfant héritent du rôle ROOT sur la racine.
        $parent = $this->matrixGroup(1);
        $child = $this->matrixGroup(1);
        $db->query('INSERT INTO acl_map (group_id, role_id, space_id) VALUES (' . $parent . ', 2, 1)');
        $db->query('UPDATE user_groups SET parent_id = ' . $parent . ' WHERE id = ' . $child);

        $denied = $this->api('role', RequestMethod::POST, $this->editorSession,
            ['userId' => $this->editorUserId, 'roleId' => 2, 'spaceId' => 1, 'assigned' => true]);
        self::assertTrue($denied['is_message'], json_encode($denied));
        self::assertSame('DANGER', $denied['message_level']);
        self::assertNull($db->query('SELECT id FROM acl_map WHERE group_id = ' . $personal . ' AND role_id = 2')->fetch_assoc());

        foreach ([3, $parent, $child] as $group) {
            $denied = $this->api('group', RequestMethod::POST, $this->editorSession,
                ['userId' => $this->editorUserId, 'groupId' => $group, 'assigned' => true]);
            self::assertTrue($denied['is_message'], json_encode($denied));
            self::assertSame('DANGER', $denied['message_level']);
            self::assertNull($db->query('SELECT id FROM user_groups_map WHERE user_id = '
                . $this->editorUserId . ' AND group_id = ' . $group)->fetch_assoc());
        }

        // La gestion du groupe ne permet pas davantage de lui ajouter ROOT ou un parent ROOT.
        $ordinary = $this->matrixGroup(1);
        foreach ([
            ['roles/update', $ordinary, ['roleIds' => json_encode([2])]],
            ['update', $ordinary, ['data' => json_encode(['parent_id' => $parent, 'name' => 'Groupe déplacé', 'description' => ''])]],
            ['roles/update', $parent, ['roleIds' => json_encode([])]],
            ['update', $child, ['data' => json_encode(['parent_id' => 0, 'name' => 'Groupe déplacé', 'description' => ''])]],
        ] as [$path, $groupId, $body]) {
            $app = new Application($this->environment->configuration());
            $app->initialiseOrThrow(new Request(method: RequestMethod::POST,
                uri: '/administrator/api/user-groups/' . $path,
                body: ['groupId' => $groupId, ...$body],
                headers: ['X-CSRF-Token' => $this->editorSession[1]],
                cookies: ['session_key' => $this->editorSession[0]], host: TestFixtures::SITE_HOST));
            $denied = json_decode($app->executeAndGetResponse()->body, true, flags: JSON_THROW_ON_ERROR);
            self::assertTrue($denied['is_message'], json_encode($denied));
            self::assertSame('DANGER', $denied['message_level']);
        }
        self::assertNull($db->query('SELECT parent_id FROM user_groups WHERE id = ' . $ordinary)->fetch_assoc()['parent_id']);
        self::assertNull($db->query('SELECT id FROM acl_map WHERE group_id = ' . $ordinary)->fetch_assoc());
        self::assertSame((string) $parent, (string) $db->query('SELECT parent_id FROM user_groups WHERE id = ' . $child)->fetch_assoc()['parent_id']);
        self::assertNotNull($db->query('SELECT id FROM acl_map WHERE group_id = ' . $parent . ' AND role_id = 2')->fetch_assoc());

        // Le même chemin reste utilisable par un ROOT connecté.
        $allowed = $this->api('group', RequestMethod::POST, $this->rootSession,
            ['userId' => $this->editorUserId, 'groupId' => $child, 'assigned' => true]);
        self::assertFalse($allowed['error'], json_encode($allowed));
        self::assertFalse($allowed['is_message'], json_encode($allowed));
        self::assertNotNull($db->query('SELECT id FROM user_groups_map WHERE user_id = '
            . $this->editorUserId . ' AND group_id = ' . $child)->fetch_assoc());
    }

    #[Test]
    public function automaticRootGrantsAbortAccountCreationEvenForRoot(): void
    {
        $db = $this->database->connection();
        $original = $db->query('SELECT params FROM spaces WHERE id = 2')->fetch_assoc()['params'];
        $group = $this->matrixGroup(2);
        $db->query('INSERT INTO acl_map (group_id, role_id, space_id) VALUES (' . $group . ', 2, 1)');
        $statement = $db->prepare('UPDATE spaces SET params = ? WHERE id = 2');
        try {
            foreach (['registration_roles' => ['1' => [2]], 'registration_groups' => [$group]] as $setting => $value) {
                $params = json_decode($original, true, flags: JSON_THROW_ON_ERROR);
                $params[$setting] = $value;
                $json = json_encode($params, JSON_THROW_ON_ERROR);
                $statement->bind_param('s', $json);
                $statement->execute();
                $email = 'root-interdit-' . bin2hex(random_bytes(5)) . '@integration.test';
                $denied = $this->api('create', RequestMethod::POST, $this->rootSession, ['data' => [
                    'space_id' => 2, 'first_name' => 'Compte', 'last_name' => 'Test',
                    'email' => $email, 'timezone' => 'Europe/Paris', 'blocked' => false,
                    'send_email' => false, 'password' => 'MotDePasseInitial123!',
                ]]);
                self::assertTrue($denied['is_message'], json_encode($denied));
                self::assertSame('DANGER', $denied['message_level']);
                $check = $db->prepare('SELECT id FROM users WHERE email = ?');
                $check->bind_param('s', $email);
                $check->execute();
                self::assertNull($check->get_result()->fetch_assoc(), 'La création doit être annulée intégralement.');
            }
        } finally {
            $statement->bind_param('s', $original);
            $statement->execute();
        }
    }

    #[Test]
    public function onlyRootCanModifyRootAccountsIncludingInheritedCustomRoot(): void
    {
        $user = $this->create($this->rootSession, 'root-protege-' . bin2hex(random_bytes(5)) . '@integration.test');
        $userId = (int) $user['id'];
        $db = $this->database->connection();
        $personal = $this->personalGroup($this->editorUserId);
        $delegation = $this->matrixRole(['USERS_READ', 'USERS_CRUD', 'USERS_ADMIN', 'USER_ROLES_CRUD',
            'USER_GROUPS_ASSIGN', 'ROLE_READ', 'USER_GROUPS_READ'], 1);
        $db->query('INSERT INTO acl_map (group_id, role_id, space_id) VALUES (' . $personal . ', ' . $delegation . ', 1)');

        // ROOT est porté par un rôle personnalisé sur un parent, sans rôle système 2 sur ce compte.
        $role = $this->matrixRole(['ROOT'], 1);
        $parent = $this->matrixGroup(1);
        $child = $this->matrixGroup(1);
        $db->query('UPDATE user_groups SET parent_id = ' . $parent . ' WHERE id = ' . $child);
        $db->query('INSERT INTO acl_map (group_id, role_id, space_id) VALUES (' . $parent . ', ' . $role . ', 1)');
        $db->query('INSERT INTO user_groups_map (user_id, group_id) VALUES (' . $userId . ', ' . $child . ')');
        $original = $db->query('SELECT email, password, blocked FROM users WHERE id = ' . $userId)->fetch_assoc();

        $read = $this->api('read', RequestMethod::GET, $this->editorSession, ['userId' => (string) $userId]);
        foreach (['can_edit', 'can_admin', 'can_assign', 'can_assign_groups', 'can_delete'] as $capability) {
            self::assertFalse($read['user'][$capability]);
        }
        $listed = $this->api('', RequestMethod::GET, $this->editorSession, ['search' => $user['email']]);
        self::assertFalse($listed['users'][0]['can_edit']);
        $matrix = $this->matrixApi('', RequestMethod::GET, $this->editorSession, ['spaceId' => '1', 'search' => $user['email']]);
        self::assertFalse($matrix['users'][0]['can_assign_roles']);
        self::assertFalse($matrix['users'][0]['can_assign_groups']);

        $profile = ['first_name' => 'Profil modifié', 'last_name' => 'Test', 'email' => $user['email'],
            'timezone' => 'Europe/Paris', 'blocked' => false, 'send_email' => false];
        foreach (['update' => ['data' => $profile],
            'password' => ['data' => ['password' => 'AutreMotDePasse123!']],
            'sso/unlink' => ['linkId' => 1], 'delete' => [],
            'role' => ['roleId' => 5, 'spaceId' => 2, 'assigned' => false],
            'group' => ['groupId' => $child, 'assigned' => false],
            'roles/save' => ['data' => ['before' => [], 'after' => []]],
            'groups/save' => ['data' => ['before' => [$child], 'after' => []]],
        ] as $path => $body) {
            $denied = $this->api($path, RequestMethod::POST, $this->editorSession, ['userId' => $userId, ...$body]);
            self::assertTrue($denied['is_message'], $path . ': ' . json_encode($denied));
            self::assertSame('Seul un super administrateur peut modifier un compte ROOT.', $denied['message']);
        }
        $denied = $this->matrixApi('save', RequestMethod::POST, $this->editorSession, ['data' => [
            'user_id' => $userId, 'space_id' => 1, 'kind' => 'group', 'item_id' => $child,
            'before' => true, 'assigned' => false,
        ]]);
        self::assertSame('Seul un super administrateur peut modifier un compte ROOT.', $denied['message']);
        self::assertSame($original, $db->query('SELECT email, password, blocked FROM users WHERE id = ' . $userId)->fetch_assoc());
        self::assertNotNull($db->query('SELECT id FROM user_groups_map WHERE user_id = '
            . $userId . ' AND group_id = ' . $child)->fetch_assoc());

        $allowed = $this->api('update', RequestMethod::POST, $this->rootSession, ['userId' => $userId, 'data' => $profile]);
        self::assertFalse($allowed['is_message'], json_encode($allowed));
        self::assertSame('Profil modifié', $allowed['user']['first_name']);
    }

    private function matrixRole(array $permissions, int $spaceId = 2): int
    {
        $db = $this->database->connection();
        $db->query("INSERT INTO acl_roles (space_id, user_defined, name, description, type) VALUES ("
            . $spaceId . ", 1, 'Rôle matrice " . bin2hex(random_bytes(5)) . "', '', 0)");
        $id = (int) $db->insert_id;
        $this->roles[] = $id;
        foreach ($permissions as $permission) {
            $statement = $db->prepare("INSERT INTO acl_permissions (role_id, extension, name) VALUES (?, 'plg_core', ?)");
            $statement->bind_param('is', $id, $permission);
            $statement->execute();
        }
        return $id;
    }

    private function matrixGroup(int $spaceId): int
    {
        $db = $this->database->connection();
        $db->query("INSERT INTO user_groups (space_id, parent_id, type, name) VALUES ("
            . $spaceId . ", NULL, 0, 'Groupe matrice test')");
        $id = (int) $db->insert_id;
        // Les enfants créés ensuite sont nettoyés avant leurs parents.
        array_unshift($this->groups, $id);
        return $id;
    }

    private function matrixApi(string $path, RequestMethod $method, array $session,
        array $values = [], bool $withCsrf = true): array
    {
        return $this->api($path, $method, $session, $values, $withCsrf, true);
    }

    private function createMemberRole(): int
    {
        $db = $this->database->connection();
        $db->query("INSERT INTO acl_roles (space_id, user_defined, name, description, type) "
            . "VALUES (1, 1, 'Membre personnalisé test', '', 0)");
        $roleId = (int) $db->insert_id;
        $this->roles[] = $roleId;
        $db->query("INSERT INTO acl_permissions (role_id, extension, name) VALUES ("
            . $roleId . ", 'plg_core', 'MEMBER')");

        return $roleId;
    }

    private function create(array $session, string $email): array
    {
        $response = $this->api('create', RequestMethod::POST, $session, ['data' => [
            'space_id' => 2, 'first_name' => 'Compte', 'last_name' => 'Test',
            'email' => $email, 'timezone' => 'Europe/Paris', 'blocked' => false,
            'send_email' => false, 'password' => 'MotDePasseInitial123!',
        ]]);
        self::assertFalse($response['error']);
        $this->users[] = (int) $response['user']['id'];

        return $response['user'];
    }

    private function personalGroup(int $userId): int
    {
        $row = $this->database->connection()->query('SELECT ug.id FROM user_groups ug '
            . 'INNER JOIN user_groups_map gm ON gm.group_id = ug.id '
            . 'WHERE ug.type = 1 AND gm.user_id = ' . $userId)->fetch_assoc();

        return (int) $row['id'];
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

    private function api(string $path, RequestMethod $method, array $session,
        array $values = [], bool $withCsrf = true, bool $matrix = false): array
    {
        [$key, $csrf] = $session;
        if (isset($values['data'])) $values['data'] = json_encode($values['data'], JSON_THROW_ON_ERROR);
        $request = new Request(
            method: $method,
            uri: ($matrix ? '/administrator/api/user-assignments' : '/administrator/api/users') . ($path === '' ? '' : '/' . $path),
            query: $method === RequestMethod::GET ? $values : [],
            body: $method === RequestMethod::POST ? $values : [],
            headers: $withCsrf ? ['X-CSRF-Token' => $csrf] : [],
            cookies: ['session_key' => $key],
            host: TestFixtures::SITE_HOST,
        );
        $application = new Application($this->environment->configuration());
        $application->initialiseOrThrow($request);

        return json_decode($application->executeAndGetResponse()->body ?? '', true, flags: JSON_THROW_ON_ERROR);
    }
}
