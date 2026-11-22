<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Integration;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Core\Helper\Mail\MailHelper;
use YTassy\Tycms\CoreExts\Core\Helper\UserHelper;
use YTassy\Tycms\CoreExts\Core\Models\Space;
use YTassy\Tycms\Request\Request;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Response\Response;
use YTassy\Tycms\Tests\Support\Integration\TestDatabase;
use YTassy\Tycms\Tests\Support\Integration\TestEnvironment;
use YTassy\Tycms\Tests\Support\Integration\TestFixtures;

final class PasswordResetIntegrationTest extends TestCase
{
    private TestEnvironment $environment;
    private TestDatabase $database;
    private array $originalParams = [];
    private array $sessions = [];
    private array $messages = [];
    private int $userId;
    private int $groupId;
    private string $email;
    private ?int $providerId = null;

    protected function setUp(): void
    {
        $this->environment = TestEnvironment::load();
        $this->database = new TestDatabase($this->environment);
        TestFixtures::assertPrepared($this->database);

        foreach ([1, 2] as $id) {
            $this->originalParams[$id] = $this->database->connection()
                ->query('SELECT params FROM spaces WHERE id = ' . $id)->fetch_assoc()['params'];
            $this->configureSpace($id, ['main_host' => 'localhost:8088', 'sso_allow_reset_local_password' => false]);
        }

        // Un compte racine sans adhésion au Site permet de vérifier la récupération globale.
        $this->email = 'reset-' . bin2hex(random_bytes(6)) . '@integration.test';
        $statement = $this->database->connection()->prepare(
            "INSERT INTO users (space_id, email, password, params) VALUES (1, ?, 'old-hash', '{}')",
        );
        $statement->bind_param('s', $this->email);
        $statement->execute();
        $this->userId = (int) $this->database->connection()->insert_id;
        $db = $this->database->connection();
        $db->query("INSERT INTO user_groups (space_id, parent_id, type, name) VALUES (1, 2, 1, 'reset-" . $this->userId . "')");
        $this->groupId = (int) $db->insert_id;
        $db->query('INSERT INTO user_groups_map (user_id, group_id) VALUES (' . $this->userId . ', ' . $this->groupId . ')');
    }

    protected function tearDown(): void
    {
        $db = $this->database->connection();
        $db->query('DELETE FROM users WHERE id = ' . $this->userId);
        $db->query('DELETE FROM user_groups WHERE id = ' . $this->groupId);
        if ($this->providerId !== null) {
            $db->query('DELETE FROM sso_providers WHERE id = ' . $this->providerId);
        }
        foreach ($this->sessions as $key) {
            $statement = $db->prepare('DELETE FROM sessions WHERE `key` = ?');
            $statement->bind_param('s', $key);
            $statement->execute();
        }
        foreach ($this->originalParams as $id => $params) {
            $statement = $db->prepare('UPDATE spaces SET params = ? WHERE id = ?');
            $statement->bind_param('si', $params, $id);
            $statement->execute();
        }
    }

    public function testRequestReplacementAndSingleUseRevokeOnlyTheTargetSessions(): void
    {
        $guest = $this->session();
        $page = $this->request('/forgot-password', RequestMethod::GET, $guest);
        self::assertSame(200, $page->code);
        self::assertStringContainsString('name="csrf_token"', $page->body);

        $this->requestReset($guest, csrf: 'invalid');
        self::assertSame('', $this->user()['reset_password_token']);
        self::assertCount(0, $this->messages);

        $this->requestReset($guest);
        $first = $this->token();
        self::assertSame(hash('sha256', $first), $this->user()['reset_password_token']);
        self::assertStringStartsWith('http://localhost:8088/reset-password/', $this->messages[0]['reset_url']);

        // Le délai est commun aux navigateurs, sans modifier le lien pendant l'attente.
        $this->requestReset($this->session());
        self::assertCount(1, $this->messages);
        self::assertSame(hash('sha256', $first), $this->user()['reset_password_token']);

        // Rendre le délai configurable permet un nouvel envoi immédiat dans ce scénario.
        $this->requestReset($guest, configuration: ['password_reset_request_delay' => 0]);
        $token = $this->token();
        self::assertNotSame($first, $token);
        self::assertStringNotContainsString('name="password"',
            $this->request('/reset-password/' . $first, RequestMethod::GET, $guest)->body);
        $form = $this->request('/reset-password/' . $token, RequestMethod::GET, $guest);
        self::assertSame('no-referrer', $form->httpHeaders['Referrer-Policy']);
        self::assertStringContainsString('name="password_confirmation"', $form->body);
        self::assertSame(hash('sha256', $token), $this->user()['reset_password_token']);

        $this->save($guest, $token, confirmation: 'different');
        self::assertSame('old-hash', $this->user()['password']);
        $this->save($guest, $token, csrf: 'invalid');
        self::assertSame('old-hash', $this->user()['password']);

        $this->session($this->userId);
        $this->session($this->userId);
        $other = $this->session(1);
        $saved = $this->save($guest, $token);
        self::assertSame(303, $saved->code);
        $user = $this->user();
        self::assertTrue(password_verify('NouveauMotDePasse123', $user['password']));
        self::assertSame('', $user['reset_password_token']);
        self::assertSame(1, (int) $user['reset_count']);
        self::assertNotNull($user['reset_password_last_reset_date']);
        self::assertSame(0, (int) $this->database->connection()->query(
            'SELECT COUNT(*) AS n FROM sessions WHERE user_id = ' . $this->userId,
        )->fetch_assoc()['n']);
        self::assertSame(1, (int) $this->database->connection()->query(
            "SELECT COUNT(*) AS n FROM sessions WHERE `key` = '" . $other[0] . "'",
        )->fetch_assoc()['n']);
        $this->save($guest, $token);
        self::assertSame(1, (int) $this->user()['reset_count']);
    }

    public function testExpiryIsStrictAndUsesConfiguration(): void
    {
        $guest = $this->session();
        $token = $this->seedToken(1800);
        $this->save($guest, $token);
        self::assertSame('old-hash', $this->user()['password']);

        $this->save($guest, $token, configuration: ['password_reset_token_lifetime' => 3600]);
        self::assertSame(1, (int) $this->user()['reset_count']);

        $token = $this->seedToken(11);
        $this->save($guest, $token, configuration: ['password_reset_token_lifetime' => 10]);
        self::assertSame(1, (int) $this->user()['reset_count']);
    }

    public function testResetWhileLoggedInCreatesAGuestSession(): void
    {
        $token = $this->seedToken(0);
        $current = $this->session($this->userId);
        $this->save($current, $token);
        self::assertSame(1, (int) $this->user()['reset_count']);
        self::assertSame(0, (int) $this->database->connection()->query(
            'SELECT COUNT(*) AS n FROM sessions WHERE user_id = ' . $this->userId,
        )->fetch_assoc()['n']);
    }

    public function testSsoPolicyComesFromAccountOriginAndIsCheckedAgainOnSave(): void
    {
        $db = $this->database->connection();
        $db->query("INSERT INTO sso_providers (space_id, name, protocol, config, mapping, login_button_text, register_button_text) "
            . "VALUES (1, 'Reset test', 'oidc', '{}', '{}', '', '')");
        $this->providerId = (int) $db->insert_id;
        $db->query("INSERT INTO sso_links (user_id, provider_id, external_subject, data) VALUES ("
            . $this->userId . ', ' . $this->providerId . ", 'reset-test', 'unused')");
        $guest = $this->session();

        // Autoriser le Site ne doit pas contourner le refus de l'espace de création.
        $this->configureSpace(2, ['sso_allow_reset_local_password' => true]);
        $this->requestReset($guest);
        self::assertCount(0, $this->messages);

        $this->configureSpace(1, ['sso_allow_reset_local_password' => true]);
        $this->requestReset($guest);
        self::assertCount(1, $this->messages);
        $token = $this->token();
        $this->configureSpace(1, ['sso_allow_reset_local_password' => false]);
        $this->save($guest, $token);
        self::assertSame('old-hash', $this->user()['password']);
    }

    public function testUnknownBlockedAndSmtpFailureKeepTheSamePublicConfirmation(): void
    {
        $guest = $this->session();
        $this->requestReset($guest, email: 'unknown-reset@integration.test');
        $unknown = $this->request('/forgot-password', RequestMethod::GET, $guest)->body;
        $this->database->connection()->query('UPDATE users SET blocked = 1 WHERE id = ' . $this->userId);
        $this->requestReset($guest);
        $blocked = $this->request('/forgot-password', RequestMethod::GET, $guest)->body;
        self::assertSame($unknown, $blocked);
        self::assertCount(0, $this->messages);

        $this->database->connection()->query('UPDATE users SET blocked = 0 WHERE id = ' . $this->userId);
        $this->requestReset($guest, sent: false);
        self::assertSame('', $this->user()['reset_password_token']);
        self::assertSame($unknown, $this->request('/forgot-password', RequestMethod::GET, $guest)->body);
    }

    public function testPasswordChangedElsewhereInvalidatesTheLink(): void
    {
        $token = $this->seedToken(0);
        $guest = $this->session();
        $app = $this->application('/forgot-password', RequestMethod::GET, $guest);
        $app->getOrAddSharedExtensionsClass(UserHelper::class)->updatePassword($this->userId, 'AutreMotDePasse123');
        self::assertSame('', $this->user()['reset_password_token']);
        $this->save($guest, $token);
        self::assertTrue(password_verify('AutreMotDePasse123', $this->user()['password']));
    }

    public function testRecoveryRejectsTruncatedPasswordsWithoutConsumingTheToken(): void
    {
        $session = $this->session();
        $token = $this->seedToken(0);
        foreach ([str_repeat('a', 73), str_repeat('é', 37), "mot-de-passe\0invalide"] as $password) {
            $this->request('/reset-password/' . $token, RequestMethod::POST, $session, [
                'password' => $password, 'password_confirmation' => $password, 'csrf_token' => $session[1],
            ]);
            self::assertSame('old-hash', $this->user()['password']);
            self::assertSame(hash('sha256', $token), $this->user()['reset_password_token']);
        }
    }

    public function testRecoveryLinkIsAccessibleWithoutASpaceOrBrowserSession(): void
    {
        $token = $this->seedToken(0);
        $response = $this->request('/reset-password/' . $token, RequestMethod::GET, ['', ''], host: 'ambiguous.integration.test');
        self::assertSame(200, $response->code);
        self::assertStringContainsString('name="password_confirmation"', $response->body);
    }

    private function requestReset(array $session, ?string $email = null, ?string $csrf = null,
        array $configuration = [], bool $sent = true): Response
    {
        return $this->request('/forgot-password', RequestMethod::POST, $session,
            ['email' => $email ?? $this->email, 'csrf_token' => $csrf ?? $session[1]], $configuration, $sent);
    }

    private function save(array $session, string $token, ?string $confirmation = null,
        ?string $csrf = null, array $configuration = []): Response
    {
        return $this->request('/reset-password/' . $token, RequestMethod::POST, $session, [
            'password' => 'NouveauMotDePasse123',
            'password_confirmation' => $confirmation ?? 'NouveauMotDePasse123',
            'csrf_token' => $csrf ?? $session[1],
        ], $configuration);
    }

    private function request(string $uri, RequestMethod $method, array $session, array $body = [],
        array $configuration = [], bool $sent = true, string $host = TestFixtures::SITE_HOST): Response
    {
        $app = $this->application($uri, $method, $session, $body, $configuration, $host);
        // Remplacer uniquement le transport : routage, transactions et SQL sont réels.
        $record = function ($key, $to, $values): void {
            self::assertSame('plg_core__password_reset', $key);
            self::assertSame($this->email, $to);
            $this->messages[] = $values;
        };
        $mailer = new class($app, $record, $sent) extends MailHelper {
            public function __construct(Application $app, private \Closure $record, private bool $sent)
            {
                parent::__construct($app);
            }

            public function sendTemplate(string $key, string|array $to, array $values,
                Space|int|null $sourceSpace = null, ?string $locale = null, string|array $cc = [],
                string|array $bcc = [], string|array $replyTo = [], array $attachments = []): bool
            {
                ($this->record)($key, $to, $values);
                return $this->sent;
            }
        };
        $property = new ReflectionProperty(Application::class, 'sharedExtensionsClasses');
        $shared = $property->getValue($app);
        $shared[MailHelper::class] = $mailer;
        $property->setValue($app, $shared);

        $response = $app->executeAndGetResponse();
        $this->sessions[] = new ReflectionProperty($app->getSession(), 'sessionKey')->getValue($app->getSession());

        return $response;
    }

    private function application(string $uri, RequestMethod $method, array $session, array $body = [],
        array $configuration = [], string $host = TestFixtures::SITE_HOST): Application
    {
        $conf = $this->environment->configuration();
        foreach ($configuration as $key => $value) $conf->$key = $value;
        $app = new Application($conf);
        $app->initialiseOrThrow(new Request(method: $method, uri: $uri, body: $body,
            host: $host, cookies: ['session_key' => $session[0]]));

        return $app;
    }

    private function session(?int $userId = null): array
    {
        $key = bin2hex(random_bytes(24));
        $csrf = bin2hex(random_bytes(32));
        $data = serialize(['user_id' => $userId, 'current_space_id' => 2, 'csrf_token' => $csrf]);
        $statement = $this->database->connection()->prepare('INSERT INTO sessions (`key`, user_id, data) VALUES (?, ?, ?)');
        $statement->bind_param('sis', $key, $userId, $data);
        $statement->execute();
        $this->sessions[] = $key;

        return [$key, $csrf];
    }

    private function user(): array
    {
        return $this->database->connection()->query('SELECT * FROM users WHERE id = ' . $this->userId)->fetch_assoc();
    }

    private function token(): string
    {
        return basename(parse_url($this->messages[array_key_last($this->messages)]['reset_url'], PHP_URL_PATH));
    }

    private function seedToken(int $ageSeconds): string
    {
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $statement = $this->database->connection()->prepare('UPDATE users SET reset_password_token = ?, '
            . 'reset_password_request_date = DATE_SUB(CURRENT_TIMESTAMP, INTERVAL ? SECOND) WHERE id = ?');
        $statement->bind_param('sii', $hash, $ageSeconds, $this->userId);
        $statement->execute();

        return $token;
    }

    private function configureSpace(int $id, array $changes): void
    {
        $current = $this->database->connection()->query('SELECT params FROM spaces WHERE id = ' . $id)->fetch_assoc()['params'];
        $params = json_encode(array_merge(json_decode($current, true, flags: JSON_THROW_ON_ERROR), $changes), JSON_THROW_ON_ERROR);
        $statement = $this->database->connection()->prepare('UPDATE spaces SET params = ? WHERE id = ?');
        $statement->bind_param('si', $params, $id);
        $statement->execute();
    }
}
