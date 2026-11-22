<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Core\Helper\AuthenticationHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Space\SpaceHelper;
use YTassy\Tycms\CoreExts\Core\WF\{WFAuthentication, WFSso, WFSpace};
use YTassy\Tycms\Request\{Request, RequestMethod};
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Tests\Support\Integration\{AuthenticationPages, TestDatabase, TestEnvironment, TestFixtures};

final class AuthenticationIntegrationTest extends TestCase
{
    private TestEnvironment $environment;
    private TestDatabase $database;
    private AuthenticationPages $pages;
    private string $originalParams;
    private array $sessions = [];
    private int $userId;
    private string $email;

    protected function setUp(): void
    {
        $this->environment = TestEnvironment::load();
        $this->database = new TestDatabase($this->environment);
        TestFixtures::assertPrepared($this->database);
        $db = $this->database->connection();
        $this->originalParams = $db->query('SELECT params FROM spaces WHERE id = 2')->fetch_assoc()['params'];
        $db->query("UPDATE spaces SET params = JSON_SET(params, '$.registration_enabled', true) WHERE id = 2");
        $this->pages = new AuthenticationPages($this->database);
        $this->pages->install(2, 'register', ['terms_enabled' => true]);
        $this->pages->install(2, 'login');
        $this->email = 'auth-' . bin2hex(random_bytes(6)) . '@integration.test';
        $password = password_hash('MotDePasseLong123', PASSWORD_DEFAULT);
        $statement = $db->prepare("INSERT INTO users (space_id, first_name, last_name, email, password, params) VALUES (2, 'Test', 'Auth', ?, ?, '{}')");
        $statement->bind_param('ss', $this->email, $password);
        $statement->execute();
        $this->userId = (int) $db->insert_id;
    }

    protected function tearDown(): void
    {
        $this->pages->restore();
        $db = $this->database->connection();
        $statement = $db->prepare('UPDATE spaces SET params = ? WHERE id = 2');
        $statement->bind_param('s', $this->originalParams);
        $statement->execute();
        foreach ($this->sessions as [$key]) {
            $statement = $db->prepare('DELETE FROM sessions WHERE `key` = ?');
            $statement->bind_param('s', $key);
            $statement->execute();
        }
        $db->query('DELETE FROM sessions WHERE user_id = ' . $this->userId);
        $db->query('DELETE FROM users WHERE id = ' . $this->userId);
    }

    public function testLegacyRoutesAreRemovedAndFallbackHasNoRegistration(): void
    {
        $session = $this->session();
        foreach (['/login', '/register', '/fr/login', '/fr/register', '/register/join', '/sso/start/1'] as $uri) {
            $app = $this->app($session, $uri);
            self::assertNull($app->getRouter()->resolve(new Request(uri: $uri)), $uri);
        }
        $response = $this->app($session, '/login-fb')->executeAndGetResponse();
        self::assertSame(200, $response->code);
        self::assertStringContainsString('core/authentication', $response->body);
        self::assertStringNotContainsString('S’inscrire', $response->body);
        self::assertStringNotContainsString('Créer mon compte', $response->body);
        self::assertSame('private, no-store', $response->httpHeaders['Cache-Control']);
    }

    public function testLocalLoginUsesApiCsrfAndRotatesSession(): void
    {
        $session = $this->session();
        $token = $this->pages->token($this->app($session), 2, 'login');
        $payload = ['token' => $token, 'email' => $this->email, 'password' => 'MotDePasseLong123'];
        $withoutCsrf = $this->api('login', $session, $payload, false);
        self::assertSame(403, $withoutCsrf->code);
        self::assertNull($this->app($session)->getSession()->getUserId());

        $response = $this->api('login', $session, $payload);
        $data = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($data['error']);
        self::assertFalse($data['is_message']);
        self::assertStringStartsWith('/fr/', $data['url']);
        $statement = $this->database->connection()->prepare('SELECT COUNT(*) AS count FROM sessions WHERE `key` = ?');
        $statement->bind_param('s', $session[0]);
        $statement->execute();
        self::assertSame(0, (int) $statement->get_result()->fetch_assoc()['count']);
    }

    public function testFallbackLoginWorksWithoutConfiguredPages(): void
    {
        $this->database->connection()->query('UPDATE spaces SET menu_item_login_id = NULL, menu_item_register_id = NULL WHERE id = 2');
        $session = $this->session();
        $app = $this->app($session);
        $helper = $app->getOrAddSharedExtensionsClass(AuthenticationHelper::class);
        $space = $app->getOrAddSharedExtensionsClass(SpaceHelper::class)->getCurrentSpace();
        self::assertTrue($helper->registrationEnabled($space));
        $token = $helper->token($space, 'fallback', 'fr');
        $response = $this->api('login', $session, ['token' => $token, 'email' => $this->email, 'password' => 'MotDePasseLong123']);
        self::assertFalse(json_decode($response->body, true)['is_message']);
    }

    public function testRegistrationRejectsPasswordsBcryptWouldTruncate(): void
    {
        $session = $this->session();
        $token = $this->pages->token($this->app($session), 2);
        foreach ([str_repeat('a', 73), str_repeat('é', 37), "mot-de-passe\0invalide"] as $password) {
            $response = $this->api('register', $session, ['token' => $token, 'acceptedTerms' => true,
                'data' => json_encode(['first_name' => 'Test', 'last_name' => 'Limite', 'email' => $this->email,
                    'password' => $password, 'password_confirmation' => $password])]);
            $data = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
            self::assertTrue($data['is_message']);
            self::assertStringContainsString('72 octets', $data['message']);
        }
    }

    public function testExistingLongPasswordsRemainUsableForLogin(): void
    {
        $password = str_repeat('a', 80);
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $statement = $this->database->connection()->prepare('UPDATE users SET password = ? WHERE id = ?');
        $statement->bind_param('si', $hash, $this->userId);
        $statement->execute();
        $session = $this->session();
        $token = $this->pages->token($this->app($session), 2, 'login');
        $response = $this->api('login', $session, ['token' => $token, 'email' => $this->email, 'password' => $password]);
        self::assertFalse(json_decode($response->body, true, flags: JSON_THROW_ON_ERROR)['is_message']);
        $params = $this->database->connection()->query('SELECT params FROM users WHERE id = ' . $this->userId)->fetch_assoc()['params'];
        self::assertSame(1, json_decode($params, true)['local_password_enabled']);
    }

    public function testTermsAreRequiredForFormAndSsoBeforeAnyIdentityRequest(): void
    {
        $session = $this->session();
        $token = $this->pages->token($this->app($session), 2);
        foreach (['form', 'sso'] as $method) {
            try {
                (new WFAuthentication($this->app($session)))->authorize($token, $method, false);
                self::fail('Les CGU doivent être obligatoires pour ' . $method);
            } catch (ExceptionResponse $exception) {
                self::assertStringContainsString('conditions générales', json_decode($exception->getResponse()->body, true)['message']);
            }
        }
        $authorized = (new WFAuthentication($this->app($session)))->authorize($token, 'sso', true);
        self::assertSame('register', $authorized->kind);
        $before = $this->database->connection()->query('SELECT COUNT(*) AS count FROM users')->fetch_assoc();
        $response = $this->api('register', $session, ['token' => $token, 'acceptedTerms' => '0', 'data' => '{}']);
        self::assertTrue(json_decode($response->body, true)['is_message']);
        self::assertSame($before, $this->database->connection()->query('SELECT COUNT(*) AS count FROM users')->fetch_assoc());
    }

    public static function invalidConfigurations(): array
    {
        return ['inscriptions fermées' => ['closed'], 'page dépubliée' => ['unpublished'],
            'autre Space' => ['space'], 'CGU modifiées' => ['terms'],
            'parent masqué' => ['hidden'], 'composant supprimé' => ['removed'],
            'langue masquée' => ['language'], 'jeton forgé' => ['tampered']];
    }

    #[DataProvider('invalidConfigurations')]
    public function testPublishedConfigurationIsRechecked(string $change): void
    {
        $session = $this->session();
        $token = $this->pages->token($this->app($session), 2);
        $db = $this->database->connection();
        $pageId = $this->pages->pageId(2);
        match ($change) {
            'closed' => $db->query("UPDATE spaces SET params = JSON_SET(params, '$.registration_enabled', false) WHERE id = 2"),
            'unpublished' => $db->query('UPDATE pages SET status = 0 WHERE id = ' . $pageId),
            'space' => $db->query('UPDATE pages SET space_id = 1 WHERE id = ' . $pageId),
            'terms' => $this->changeProps('register', ['terms_text' => 'Nouvelles CGU']),
            'hidden' => $db->query("UPDATE pages SET data = JSON_SET(data, '$.items[0].props.common', JSON_OBJECT('visible', false)) WHERE id = " . $pageId),
            'removed' => $db->query("UPDATE pages SET data = JSON_SET(data, '$.items[0].zones.columns[0].items', JSON_ARRAY()) WHERE id = " . $pageId),
            'language' => $db->query("UPDATE pages SET data = JSON_SET(data, '$.items[0].props.common', JSON_OBJECT('languages', JSON_ARRAY('en'))) WHERE id = " . $pageId),
            'tampered' => $token .= 'forged',
        };
        $this->expectException(ExceptionResponse::class);
        (new WFAuthentication($this->app($session)))->authorize($token, 'sso', true);
    }

    public function testComponentsWorkOutsideTheSpacesChosenDestinations(): void
    {
        $session = $this->session();
        $context = $this->app($session);
        $registerToken = $this->pages->token($context, 2);
        $loginToken = $this->pages->token($context, 2, 'login');
        $loginItemId = $this->pages->itemId(2, 'login');

        // Les liens peuvent être absents ou viser une autre page sans désactiver les composants.
        foreach (['NULL', (string) $loginItemId] as $destination) {
            $this->database->connection()->query('UPDATE spaces SET menu_item_login_id = NULL, menu_item_register_id = '
                . $destination . ' WHERE id = 2');
            $workflow = new WFAuthentication($this->app($session));
            self::assertSame('login', $workflow->authorize($loginToken, 'form', false)->kind);
            self::assertSame('register', $workflow->authorize($registerToken, 'form', true)->kind);
            self::assertSame('register', $workflow->authorize($registerToken, 'sso', true)->kind);
            try {
                $workflow->authorize($registerToken, 'form', false);
                self::fail('Les CGU du composant source restent obligatoires.');
            } catch (ExceptionResponse) {
                self::assertTrue(true);
            }
        }
    }

    public function testDisabledMethodsAndWrongSpaceAreRejected(): void
    {
        $this->changeProps('login', ['form_enabled' => false]);
        $session = $this->session();
        $token = $this->pages->token($this->app($session), 2, 'login');
        $response = $this->api('login', $session, ['token' => $token, 'email' => $this->email, 'password' => 'MotDePasseLong123']);
        self::assertTrue(json_decode($response->body, true)['is_message']);
        $otherSpaceSession = $this->session(1);
        $this->expectException(ExceptionResponse::class);
        (new WFAuthentication($this->app($otherSpaceSession)))->authorize($token, 'sso', true);
    }

    public function testSsoCallbackConsumesStateAndRechecksTermsBeforeCallingProvider(): void
    {
        $session = $this->session();
        $app = $this->app($session);
        $token = $this->pages->token($app, 2);
        $state = bin2hex(random_bytes(32));
        $app->getSession()->set('sso_login_flows', [$state => [
            'space_id' => 2, 'provider_id' => 999999, 'nonce' => 'nonce', 'verifier' => 'verifier',
            'created' => time(), 'authorization' => $token, 'accepted_terms' => true,
        ]]);
        $this->changeProps('register', ['terms_text' => 'CGU modifiées pendant le SSO']);
        $app = $this->app($session);
        try {
            (new WFSso($app))->finishLogin(new Request(query: ['state' => $state, 'code' => 'code-inutilisé']));
            self::fail('La configuration modifiée doit invalider le callback.');
        } catch (ExceptionResponse) {
            self::assertSame([], $app->getSession()->get('sso_login_flows'));
        }
    }

    public function testLoginSsoCannotEnrollAccountEvenWhenRegistrationsAreOpen(): void
    {
        $session = $this->session();
        $app = $this->app($session);
        $space = $app->getOrAddSharedExtensionsClass(SpaceHelper::class)->getCurrentSpace();
        $method = new \ReflectionMethod(WFSso::class, 'ensureEligible');
        $this->expectException(\RuntimeException::class);
        $method->invoke(new WFSso($app), (object) ['id' => $this->userId, 'blocked' => 0], $space, false);
    }

    public function testSpaceNavigationAllowsTechnicallyValidIncoherentChoices(): void
    {
        $session = $this->session(userId: 1);
        $workflow = new WFSpace($this->app($session));
        $navigation = $workflow->getSpaceNavigation(2);
        // L'espace utilisateur est protégé et ne contient pas de formulaire : ce choix reste libre.
        $navigation['login_item_id'] = 50;
        $navigation['register_item_id'] = 50;
        $saved = $workflow->saveSpaceNavigation(2, json_decode(json_encode($navigation)));
        self::assertSame(50, $saved['login_item_id']);
        self::assertSame(50, $saved['register_item_id']);
    }

    public function testSpaceNavigationRejectsForeignMenus(): void
    {
        $session = $this->session(userId: 1);
        $workflow = new WFSpace($this->app($session));
        $navigation = $workflow->getSpaceNavigation(2);
        self::assertSame($this->pages->itemId(2, 'login'), $navigation['login_item_id']);
        self::assertSame($this->pages->itemId(2), $navigation['register_item_id']);
        $navigation['register_item_id'] = 1;
        $this->expectException(ExceptionResponse::class);
        $workflow->saveSpaceNavigation(2, json_decode(json_encode($navigation)));
    }

    private function changeProps(string $kind, array $values): void
    {
        $db = $this->database->connection();
        $id = $this->pages->pageId(2, $kind);
        $document = json_decode($db->query('SELECT data FROM pages WHERE id = ' . $id)->fetch_assoc()['data']);
        $props = $document->items[0]->zones->columns[0]->items[0]->props;
        foreach ($values as $key => $value) $props->{$key} = $value;
        $json = json_encode($document, JSON_THROW_ON_ERROR);
        $statement = $db->prepare('UPDATE pages SET data = ? WHERE id = ?');
        $statement->bind_param('si', $json, $id);
        $statement->execute();
    }

    private function session(int $spaceId = 2, ?int $userId = null): array
    {
        $key = bin2hex(random_bytes(24));
        $csrf = bin2hex(random_bytes(32));
        $data = serialize(['user_id' => $userId, 'current_space_id' => $spaceId, 'csrf_token' => $csrf]);
        $statement = $this->database->connection()->prepare('INSERT INTO sessions (`key`, user_id, data) VALUES (?, ?, ?)');
        $statement->bind_param('sis', $key, $userId, $data);
        $statement->execute();
        return $this->sessions[] = [$key, $csrf];
    }

    private function app(array $session, string $uri = '/'): Application
    {
        $app = new Application($this->environment->configuration());
        $app->initialiseOrThrow(new Request(uri: $uri, host: TestFixtures::SITE_HOST, cookies: ['session_key' => $session[0]]));
        return $app;
    }

    private function api(string $action, array $session, array $body, bool $csrf = true): \YTassy\Tycms\Response\Response
    {
        $app = new Application($this->environment->configuration());
        $app->initialiseOrThrow(new Request(uri: '/auth/api/' . $action, method: RequestMethod::POST,
            body: $body, headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $csrf ? $session[1] : ''],
            host: TestFixtures::SITE_HOST, cookies: ['session_key' => $session[0]]));
        return $app->executeAndGetResponse();
    }
}
