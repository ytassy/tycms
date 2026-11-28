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

/**
 * Émission du premier événement métier à travers l'API d'administration.
 *
 * Le parcours complet est exercé : workflow, écouteur de journal, transaction
 * de la route. Les comptes créés sont supprimés à la fin du scénario.
 */
final class EventEmissionIntegrationTest extends TestCase
{
    private TestEnvironment $environment;

    private TestDatabase $database;

    /** @var list<string> */
    private array $sessions = [];

    /** @var list<int> */
    private array $users = [];

    private array $rootSession;

    protected function setUp(): void
    {
        $this->environment = TestEnvironment::load();
        $this->database = new TestDatabase($this->environment);
        TestFixtures::assertPrepared($this->database);
        $this->rootSession = $this->session(1);
    }

    protected function tearDown(): void
    {
        $db = $this->database->connection();

        if ($this->users !== []) {
            $db->query('DELETE FROM event_log WHERE subject_id IN (' . implode(',', $this->users) . ')');
        }

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
    }

    #[Test]
    public function assigningAndRemovingARoleWritesOneJournalEventEach(): void
    {
        $user = $this->createUser();
        $userId = (int) $user['id'];

        $assigned = $this->api('role', $this->rootSession, [
            'userId' => $userId,
            'roleId' => 4,
            'spaceId' => TestFixtures::SITE_SPACE_ID,
            'assigned' => '1',
        ]);
        self::assertFalse($assigned['error'] ?? false, json_encode($assigned));

        $row = $this->lastEvent('core.user.role.assigned', $userId);
        self::assertSame(TestFixtures::SITE_SPACE_ID, (int) $row->space_id);
        self::assertSame('user', $row->category);
        self::assertSame('info', $row->severity);
        self::assertSame(1, (int) $row->actor_id);
        self::assertSame('user', $row->actor_type);
        self::assertSame('user', $row->subject_type);

        // La charge utile porte les libellés des textes, jamais un secret.
        $data = json_decode((string) $row->data, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($userId, $data['user']['id']);
        self::assertSame(4, $data['role']['id']);
        self::assertSame(1, $data['actor']['id']);
        self::assertNotSame('', $data['user']['name']);
        self::assertNotSame('', $data['role']['name']);
        self::assertNotSame('', $data['actor']['name']);

        $removed = $this->api('role', $this->rootSession, [
            'userId' => $userId,
            'roleId' => 4,
            'spaceId' => TestFixtures::SITE_SPACE_ID,
            'assigned' => '0',
        ]);
        self::assertFalse($removed['error'] ?? false, json_encode($removed));

        $this->lastEvent('core.user.role.removed', $userId);
        self::assertSame(1, $this->countEvents('core.user.role.assigned', $userId));
        self::assertSame(1, $this->countEvents('core.user.role.removed', $userId));
    }

    #[Test]
    public function batchAssignmentEmitsOnlyActualChanges(): void
    {
        $user = $this->createUser();
        $userId = (int) $user['id'];

        // L'inscription attribue le rôle Membre au groupe personnel du compte.
        $initial = $this->currentRoles($userId);
        self::assertContains(5, array_column($initial, 'role_id'));

        // Aucun changement : aucun événement.
        $unchanged = $this->api('roles/save', $this->rootSession, [
            'userId' => $userId,
            'data' => ['before' => $initial, 'after' => $initial],
        ]);
        self::assertFalse($unchanged['error'] ?? false, json_encode($unchanged));
        self::assertSame(0, $this->countEvents('core.user.role.assigned', $userId));
        self::assertSame(0, $this->countEvents('core.user.role.removed', $userId));

        // Un rôle ajouté : un seul événement d'attribution.
        $siteRole = ['role_id' => 4, 'space_id' => TestFixtures::SITE_SPACE_ID];
        $assign = $this->api('roles/save', $this->rootSession, [
            'userId' => $userId,
            'data' => ['before' => $initial, 'after' => [...$initial, $siteRole]],
        ]);
        self::assertFalse($assign['error'] ?? false, json_encode($assign));
        self::assertSame(1, $this->countEvents('core.user.role.assigned', $userId));
        self::assertSame(0, $this->countEvents('core.user.role.removed', $userId));

        // Un rôle retiré : un seul événement de retrait, sans nouvelle attribution.
        $withRole = $this->currentRoles($userId);
        $remove = $this->api('roles/save', $this->rootSession, [
            'userId' => $userId,
            'data' => ['before' => $withRole, 'after' => $initial],
        ]);
        self::assertFalse($remove['error'] ?? false, json_encode($remove));
        self::assertSame(1, $this->countEvents('core.user.role.assigned', $userId));
        self::assertSame(1, $this->countEvents('core.user.role.removed', $userId));
    }

    private function createUser(): array
    {
        $response = $this->api('create', $this->rootSession, ['data' => [
            'space_id' => TestFixtures::SITE_SPACE_ID,
            'first_name' => 'Compte',
            'last_name' => 'Test',
            'email' => 'event-' . bin2hex(random_bytes(5)) . '@integration.test',
            'timezone' => 'Europe/Paris',
            'blocked' => false,
            'send_email' => false,
            'password' => 'MotDePasseInitial123!',
        ]]);

        self::assertFalse($response['error'] ?? false, json_encode($response));
        $this->users[] = (int) $response['user']['id'];

        return $response['user'];
    }

    private function lastEvent(string $eventType, int $subjectId): object
    {
        $statement = $this->database->connection()->prepare(
            'SELECT * FROM event_log WHERE event_type = ? AND subject_id = ? ORDER BY id DESC LIMIT 1'
        );
        $statement->bind_param('si', $eventType, $subjectId);
        $statement->execute();
        $row = $statement->get_result()->fetch_object();

        self::assertNotNull($row, sprintf('Événement "%s" attendu pour le compte %d.', $eventType, $subjectId));

        return $row;
    }

    private function countEvents(string $eventType, int $subjectId): int
    {
        $statement = $this->database->connection()->prepare(
            'SELECT COUNT(*) AS total FROM event_log WHERE event_type = ? AND subject_id = ?'
        );
        $statement->bind_param('si', $eventType, $subjectId);
        $statement->execute();

        return (int) $statement->get_result()->fetch_assoc()['total'];
    }

    /** @return list<array{role_id:int, space_id:int}> */
    private function currentRoles(int $userId): array
    {
        $response = $this->api('read', $this->rootSession, ['userId' => $userId], RequestMethod::GET);
        self::assertFalse($response['error'] ?? false, json_encode($response));

        return array_map(static fn (array $role): array => [
            'role_id' => (int) $role['id'],
            'space_id' => (int) $role['target_space_id'],
        ], $response['user']['roles']);
    }

    private function session(?int $userId): array
    {
        $key = bin2hex(random_bytes(24));
        $csrf = bin2hex(random_bytes(32));
        $data = serialize(['user_id' => $userId, 'current_space_id' => TestFixtures::SITE_SPACE_ID, 'csrf_token' => $csrf]);
        $statement = $this->database->connection()->prepare(
            'INSERT INTO sessions (`key`, `user_id`, `data`) VALUES (?, ?, ?)'
        );
        $statement->bind_param('sis', $key, $userId, $data);
        $statement->execute();
        $this->sessions[] = $key;

        return [$key, $csrf];
    }

    private function api(
        string $path,
        array $session,
        array $values = [],
        RequestMethod $method = RequestMethod::POST,
    ): array {
        [$key, $csrf] = $session;
        if (isset($values['data'])) {
            $values['data'] = json_encode($values['data'], JSON_THROW_ON_ERROR);
        }

        $request = new Request(
            method: $method,
            uri: '/administrator/api/users/' . $path,
            query: $method === RequestMethod::GET ? $values : [],
            body: $method === RequestMethod::POST ? $values : [],
            headers: ['X-CSRF-Token' => $csrf],
            cookies: ['session_key' => $key],
            host: TestFixtures::SITE_HOST,
        );

        $application = new Application($this->environment->configuration());
        $application->initialiseOrThrow($request);

        return json_decode($application->executeAndGetResponse()->body ?? '', true, flags: JSON_THROW_ON_ERROR);
    }
}
