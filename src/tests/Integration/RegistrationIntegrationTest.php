<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Core\Helper\Acl\AclHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Space\SpaceAdminHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Space\SpaceHelper;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Roles;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\CoreExts\Core\WF\WFUser;
use YTassy\Tycms\CoreExts\Core\WF\WFSso;
use YTassy\Tycms\Request\Request;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Tests\Support\Integration\TestDatabase;
use YTassy\Tycms\Tests\Support\Integration\TestEnvironment;
use YTassy\Tycms\Tests\Support\Integration\TestFixtures;

final class RegistrationIntegrationTest extends TestCase
{
    private TestEnvironment $environment;
    private TestDatabase $database;
    private \YTassy\Tycms\Tests\Support\Integration\AuthenticationPages $authPages;
    private array $originalParams;
    private array $originalHosts;
    private array $originalParents;
    private array $sessionKeys = [];
    private ?int $createdUserId = null;
    private ?int $createdSpaceId = null;
    private ?string $createdMediaDir = null;
    private array $createdGroupIds = [];
    private array $createdRoleIds = [];

    protected function setUp(): void
    {
        $this->environment = TestEnvironment::load();
        $this->database = new TestDatabase($this->environment);
        TestFixtures::assertPrepared($this->database);
        $this->authPages = new \YTassy\Tycms\Tests\Support\Integration\AuthenticationPages($this->database);
        $this->authPages->install(1);
        $this->authPages->install(2);

        $this->originalParams = [];
        $this->originalHosts = [];
        $this->originalParents = [];
        foreach ([1, 2] as $spaceId) {
            $statement = $this->database->connection()->prepare('SELECT params, hosts, parent_id FROM spaces WHERE id = ?');
            $statement->bind_param('i', $spaceId);
            $statement->execute();
            $row = $statement->get_result()->fetch_assoc();
            $this->originalParams[$spaceId] = $row['params'];
            $this->originalHosts[$spaceId] = $row['hosts'];
            $this->originalParents[$spaceId] = $row['parent_id'];
        }
    }

    protected function tearDown(): void
    {
        $db = $this->database->connection();
        $this->authPages->restore();

        // Les sessions et le compte du scénario sont retirés avant de restaurer les espaces.
        foreach ($this->sessionKeys as $key) {
            $statement = $db->prepare('DELETE FROM sessions WHERE `key` = ?');
            $statement->bind_param('s', $key);
            $statement->execute();
        }
        if ($this->createdUserId !== null) {
            $groups = $db->query('SELECT ug.id FROM user_groups ug '
                . 'INNER JOIN user_groups_map gm ON gm.group_id = ug.id '
                . 'WHERE gm.user_id = ' . $this->createdUserId . ' AND ug.type = 1')
                ->fetch_all(MYSQLI_ASSOC);
            $db->query('DELETE FROM sessions WHERE user_id = ' . $this->createdUserId);
            $db->query('DELETE FROM users WHERE id = ' . $this->createdUserId);
            foreach ($groups as $group) {
                $db->query('DELETE FROM user_groups WHERE id = ' . (int) $group['id']);
            }
        }
        if ($this->createdSpaceId !== null) {
            $db->query('DELETE FROM space_languages WHERE space_id = ' . $this->createdSpaceId);
            $db->query('DELETE FROM spaces WHERE id = ' . $this->createdSpaceId);
        }
        if ($this->createdMediaDir !== null && is_dir($this->createdMediaDir)) {
            rmdir($this->createdMediaDir);
        }
        foreach ($this->originalParams as $spaceId => $params) {
            // Le formulaire ajoute le domaine principal aux hôtes : restaurer aussi ce champ.
            $hosts = $this->originalHosts[$spaceId];
            $parentId = $this->originalParents[$spaceId];
            $statement = $db->prepare('UPDATE spaces SET params = ?, hosts = ?, parent_id = ? WHERE id = ?');
            $statement->bind_param('ssii', $params, $hosts, $parentId, $spaceId);
            $statement->execute();
        }
        foreach (array_reverse($this->createdGroupIds) as $groupId) {
            $db->query('DELETE FROM user_groups WHERE id = ' . $groupId);
        }
        foreach ($this->createdRoleIds as $roleId) {
            $db->query('DELETE FROM acl_roles WHERE id = ' . $roleId);
        }
    }

    #[Test]
    #[DataProvider('registrationTargets')]
    public function formCreatesPersonalGroupAndExistingUserJoinsAnotherSpace(?int $targetId): void
    {
        $this->configure(2, true, ['this' => [4], 1 => [4]], ['this' => [4]], $targetId);
        $this->configure(1, false, [], [], 2);
        // L'inscription locale et l'adhésion ne dépendent pas des liens de navigation.
        $this->database->connection()->query('UPDATE spaces SET menu_item_register_id = NULL WHERE id IN (1, 2)');
        [$guestKey, $csrf] = $this->session(null, 2);

        $response = $this->request('/auth/api/register', RequestMethod::POST, $guestKey, [
            'first_name' => 'Alice', 'last_name' => 'Martin',
            'email' => 'registration.integration@example.test',
            'password' => 'MotDePasseLong123',
            'password_confirmation' => 'MotDePasseLong123',
            'csrf_token' => $csrf,
        ]);
        self::assertSame(200, $response->code);

        $db = $this->database->connection();
        $user = $db->query("SELECT id, space_id FROM users WHERE email = 'registration.integration@example.test'")
            ->fetch_assoc();
        self::assertNotNull($user);
        $this->createdUserId = (int) $user['id'];
        self::assertSame($targetId ?? 2, (int) $user['space_id']);

        $group = $db->query('SELECT ug.id, ug.space_id, ug.parent_id, ug.type FROM user_groups ug '
            . 'INNER JOIN user_groups_map gm ON gm.group_id = ug.id WHERE gm.user_id = ' . $this->createdUserId)
            ->fetch_assoc();
        self::assertSame(2, (int) $group['parent_id']);
        self::assertSame(1, (int) $group['type']);
        self::assertSame($targetId ?? 2, (int) $group['space_id']);
        self::assertSame(1, (int) $db->query('SELECT COUNT(*) AS total FROM acl_map '
            . 'WHERE group_id = ' . (int) $group['id'] . ' AND space_id = 2 AND role_id = 5')->fetch_assoc()['total']);
        self::assertSame(0, (int) $db->query('SELECT COUNT(*) AS total FROM acl_map '
            . 'WHERE group_id = ' . (int) $group['id'] . ' AND role_id IN (1, 3)')->fetch_assoc()['total']);
        self::assertSame(1, (int) $db->query('SELECT COUNT(*) AS total FROM acl_map '
            . 'WHERE group_id = ' . (int) $group['id'] . ' AND space_id = 2 AND role_id = 4')->fetch_assoc()['total']);
        self::assertSame(1, (int) $db->query('SELECT COUNT(*) AS total FROM acl_map '
            . 'WHERE group_id = ' . (int) $group['id'] . ' AND space_id = 1 AND role_id = 4')->fetch_assoc()['total']);
        self::assertSame(0, (int) $db->query('SELECT COUNT(*) AS total FROM acl_map '
            . 'WHERE group_id = ' . (int) $group['id'] . ' AND space_id = 1 AND role_id = 5')->fetch_assoc()['total']);

        $this->configure(1, true, [], [], 2);
        [$userKey, $userCsrf] = $this->session($this->createdUserId, 1);
        $joined = $this->request('/auth/api/join', RequestMethod::POST, $userKey, ['csrf_token' => $userCsrf]);
        self::assertSame(200, $joined->code);
        self::assertSame(1, (int) $db->query('SELECT COUNT(*) AS total FROM users '
            . 'WHERE email = \'registration.integration@example.test\'')->fetch_assoc()['total']);
        self::assertSame(1, (int) $db->query('SELECT COUNT(*) AS total FROM acl_map '
            . 'WHERE group_id = ' . (int) $group['id'] . ' AND space_id = 1 AND role_id = 5')->fetch_assoc()['total']);
        self::assertSame($targetId ?? 2, (int) $db->query('SELECT space_id FROM users WHERE id = '
            . $this->createdUserId)->fetch_assoc()['space_id']);
    }

    public static function registrationTargets(): array
    {
        return ['même espace' => [null], 'espace principal distinct' => [1]];
    }

    #[Test]
    #[DataProvider('optionalMembershipScenarios')]
    public function optionalMemberRoleRequiresEffectiveMembership(string $flow, string $grant, bool $allowed): void
    {
        $db = $this->database->connection();
        $roleSpaceId = $grant === 'ancestor' ? 1 : 2;
        $db->query("INSERT INTO acl_roles (space_id, user_defined, type, name) VALUES ("
            . $roleSpaceId . ", 1, 0, 'optional-member-" . bin2hex(random_bytes(6)) . "')");
        $roleId = (int) $db->insert_id;
        $this->createdRoleIds[] = $roleId;
        $permission = $allowed ? 'MEMBER' : 'PAGE_READ';
        $db->query("INSERT INTO acl_permissions (role_id, extension, name) VALUES ("
            . $roleId . ", 'plg_core', '" . $permission . "')");

        $this->configure(2, true, [], [], 1);
        $params = json_decode($db->query('SELECT params FROM spaces WHERE id = 2')->fetch_assoc()['params'], true);
        $params['registration_auto_member_role'] = $grant === 'standalone';
        if ($grant === 'standalone') $db->query('UPDATE spaces SET parent_id = NULL WHERE id = 2');
        if ($grant === 'group') {
            // Seul le parent du groupe attribué porte les droits : vérifier les deux héritages.
            $db->query("INSERT INTO user_groups (space_id, type, name) VALUES (2, 0, 'parent-member-test')");
            $parentId = (int) $db->insert_id;
            $this->createdGroupIds[] = $parentId;
            $db->query('INSERT INTO acl_map (group_id, role_id, space_id) VALUES (' . $parentId . ', ' . $roleId . ', 2)');
            $db->query("INSERT INTO user_groups (space_id, parent_id, type, name) VALUES (2, " . $parentId . ", 0, 'child-member-test')");
            $childId = (int) $db->insert_id;
            $this->createdGroupIds[] = $childId;
            $params['registration_groups'] = [$childId];
        } elseif ($grant !== 'standalone') {
            $params['registration_roles'] = (object) [
                ($grant === 'ancestor' ? '1' : 'this') => [$grant === 'explicit-member' ? Roles::ROLE_MEMBER : $roleId],
            ];
        }
        if (!$allowed) $params['user_roles'] = (object) ['this' => [$roleId]];
        $json = json_encode($params, JSON_THROW_ON_ERROR);
        $statement = $db->prepare('UPDATE spaces SET params = ? WHERE id = 2');
        $statement->bind_param('s', $json);
        $statement->execute();

        $email = 'optional-member-' . bin2hex(random_bytes(6)) . '@example.test';
        [$key, $csrf] = $this->session($flow === 'admin' ? 1 : null, 2);
        $app = new Application($this->environment->configuration());
        $app->initialiseOrThrow(new Request(uri: '/', host: TestFixtures::SITE_HOST, cookies: ['session_key' => $key]));
        if (in_array($flow, ['join', 'sso'], true)) {
            $helper = $app->getOrAddSharedExtensionsClass(\YTassy\Tycms\CoreExts\Core\Helper\UserRegistrationHelper::class);
            $this->createdUserId = $helper->createUser(
                $app->getOrAddSharedExtensionsClass(SpaceHelper::class)->getSpace(1), 'Compte', 'Existant', $email, 'unused'
            );
            // Précharger un refus avant les attributions pour éprouver l’invalidation du cache.
            self::assertFalse($app->getOrAddSharedExtensionsClass(AclHelper::class)
                ->userHasGlobalPermission($this->createdUserId, Permissions::MEMBER, 2));
        }
        $before = $this->enrollmentRowCounts();

        if ($flow === 'sso') {
            // Exercer la décision SSO sans contacter un fournisseur externe.
            $app->getDatabase()->execute('START TRANSACTION');
            try {
                new \ReflectionMethod(WFSso::class, 'ensureEligible')->invoke(
                    new WFSso($app), (object) ['id' => $this->createdUserId, 'blocked' => 0],
                    $app->getOrAddSharedExtensionsClass(SpaceHelper::class)->getSpace(2), true
                );
                $app->getDatabase()->execute('COMMIT');
                $result = ['error' => false, 'is_message' => false];
            } catch (\Throwable $error) {
                $app->getDatabase()->execute('ROLLBACK');
                if (!$error instanceof \YTassy\Tycms\Response\ExceptionResponse) throw $error;
                $result = json_decode($error->getResponse()->body, true);
                self::assertFalse($app->getOrAddSharedExtensionsClass(AclHelper::class)
                    ->userHasGlobalPermission($this->createdUserId, Permissions::PAGE_READ, 2));
            }
        } elseif ($flow === 'admin') {
            $result = $this->api('users/create', RequestMethod::POST, $key, $csrf, [], [
                'data' => json_encode(['space_id' => 2, 'first_name' => 'Compte', 'last_name' => 'Admin',
                    'email' => $email, 'password' => 'MotDePasseLong123', 'timezone' => 'Europe/Paris',
                    'blocked' => false, 'send_email' => false, 'send_welcome_email' => false]),
            ]);
        } else {
            if ($flow === 'join') [$key, $csrf] = $this->session($this->createdUserId, 2);
            $body = $flow === 'join' ? [] : ['first_name' => 'Compte', 'last_name' => 'Local', 'email' => $email,
                'password' => 'MotDePasseLong123', 'password_confirmation' => 'MotDePasseLong123'];
            $response = $this->request('/auth/api/' . ($flow === 'join' ? 'join' : 'register'), RequestMethod::POST,
                $key, $body + ['csrf_token' => $csrf]);
            $result = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        }

        $statement = $db->prepare('SELECT id, space_id FROM users WHERE email = ?');
        $statement->bind_param('s', $email);
        $statement->execute();
        $user = $statement->get_result()->fetch_assoc();
        if ($user !== null) $this->createdUserId = (int) $user['id'];

        if (!$allowed) {
            self::assertTrue($result['error']);
            self::assertStringContainsString('MEMBER', $result['message']);
            self::assertSame($before, $this->enrollmentRowCounts(), 'Toute la tentative doit être annulée.');
            if (in_array($flow, ['local', 'admin'], true)) self::assertNull($user);
            return;
        }

        self::assertFalse($result['error']);
        self::assertFalse($result['is_message'] ?? false);
        self::assertNotNull($user);
        self::assertSame($flow === 'admin' ? 2 : 1, (int) $user['space_id']);
        [$userKey] = $this->session($this->createdUserId, 2);
        $verified = new Application($this->environment->configuration());
        $verified->initialiseOrThrow(new Request(uri: '/', host: TestFixtures::SITE_HOST, cookies: ['session_key' => $userKey]));
        $acl = $verified->getOrAddSharedExtensionsClass(AclHelper::class);
        self::assertTrue($acl->hasGlobalPermission(Permissions::MEMBER, 2));
        self::assertSame(in_array($grant, ['explicit-member', 'standalone'], true), $acl->hasGlobalRoleOnSpace(Roles::ROLE_MEMBER, 2));
    }

    public static function optionalMembershipScenarios(): array
    {
        $cases = [];
        foreach (['local', 'admin', 'join', 'sso'] as $flow) {
            $cases[$flow . ' avec groupe porteur de MEMBER'] = [$flow, 'group', true];
            $cases[$flow . ' sans MEMBER annule tout'] = [$flow, 'group', false];
        }
        foreach (['role', 'ancestor', 'explicit-member', 'standalone'] as $grant) {
            $cases['local avec ' . $grant] = ['local', $grant, true];
        }
        return $cases;
    }

    private function enrollmentRowCounts(): array
    {
        $counts = [];
        foreach (['users', 'user_groups', 'user_groups_map', 'acl_map'] as $table) {
            $counts[$table] = (int) $this->database->connection()->query('SELECT COUNT(*) AS total FROM ' . $table)
                ->fetch_assoc()['total'];
        }
        return $counts;
    }

    #[Test]
    public function adminCreationKeepsTheExplicitPrincipalSpace(): void
    {
        $this->configure(2, true, [], [], 1);
        [$adminKey, $csrf] = $this->session(1, 2);
        $response = $this->api('users/create', RequestMethod::POST, $adminKey, $csrf, [], [
            'data' => json_encode([
                'space_id' => 2, 'first_name' => 'Compte', 'last_name' => 'Administratif',
                'email' => 'admin-target-' . bin2hex(random_bytes(6)) . '@example.test',
                'timezone' => 'Europe/Paris', 'blocked' => false, 'send_email' => false,
                'password' => 'MotDePasseLong123', 'send_welcome_email' => false,
            ], JSON_THROW_ON_ERROR),
        ]);
        self::assertFalse($response['error']);
        $this->createdUserId = (int) $response['user']['id'];
        self::assertSame(2, $response['user']['space_id']);
        $group = $this->database->connection()->query('SELECT ug.space_id FROM user_groups ug '
            . 'INNER JOIN user_groups_map gm ON gm.group_id = ug.id WHERE ug.type = 1 AND gm.user_id = '
            . $this->createdUserId)->fetch_assoc();
        self::assertSame(2, (int) $group['space_id']);
    }

    #[Test]
    public function closedSpaceRejectsRegistrationAndJoining(): void
    {
        $this->configure(2, false, [], ['this' => [5]]);
        [$guestKey, $csrf] = $this->session(null, 2);

        $response = $this->request('/auth/api/register', RequestMethod::POST, $guestKey, [
            'first_name' => 'Bob', 'last_name' => 'Martin',
            'email' => 'closed.registration.integration@example.test',
            'password' => 'MotDePasseLong123',
            'password_confirmation' => 'MotDePasseLong123',
            'csrf_token' => $csrf,
        ]);
        self::assertSame(200, $response->code);
        self::assertSame(0, (int) $this->database->connection()->query(
            "SELECT COUNT(*) AS total FROM users WHERE email = 'closed.registration.integration@example.test'"
        )->fetch_assoc()['total']);

        // Un compte valide d'un autre espace ne peut pas contourner la fermeture.
        $db = $this->database->connection();
        $db->query("INSERT INTO users (space_id, first_name, last_name, email, password, params) "
            . "VALUES (1, 'Bob', 'Martin', 'existing.registration.integration@example.test', 'unused', '{}')");
        $this->createdUserId = (int) $db->insert_id;
        $db->query('INSERT INTO user_groups (space_id, parent_id, type, name) VALUES '
            . '(1, 2, 1, \'existing-registration-' . $this->createdUserId . '\')');
        $groupId = (int) $db->insert_id;
        $db->query('INSERT INTO user_groups_map (user_id, group_id) VALUES ('
            . $this->createdUserId . ', ' . $groupId . ')');
        [$userKey, $userCsrf] = $this->session($this->createdUserId, 2);

        $this->request('/auth/api/join', RequestMethod::POST, $userKey, ['csrf_token' => $userCsrf]);
        self::assertSame(0, (int) $db->query('SELECT COUNT(*) AS total FROM acl_map '
            . 'WHERE group_id = ' . $groupId . ' AND space_id = 2')->fetch_assoc()['total']);
    }

    #[Test]
    public function registrationAssignsConfiguredOrdinaryGroupOnlyOnce(): void
    {
        $db = $this->database->connection();
        $db->query("INSERT INTO user_groups (space_id, parent_id, type, name) VALUES "
            . "(2, NULL, 0, 'registration-group-" . bin2hex(random_bytes(6)) . "')");
        $groupId = (int) $db->insert_id;
        $this->createdGroupIds[] = $groupId;

        $this->configure(2, true, [], []);
        [$adminKey, $adminCsrf] = $this->session(1, 2);
        $form = $this->api('spaces/form', RequestMethod::GET, $adminKey, $adminCsrf, ['spaceId' => '2']);
        $fields = array_merge(...array_map(static fn (array $section): array => $section['fields'] ?? [],
            $form['form']['sections']));
        $byName = array_column($fields, null, 'name');
        self::assertArrayHasKey('registration_groups', $byName);
        self::assertArrayHasKey('user_groups', $byName);

        $values = [];
        foreach ($fields as $field) {
            $name = $field['name'] ?? null;
            if ($name !== null && ($field['submittable'] ?? true)
                && array_key_exists($name, $form['form']['initialValues'])) {
                $values[$name] = $form['form']['initialValues'][$name];
            }
        }
        $values['registration_groups'] = [$groupId];
        $values['user_groups'] = [$groupId];
        $values['registration_roles'] = (object) [];
        $values['user_roles'] = (object) [];
        $saved = $this->api('spaces/update', RequestMethod::POST, $adminKey, $adminCsrf, [], [
            'spaceId' => '2', 'data' => json_encode($values, JSON_THROW_ON_ERROR),
        ]);
        self::assertFalse($saved['error']);
        self::assertFalse($saved['is_message'] ?? false, json_encode($saved, JSON_THROW_ON_ERROR));

        $values['registration_groups'] = [100];
        $rejected = $this->api('spaces/update', RequestMethod::POST, $adminKey, $adminCsrf, [], [
            'spaceId' => '2', 'data' => json_encode($values, JSON_THROW_ON_ERROR),
        ]);
        self::assertTrue(($rejected['is_message'] ?? false) || ($rejected['error'] ?? false));

        [$guestKey, $csrf] = $this->session(null, 2);
        $response = $this->request('/auth/api/register', RequestMethod::POST, $guestKey, [
            'first_name' => 'Groupe', 'last_name' => 'Test',
            'email' => 'registration-group-' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => 'MotDePasseLong123', 'password_confirmation' => 'MotDePasseLong123',
            'csrf_token' => $csrf,
        ]);
        self::assertSame(200, $response->code);

        $this->createdUserId = (int) $db->query('SELECT user_id FROM user_groups_map WHERE group_id = '
            . $groupId . ' LIMIT 1')->fetch_assoc()['user_id'];
        self::assertSame(1, (int) $db->query('SELECT COUNT(*) AS total FROM user_groups_map WHERE group_id = '
            . $groupId . ' AND user_id = ' . $this->createdUserId)->fetch_assoc()['total']);
    }

    #[Test]
    public function spaceEditorPersistsBothRoleMaps(): void
    {
        [$adminKey, $csrf] = $this->session(1, 2);
        $formResponse = $this->api('spaces/form', RequestMethod::GET, $adminKey, $csrf, ['spaceId' => '2']);
        self::assertFalse($formResponse['error']);

        $form = $formResponse['form'];
        $fields = array_merge(...array_map(static fn (array $section): array => $section['fields'] ?? [], $form['sections']));
        $byName = array_column($fields, null, 'name');
        self::assertArrayHasKey('registration_roles', $byName);
        self::assertArrayHasKey('user_roles', $byName);
        self::assertArrayHasKey('sso_allow_reset_local_password', $byName);
        self::assertArrayHasKey('registration_target_space_id', $byName);
        self::assertNull($form['initialValues']['registration_target_space_id']);
        self::assertFalse($form['initialValues']['sso_allow_reset_local_password']);
        self::assertTrue($form['initialValues']['registration_auto_member_role']);
        self::assertArrayHasKey('this', $byName['registration_roles']['schema']['properties']);
        self::assertArrayHasKey('space_1', $byName['registration_roles']['schema']['properties']);
        foreach ($byName['registration_roles']['schema']['properties'] as $target) {
            self::assertNotContains(1, $target['items']['enum']);
            self::assertNotContains(3, $target['items']['enum']);
        }

        $values = [];
        foreach ($fields as $field) {
            $name = $field['name'] ?? null;
            if ($name !== null && ($field['submittable'] ?? true)
                && array_key_exists($name, $form['initialValues'])) {
                $values[$name] = $form['initialValues'][$name];
            }
        }
        // Le schéma HTTP et le formulaire serveur doivent refuser toute coercition en booléen.
        foreach ([null, 0, 'false'] as $invalid) {
            $values['registration_auto_member_role'] = $invalid;
            $rejected = $this->api('spaces/update', RequestMethod::POST, $adminKey, $csrf, [], [
                'spaceId' => '2', 'data' => json_encode($values, JSON_THROW_ON_ERROR),
            ]);
            self::assertTrue(($rejected['is_message'] ?? false) || ($rejected['error'] ?? false));
        }
        foreach (['registration_roles', 'user_roles'] as $setting) {
            foreach ([1, 3] as $roleId) {
                $invalidValues = $values;
                $invalidValues['registration_auto_member_role'] = true;
                $invalidValues[$setting] = ['this' => [$roleId]];
                $rejected = $this->api('spaces/update', RequestMethod::POST, $adminKey, $csrf, [], [
                    'spaceId' => '2', 'data' => json_encode($invalidValues, JSON_THROW_ON_ERROR),
                ]);
                self::assertTrue(($rejected['is_message'] ?? false) || ($rejected['error'] ?? false));
            }
        }
        $values['registration_auto_member_role'] = false;
        $values['registration_roles'] = ['this' => [5], 'space_1' => [4]];
        $values['user_roles'] = ['this' => [5]];
        $values['sso_allow_reset_local_password'] = true;
        $values['registration_target_space_id'] = 1;

        $saved = $this->api('spaces/update', RequestMethod::POST, $adminKey, $csrf, [], [
            'spaceId' => '2', 'data' => json_encode($values, JSON_THROW_ON_ERROR),
        ]);
        self::assertFalse($saved['error']);

        $params = json_decode($this->database->connection()->query('SELECT params FROM spaces WHERE id = 2')
            ->fetch_assoc()['params'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([5], $params['registration_roles']['this']);
        self::assertSame([4], $params['registration_roles']['1']);
        self::assertSame([5], $params['user_roles']['this']);
        self::assertTrue($params['sso_allow_reset_local_password']);
        self::assertSame(1, $params['registration_target_space_id']);
        self::assertFalse($params['registration_auto_member_role']);

        // L'absence du nouveau champ conserve une cible existante ; null la supprime explicitement.
        unset($values['registration_target_space_id'], $values['registration_auto_member_role']);
        $saved = $this->api('spaces/update', RequestMethod::POST, $adminKey, $csrf, [], [
            'spaceId' => '2', 'data' => json_encode($values, JSON_THROW_ON_ERROR),
        ]);
        self::assertFalse($saved['error']);
        $params = json_decode($this->database->connection()->query('SELECT params FROM spaces WHERE id = 2')
            ->fetch_assoc()['params'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $params['registration_target_space_id']);
        self::assertFalse($params['registration_auto_member_role']);

        $values['registration_target_space_id'] = null;
        $saved = $this->api('spaces/update', RequestMethod::POST, $adminKey, $csrf, [], [
            'spaceId' => '2', 'data' => json_encode($values, JSON_THROW_ON_ERROR),
        ]);
        self::assertFalse($saved['error']);
        $params = json_decode($this->database->connection()->query('SELECT params FROM spaces WHERE id = 2')
            ->fetch_assoc()['params'], true, flags: JSON_THROW_ON_ERROR);
        self::assertNull($params['registration_target_space_id']);

        $values['registration_target_space_id'] = 99999;
        $rejected = $this->api('spaces/update', RequestMethod::POST, $adminKey, $csrf, [], [
            'spaceId' => '2', 'data' => json_encode($values, JSON_THROW_ON_ERROR),
        ]);
        self::assertTrue(($rejected['is_message'] ?? false) || ($rejected['error'] ?? false));
        $values['registration_target_space_id'] = null;

        // Une cible absente du formulaire ne peut pas être ajoutée par un POST forgé.
        $values['registration_roles']['space_99999'] = [5];
        $rejected = $this->api('spaces/update', RequestMethod::POST, $adminKey, $csrf, [], [
            'spaceId' => '2', 'data' => json_encode($values, JSON_THROW_ON_ERROR),
        ]);
        self::assertTrue(($rejected['is_message'] ?? false) || ($rejected['error'] ?? false));
    }

    #[Test]
    public function newSpaceCanBeCreatedWithDefaultRegistrationRoles(): void
    {
        [$adminKey, $csrf] = $this->session(1, 2);
        $formResponse = $this->api('spaces/form', RequestMethod::GET, $adminKey, $csrf);
        self::assertFalse($formResponse['error']);

        $form = $formResponse['form'];
        $fields = array_merge(...array_map(static fn (array $section): array => $section['fields'] ?? [], $form['sections']));
        $values = [];
        foreach ($fields as $field) {
            $name = $field['name'] ?? null;
            if ($name !== null && ($field['submittable'] ?? true)
                && array_key_exists($name, $form['initialValues'])) {
                $values[$name] = $form['initialValues'][$name];
            }
        }
        $values['parent_id'] = 2;
        $values['template'] = 'tpl_main';
        $values['hosts'] = '';
        $values['title'] = 'Inscription test';

        // Le nom doit être sûr et distinct même si la casse diffère.
        foreach (['../interdit', 'ROOT-FIXTURE'] as $invalidDir) {
            $values['medias_dir'] = $invalidDir;
            $rejected = $this->api('spaces/create', RequestMethod::POST, $adminKey, $csrf, [], [
                'data' => json_encode($values, JSON_THROW_ON_ERROR),
            ]);
            self::assertTrue(($rejected['is_message'] ?? false) || ($rejected['error'] ?? false));
        }
        $values['medias_dir'] = 'test-create-' . bin2hex(random_bytes(6));
        $this->createdMediaDir = $this->environment->configuration()->medias_root . '/' . $values['medias_dir'];

        $created = $this->api('spaces/create', RequestMethod::POST, $adminKey, $csrf, [], [
            'data' => json_encode($values, JSON_THROW_ON_ERROR),
        ]);
        self::assertFalse($created['error']);
        $this->createdSpaceId = (int) $created['space']['id'];
        self::assertSame($values['medias_dir'], $created['space']['medias_dir']);

        // La contrainte SQL protège également les écritures hors workflow.
        try {
            $this->database->connection()->query("UPDATE spaces SET medias_dir = 'ROOT-FIXTURE' WHERE id = " . $this->createdSpaceId);
            self::fail('La base doit refuser un dossier déjà attribué.');
        } catch (\mysqli_sql_exception $exception) {
            self::assertSame(1062, $exception->getCode());
        }

        $params = json_decode($this->database->connection()->query(
            'SELECT params FROM spaces WHERE id = ' . $this->createdSpaceId
        )->fetch_assoc()['params'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([], $params['user_roles']);
    }

    #[Test]
    public function aclDistinguishesRoleOnExactSpaceFromInheritedPermission(): void
    {
        [$adminKey] = $this->session(1, 2);
        $request = new Request(uri: '/auth/api/register', host: TestFixtures::SITE_HOST,
            cookies: ['session_key' => $adminKey]);
        $application = new Application($this->environment->configuration());
        $application->initialiseOrThrow($request);
        $acl = $application->getOrAddSharedExtensionsClass(AclHelper::class);

        // MEMBER suffit à l’appartenance même sans rôle 5, et descend vers le Site.
        self::assertTrue($acl->hasGlobalPermission(Permissions::MEMBER, 2));
        self::assertTrue($acl->userHasGlobalPermission(1, Permissions::MEMBER, 2));
        self::assertTrue($acl->hasGlobalRole(Roles::ROLE_ROOT, 2));
        self::assertFalse($acl->hasGlobalRoleOnSpace(Roles::ROLE_ROOT, 2));
        self::assertTrue($acl->hasGlobalRoleOnSpace(Roles::ROLE_ROOT, 1));
        self::assertFalse($acl->hasGlobalRoleOnSpace(Roles::ROLE_MEMBER, 2));
        self::assertFalse($acl->userHasGlobalRoleOnSpace(1, Roles::ROLE_MEMBER, 2));

        // Au retour SSO, le compte connu n'est pas encore l'utilisateur de la session.
        // Les deux contrôles suivants réutilisent la même liste chargée pour ce compte.
        [$guestKey] = $this->session(null, 2);
        $guestRequest = new Request(uri: '/auth/api/register', host: TestFixtures::SITE_HOST,
            cookies: ['session_key' => $guestKey]);
        $guestApplication = new Application($this->environment->configuration());
        $guestApplication->initialiseOrThrow($guestRequest);
        $guestAcl = $guestApplication->getOrAddSharedExtensionsClass(AclHelper::class);
        self::assertTrue($guestAcl->userHasGlobalPermission(1, Permissions::MEMBER, 2));
        self::assertTrue($guestAcl->userHasGlobalPermission(1, Permissions::MEMBER, 1));
        self::assertFalse($guestAcl->userHasGlobalPermission(999999999, Permissions::MEMBER, 2));
        self::assertTrue($guestAcl->userHasGlobalRoleOnSpace(1, Roles::ROLE_ROOT, 1));
        self::assertFalse($guestAcl->userHasGlobalRoleOnSpace(1, Roles::ROLE_ROOT, 2));
    }

    #[Test]
    public function inheritedMembershipSkipsClosedSpaceEnrollmentLocallyAndForSso(): void
    {
        $this->configure(2, false, [], ['this' => [4]]);
        [$adminKey] = $this->session(1, 2);
        $application = new Application($this->environment->configuration());
        $application->initialiseOrThrow(new Request(uri: '/auth/api/register', host: TestFixtures::SITE_HOST,
            cookies: ['session_key' => $adminKey]));
        $workflow = new WFUser($application);
        self::assertTrue($workflow->isRegisteredInCurrentSpace());
        $before = $this->database->connection()->query('SELECT COUNT(*) AS total FROM acl_map')->fetch_assoc();
        $workflow->joinCurrentSpace();

        // Le retour SSO examine un utilisateur qui n’est pas encore dans la session.
        [$guestKey] = $this->session(null, 2);
        $guestApplication = new Application($this->environment->configuration());
        $guestApplication->initialiseOrThrow(new Request(uri: '/auth/api/register', host: TestFixtures::SITE_HOST,
            cookies: ['session_key' => $guestKey]));
        $space = $guestApplication->getOrAddSharedExtensionsClass(
            SpaceHelper::class)->getCurrentSpace();
        $sso = new WFSso($guestApplication);
        $ensureEligible = new \ReflectionMethod(WFSso::class, 'ensureEligible');
        $ensureEligible->invoke($sso, (object) ['id' => 1, 'blocked' => 0], $space, false);

        // L'accès hérité ne dispense jamais de vérifier le blocage du compte.
        foreach ([(object) ['id' => 1, 'blocked' => 1], (object) ['id' => 999999999, 'blocked' => 0]] as $user) {
            try {
                $ensureEligible->invoke($sso, $user, $space, false);
                self::fail('Le compte bloqué ou sans MEMBER doit être refusé.');
            } catch (\RuntimeException $exception) {
                self::assertSame('Compte non autorisé dans cet espace.', $exception->getMessage());
            }
        }

        // Aucun rôle d’inscription de l’enfant ne doit être appliqué une seconde fois.
        self::assertSame($before, $this->database->connection()->query(
            'SELECT COUNT(*) AS total FROM acl_map')->fetch_assoc());
    }

    #[Test]
    public function targetSpaceDeletionIsBlockedByRegistrationReferences(): void
    {
        $this->configure(1, true, [], [], 2);
        [$adminKey] = $this->session(1, 2);
        $request = new Request(uri: '/auth/api/register', host: TestFixtures::SITE_HOST,
            cookies: ['session_key' => $adminKey]);
        $application = new Application($this->environment->configuration());
        $application->initialiseOrThrow($request);

        $blockers = $application->getOrAddSharedExtensionsClass(SpaceAdminHelper::class)->getDeletionBlockers(2);
        self::assertContains('des espaces ciblant cet espace pour leurs inscriptions', $blockers);
    }

    #[Test]
    public function enrollmentRejectsImplicitRolesEvenFromAStoredConfiguration(): void
    {
        [$key] = $this->session(1, 2);
        $app = new Application($this->environment->configuration());
        $app->initialiseOrThrow(new Request(uri: '/', host: TestFixtures::SITE_HOST, cookies: ['session_key' => $key]));
        $helper = $app->getOrAddSharedExtensionsClass(\YTassy\Tycms\CoreExts\Core\Helper\UserRegistrationHelper::class);
        $space = $app->getOrAddSharedExtensionsClass(SpaceHelper::class)->getSpace(2);
        $initialParams = $space->params;
        $before = $this->database->connection()->query('SELECT COUNT(*) AS total FROM acl_map')->fetch_assoc();
        $app->getDatabase()->execute('START TRANSACTION');
        try {
            foreach (['registration_roles', 'user_roles'] as $setting) {
                foreach ([1, 3] as $roleId) {
                    $params = json_decode($this->originalParams[2], true, flags: JSON_THROW_ON_ERROR);
                    $params[$setting] = ['this' => [$roleId]];
                    $space->params = $params;
                    try {
                        $helper->enroll(1, $space);
                        self::fail('Un rôle implicite ne doit pas être attribué par une inscription.');
                    } catch (\RuntimeException $exception) {
                        self::assertStringContainsString('rôle d’inscription invalide', $exception->getMessage());
                    }
                }
            }
        } finally {
            $space->params = $initialParams;
            $app->getDatabase()->execute('ROLLBACK');
        }
        self::assertSame($before, $this->database->connection()->query('SELECT COUNT(*) AS total FROM acl_map')->fetch_assoc());
    }

    private function configure(int $spaceId, bool $enabled, array $registrationRoles, array $userRoles, ?int $targetId = null): void
    {
        $params = json_decode($this->originalParams[$spaceId], true, flags: JSON_THROW_ON_ERROR);
        $params['registration_enabled'] = $enabled;
        $params['registration_target_space_id'] = $targetId;
        $params['registration_roles'] = (object) $registrationRoles;
        $params['user_roles'] = (object) $userRoles;
        $json = json_encode($params, JSON_THROW_ON_ERROR);

        $statement = $this->database->connection()->prepare('UPDATE spaces SET params = ? WHERE id = ?');
        $statement->bind_param('si', $json, $spaceId);
        $statement->execute();
    }

    /** @return array{string,string} */
    private function session(?int $userId, int $spaceId): array
    {
        $key = bin2hex(random_bytes(24));
        $csrf = bin2hex(random_bytes(32));
        $data = serialize(['user_id' => $userId, 'current_space_id' => $spaceId, 'csrf_token' => $csrf]);
        $statement = $this->database->connection()->prepare(
            'INSERT INTO sessions (`key`, `user_id`, `data`) VALUES (?, ?, ?)'
        );
        $statement->bind_param('sis', $key, $userId, $data);
        $statement->execute();
        $this->sessionKeys[] = $key;

        return [$key, $csrf];
    }

    private function request(string $uri, RequestMethod $method, string $key, array $body): \YTassy\Tycms\Response\Response
    {
        $context = new Application($this->environment->configuration());
        $context->initialiseOrThrow(new Request(uri: '/', host: TestFixtures::SITE_HOST, cookies: ['session_key' => $key]));
        $spaceId = $context->getOrAddSharedExtensionsClass(SpaceHelper::class)->getCurrentSpace()->id;
        $token = $this->authPages->token($context, $spaceId);
        $csrf = $body['csrf_token'];
        unset($body['csrf_token']);
        $payload = ['token' => $token, 'acceptedTerms' => '0'];
        if ($uri === '/auth/api/register') $payload['data'] = json_encode($body, JSON_THROW_ON_ERROR);
        $request = new Request(
            method: $method, uri: $uri, body: $payload,
            headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $csrf],
            host: TestFixtures::SITE_HOST, cookies: ['session_key' => $key]
        );
        $application = new Application($this->environment->configuration());
        $application->initialiseOrThrow($request);

        return $application->executeAndGetResponse();
    }

    private function api(
        string $path, RequestMethod $method, string $key, string $csrf, array $query = [], array $body = []
    ): array {
        $request = new Request(
            method: $method, uri: '/administrator/api/' . $path, query: $query, body: $body,
            headers: ['X-CSRF-Token' => $csrf],
            host: TestFixtures::SITE_HOST, cookies: ['session_key' => $key]
        );
        $application = new Application($this->environment->configuration());
        $application->initialiseOrThrow($request);

        return json_decode($application->executeAndGetResponse()->body ?? '', true, flags: JSON_THROW_ON_ERROR);
    }
}
