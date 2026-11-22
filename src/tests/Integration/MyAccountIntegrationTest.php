<?php

namespace YTassy\Tycms\Tests\Integration;

use PHPUnit\Framework\TestCase;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Core\WF\WFMyAccount;
use YTassy\Tycms\CoreExts\Core\Helper\Crypt\CryptHelper;
use YTassy\Tycms\Request\{Request, RequestMethod};
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Tests\Support\Integration\{TestDatabase, TestEnvironment, TestFixtures};

final class MyAccountIntegrationTest extends TestCase
{
    private TestEnvironment $environment;
    private TestDatabase $database;
    private array $users = [];
    private array $sessions = [];
    private array $providers = [];
    private string $siteParams;

    protected function setUp(): void
    {
        $this->environment = TestEnvironment::load();
        $this->database = new TestDatabase($this->environment);
        TestFixtures::assertPrepared($this->database);
        $this->siteParams = $this->database->connection()->query('SELECT params FROM spaces WHERE id = 2')->fetch_assoc()['params'];
        foreach ([1, 2] as $number) {
            $email = 'account-' . bin2hex(random_bytes(6)) . '@integration.test';
            $password = password_hash('AncienMotDePasse123', PASSWORD_DEFAULT);
            $statement = $this->database->connection()->prepare(
                "INSERT INTO users (space_id, first_name, last_name, email, password, params) VALUES (1, 'Camille', 'Martin', ?, ?, '{}')",
            );
            $statement->bind_param('ss', $email, $password);
            $statement->execute();
            $id = (int) $this->database->connection()->insert_id;
            $this->users[] = $id;
            $this->database->connection()->query("INSERT INTO user_groups_map (user_id, group_id) VALUES ($id, 2)");
        }
    }

    protected function tearDown(): void
    {
        $db = $this->database->connection();
        foreach ($this->users as $id) $db->query('DELETE FROM users WHERE id = ' . $id);
        foreach ($this->providers as $id) $db->query('DELETE FROM sso_providers WHERE id = ' . $id);
        $statement = $db->prepare('UPDATE spaces SET params = ? WHERE id = 2');
        $statement->bind_param('s', $this->siteParams);
        $statement->execute();
        foreach ($this->sessions as $key) {
            $statement = $db->prepare('DELETE FROM sessions WHERE `key` = ?');
            $statement->bind_param('s', $key);
            $statement->execute();
        }
    }

    public function testAnonymousAndBlockedAccountsAreDeniedAndSecretsAreNeverReturned(): void
    {
        self::assertTrue($this->api('', $this->session(null))['is_message']);
        $session = $this->session($this->users[0]);
        $profile = $this->api('', $session);
        self::assertSame($this->user()['email'], $profile['email']);
        foreach (['password', 'params', 'reset_password_token', 'id', 'space_id'] as $secret) {
            self::assertArrayNotHasKey($secret, $profile);
        }
        $response = $this->application($session)->executeAndGetResponse();
        self::assertSame('private, no-store', $response->httpHeaders['Cache-Control']);
        $this->database->connection()->query('UPDATE users SET blocked = 1 WHERE id = ' . $this->users[0]);
        self::assertTrue($this->api('', $session)['is_message']);
    }

    public function testProfileUpdatesArePersonalPartialValidatedAndProtectedByCsrf(): void
    {
        $session = $this->session($this->users[0]);
        $original = $this->user();
        $this->api('save', $session, ['data' => ['first_name' => 'Intrus']], false);
        self::assertSame($original['first_name'], $this->user()['first_name']);
        foreach ([['id' => $this->users[1]], ['blocked' => false], ['space_id' => 2],
            ['timezone' => 'Mars/Olympus'], ['email' => 'invalide'], ['first_name' => []]] as $data) {
            self::assertTrue($this->api('save', $session, ['data' => $data])['is_message']);
        }
        $other = $this->user($this->users[1]);
        self::assertTrue($this->api('save', $session, ['data' => ['email' => $other['email']]])['is_message']);
        $this->database->connection()->query("UPDATE users SET reset_password_token = 'ancien-lien' WHERE id = " . $this->users[0]);
        $result = $this->api('save', $session, ['data' => ['email' => '  NOUVEAU-' . $this->users[0] . '@integration.test  ', 'timezone' => 'UTC']]);
        self::assertFalse($result['is_message']);
        self::assertSame('nouveau-' . $this->users[0] . '@integration.test', $this->user()['email']);
        self::assertSame('', $this->user()['reset_password_token']);
        self::assertSame('UTC', $this->user()['timezone']);
        self::assertSame($original['first_name'], $this->user()['first_name']);
        self::assertSame($other, $this->user($this->users[1]));
        $response = $this->application($session, 'save', ['data' => ['timezone' => 'UTC']])->executeAndGetResponse();
        self::assertSame('private, no-store', $response->httpHeaders['Cache-Control']);

        // Les anciens profils SSO peuvent ne pas avoir de nom : une mise à jour partielle reste possible.
        $this->database->connection()->query("UPDATE users SET first_name = '', last_name = '' WHERE id = " . $this->users[0]);
        self::assertFalse($this->api('save', $session, ['data' => ['timezone' => 'Europe/Paris']])['is_message']);
    }

    public function testPasswordRequiresTheOldSecretAndRevokesOnlyTheOwnersSessions(): void
    {
        $session = $this->session($this->users[0]);
        $this->session($this->users[0]);
        $this->session($this->users[1]);
        $data = ['current' => 'faux', 'password' => 'NouveauMotDePasse123', 'confirmation' => 'NouveauMotDePasse123'];
        self::assertTrue($this->api('password', $session, $data)['is_message']);
        self::assertTrue(password_verify('AncienMotDePasse123', $this->user()['password']));
        $data['current'] = 'AncienMotDePasse123';
        self::assertFalse($this->api('password', $session, $data)['is_message']);
        self::assertTrue(password_verify('NouveauMotDePasse123', $this->user()['password']));
        $db = $this->database->connection();
        self::assertSame(0, (int) $db->query('SELECT COUNT(*) n FROM sessions WHERE user_id = ' . $this->users[0])->fetch_assoc()['n']);
        self::assertSame(1, (int) $db->query('SELECT COUNT(*) n FROM sessions WHERE user_id = ' . $this->users[1])->fetch_assoc()['n']);
    }

    public function testPasswordChangesRejectSilentBcryptTruncationAndNullBytes(): void
    {
        $session = $this->session($this->users[0]);
        $original = $this->user()['password'];
        foreach ([str_repeat('a', 73), str_repeat('é', 37), "mot-de-passe\0invalide"] as $password) {
            $result = $this->api('password', $session, ['current' => 'AncienMotDePasse123',
                'password' => $password, 'confirmation' => $password]);
            self::assertTrue($result['is_message']);
            self::assertStringContainsString('72 octets', $result['message']);
            self::assertSame($original, $this->user()['password']);
        }
        $password = str_repeat('é', 36);
        self::assertFalse($this->api('password', $session, ['current' => 'AncienMotDePasse123',
            'password' => $password, 'confirmation' => $password])['is_message']);
        self::assertTrue(password_verify($password, $this->user()['password']));
    }

    public function testSsoLinksCannotBeStolenOrRemoveTheLastUnknownLogin(): void
    {
        $provider = $this->provider();
        $db = $this->database->connection();
        $first = $this->users[0];
        $second = $this->users[1];
        $db->query("INSERT INTO sso_links (user_id, provider_id, external_subject, data, data_key_version) VALUES ($first, $provider, 'first', '', 1)");
        $ownLink = (int) $db->insert_id;
        $db->query("INSERT INTO sso_links (user_id, provider_id, external_subject, data, data_key_version) VALUES ($second, $provider, 'second', '', 1)");
        $otherLink = (int) $db->insert_id;
        $session = $this->session($first);
        self::assertTrue($this->api('sso/unlink', $session, ['linkId' => $otherLink])['is_message']);
        self::assertTrue($this->api('sso/unlink', $session, ['linkId' => $ownLink])['is_message']);

        // Un compte historique lié au SSO ne devient local qu'après preuve de son mot de passe.
        $db->query("UPDATE users SET params = '{\"local_password_enabled\":1}' WHERE id = $first");
        self::assertFalse($this->api('sso/unlink', $session, ['linkId' => $ownLink])['is_message']);
        self::assertSame(1, (int) $db->query("SELECT COUNT(*) n FROM sso_links WHERE id = $otherLink")->fetch_assoc()['n']);

        $app = $this->application($session);
        $wf = new WFMyAccount($app);

        // Un fournisseur désactivé ne doit pas être accepté après le retour OIDC.
        try {
            $wf->linkIdentity($first, $provider, 'new-identity', ['claims' => ['sub' => 'new-identity']]);
            self::fail('Un fournisseur indisponible a été accepté.');
        } catch (ExceptionResponse) {
            self::assertSame(0, (int) $db->query("SELECT COUNT(*) n FROM sso_links WHERE user_id = $first")->fetch_assoc()['n']);
        }

        $this->enableLoginProvider($provider);
        $wf->linkIdentity($first, $provider, 'new-identity', ['claims' => ['sub' => 'new-identity']]);
        self::assertTrue(password_verify('AncienMotDePasse123', $this->user()['password']));
        try {
            $wf->linkIdentity($first, $provider, 'second', ['claims' => ['sub' => 'second']]);
            self::fail('Une identité appartenant à un autre compte a été acceptée.');
        } catch (ExceptionResponse $error) {
            self::assertStringContainsString('autre utilisateur', $error->getResponse()->body);
        }

        // Le catalogue en mémoire ne doit pas masquer une sélection révoquée pendant l'échange OIDC.
        $db->query("UPDATE spaces SET params = JSON_SET(params, '$.sso_provider_ids', JSON_ARRAY()) WHERE id = 2");
        try {
            $wf->linkIdentity($first, $provider, 'revoked-identity', ['claims' => ['sub' => 'revoked-identity']]);
            self::fail('Un fournisseur retiré du Space a été accepté.');
        } catch (ExceptionResponse $error) {
            self::assertStringContainsString('indisponible', $error->getResponse()->body);
        }
    }

    public function testIntegrationDisconnectCannotDeleteAnotherUsersGrant(): void
    {
        $provider = $this->provider();
        $db = $this->database->connection();
        foreach ($this->users as $id) {
            $db->query("INSERT INTO oauth_grants (user_id, provider_id, token_data, token_key_version) VALUES ($id, $provider, 'secret', 1)");
        }
        self::assertFalse($this->api('integrations/disconnect', $this->session($this->users[0]), ['providerId' => $provider])['is_message']);
        self::assertSame(0, (int) $db->query('SELECT COUNT(*) n FROM oauth_grants WHERE user_id = ' . $this->users[0])->fetch_assoc()['n']);
        self::assertSame(1, (int) $db->query('SELECT COUNT(*) n FROM oauth_grants WHERE user_id = ' . $this->users[1])->fetch_assoc()['n']);
    }

    public function testThePrincipalSpacesSsoPasswordPolicyCannotBeBypassedFromAnotherSpace(): void
    {
        $db = $this->database->connection();
        $oldParams = $db->query('SELECT params FROM spaces WHERE id = 1')->fetch_assoc()['params'];
        $provider = $this->provider();
        $user = $this->users[0];
        $db->query("INSERT INTO sso_links (user_id, provider_id, external_subject, data, data_key_version) VALUES ($user, $provider, 'policy', '', 1)");
        $session = $this->session($user);
        $data = ['current' => 'AncienMotDePasse123', 'password' => 'NouveauMotDePasse123', 'confirmation' => 'NouveauMotDePasse123'];
        try {
            $db->query("UPDATE spaces SET params = JSON_SET(params, '$.sso_allow_reset_local_password', 0) WHERE id = 1");
            self::assertFalse($this->api('', $session)['password_change_allowed']);
            self::assertTrue($this->api('password', $session, $data)['is_message']);
            self::assertTrue(password_verify('AncienMotDePasse123', $this->user()['password']));
            $db->query("UPDATE spaces SET params = JSON_SET(params, '$.sso_allow_reset_local_password', 1) WHERE id = 1");
            self::assertTrue($this->api('', $session)['password_change_allowed']);
            self::assertFalse($this->api('password', $session, $data)['is_message']);
        } finally {
            $statement = $db->prepare('UPDATE spaces SET params = ? WHERE id = 1');
            $statement->bind_param('s', $oldParams);
            $statement->execute();
        }
    }

    private function provider(): int
    {
        $this->database->connection()->query("INSERT INTO sso_providers (space_id, name, protocol, config, mapping, login_button_text, register_button_text) VALUES (1, 'Compte test', 'oidc', '{}', '{}', '', '')");
        $id = (int) $this->database->connection()->insert_id;
        $this->providers[] = $id;
        return $id;
    }

    private function enableLoginProvider(int $providerId): void
    {
        $config = json_encode(['driver' => 'google', 'client_id' => 'integration-only-client',
            'redirect_uri' => 'https://' . TestFixtures::SITE_HOST . '/sso/callback']);
        $crypto = new CryptHelper(new Application($this->environment->configuration()));
        $secret = $crypto->encrypt('integration-only-provider-secret', 'provider:v1');
        $db = $this->database->connection();
        $statement = $db->prepare("UPDATE sso_providers SET enabled = 1, login_enabled = 1, config = ?, secret_data = ?, mapping = '{\"subject\":\"claims.sub\"}' WHERE id = ?");
        $statement->bind_param('ssi', $config, $secret, $providerId);
        $statement->execute();
        $db->query("UPDATE spaces SET params = JSON_SET(params, '$.sso_provider_ids', JSON_ARRAY($providerId)) WHERE id = 2");
    }

    private function user(?int $id = null): array
    {
        return $this->database->connection()->query('SELECT * FROM users WHERE id = ' . ($id ?? $this->users[0]))->fetch_assoc();
    }

    private function session(?int $userId): array
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

    private function application(array $session, string $path = '', array $values = [], bool $csrf = true): Application
    {
        if (isset($values['data'])) $values['data'] = json_encode($values['data'], JSON_THROW_ON_ERROR);
        $request = new Request(
            method: $path === '' ? RequestMethod::GET : RequestMethod::POST,
            uri: '/account/api' . ($path === '' ? '' : '/' . $path), body: $values,
            headers: $csrf ? ['X-CSRF-Token' => $session[1]] : [],
            cookies: ['session_key' => $session[0]], host: TestFixtures::SITE_HOST,
        );
        $app = new Application($this->environment->configuration());
        $app->initialiseOrThrow($request);
        return $app;
    }

    private function api(string $path, array $session, array $values = [], bool $csrf = true): array
    {
        return json_decode($this->application($session, $path, $values, $csrf)->executeAndGetResponse()->body, true, flags: JSON_THROW_ON_ERROR);
    }
}
