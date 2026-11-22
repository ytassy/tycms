<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Core\Helper\Space\SpaceSwitchHelper;
use YTassy\Tycms\CoreExts\Core\PageComponents\SpaceSwitch;
use YTassy\Tycms\Request\Request;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Tests\Support\Integration\TestDatabase;
use YTassy\Tycms\Tests\Support\Integration\TestEnvironment;
use YTassy\Tycms\Tests\Support\Integration\TestFixtures;

final class SpaceSwitchIntegrationTest extends TestCase
{
    private TestEnvironment $environment;
    private TestDatabase $database;
    private \YTassy\Tycms\Tests\Support\Integration\AuthenticationPages $authPages;
    private array $original = [];
    private array $sessions = [];
    private array $files = [];

    protected function setUp(): void
    {
        $this->environment = TestEnvironment::load();
        $this->database = new TestDatabase($this->environment);
        TestFixtures::assertPrepared($this->database);
        $this->authPages = new \YTassy\Tycms\Tests\Support\Integration\AuthenticationPages($this->database);
        $this->authPages->install(2);
        $this->original = $this->database->connection()->query('SELECT id, params, hosts FROM spaces WHERE id IN (1, 2)')
            ->fetch_all(MYSQLI_ASSOC);
    }

    protected function tearDown(): void
    {
        $db = $this->database->connection();
        $this->authPages->restore();
        foreach ($this->original as $space) {
            $statement = $db->prepare('UPDATE spaces SET params = ?, hosts = ? WHERE id = ?');
            $statement->bind_param('ssi', $space['params'], $space['hosts'], $space['id']);
            $statement->execute();
        }
        foreach ($this->sessions as $key) {
            $statement = $db->prepare('DELETE FROM sessions WHERE `key` = ?');
            $statement->bind_param('s', $key);
            $statement->execute();
        }
        foreach ($this->files as $file) if (is_file($file)) unlink($file);
    }

    #[Test]
    public function sourcesAreCombinedWithoutDuplicatesAndSecretsAreExcluded(): void
    {
        $session = $this->session(1);
        $this->configure(2, ['short_description' => 'Présentation',
            'description' => '<p>Texte <strong>riche</strong><script>alert(1)</script></p>',
            'mail_smtp_pass' => 'secret-ne-jamais-exposer']);
        $token = $this->token(['from_list' => true, 'space_ids' => [1, 2], 'show_description' => true], $session);
        $data = $this->api('options', $session, ['token' => $token]);
        self::assertFalse($data['error']);
        $ids = array_column($data['spaces'], 'id');
        sort($ids);
        self::assertSame([1, 2], $ids);
        $site = array_column($data['spaces'], null, 'id')[2];
        self::assertTrue($site['member']);
        self::assertSame('Présentation', $site['short_description']);
        self::assertStringContainsString('<strong>riche</strong>', $site['description']);
        self::assertStringNotContainsString('<script', $site['description']);
        self::assertStringNotContainsString('secret-ne-jamais-exposer', json_encode($data));
        self::assertArrayNotHasKey('params', $site);
    }

    #[Test]
    public function closedSpacesCanBeHiddenAndCannotBeSelectedWithoutMembership(): void
    {
        $this->configure(2, ['registration_enabled' => false]);
        $session = $this->session(null);
        $token = $this->token(['from_member' => false], $session);
        $data = $this->api('options', $session, ['token' => $token]);
        self::assertCount(1, $data['spaces']);
        self::assertNull($data['spaces'][0]['url']);
        // L’ouverture affichée n’est jamais une autorisation durable pour le POST suivant.
        $this->configure(2, ['registration_enabled' => true]);
        self::assertSame($this->registrationUrl(), $this->api('options', $session, ['token' => $token])['spaces'][0]['url']);
        $this->configure(2, ['registration_enabled' => false]);
        self::assertTrue($this->api('select', $session, ['token' => $token, 'spaceId' => 2])['is_message']);
        self::assertSame(1, $this->currentSpace($session));

        $hidden = $this->token(['from_member' => false, 'hide_closed' => true], $session);
        self::assertSame([], $this->api('options', $session, ['token' => $hidden])['spaces']);
    }

    #[Test]
    public function registrationDestinationIsRequiredAndRecheckedBeforeSwitching(): void
    {
        $this->configure(2, ['registration_enabled' => true]);
        $session = $this->session(null);
        $token = $this->token(['from_member' => false], $session);
        self::assertSame($this->registrationUrl(), $this->api('options', $session, ['token' => $token])['spaces'][0]['url']);

        // Un lien retiré après l'affichage bloque le POST sans changer le Space de session.
        $db = $this->database->connection();
        $db->query('UPDATE spaces SET menu_item_register_id = NULL WHERE id = 2');
        self::assertTrue($this->api('select', $session, ['token' => $token, 'spaceId' => 2])['is_message']);
        self::assertSame(1, $this->currentSpace($session));

        $spaces = $this->api('options', $session, ['token' => $token])['spaces'];
        self::assertCount(1, $spaces);
        self::assertFalse($spaces[0]['registration_enabled']);
        self::assertNull($spaces[0]['url']);
        $hidden = $this->token(['from_member' => false, 'hide_closed' => true], $session);
        self::assertSame([], $this->api('options', $session, ['token' => $hidden])['spaces']);

        // Une entrée encore référencée mais dépubliée est également inutilisable.
        $itemId = $this->authPages->itemId(2);
        $db->query('UPDATE spaces SET menu_item_register_id = ' . $itemId . ' WHERE id = 2');
        $db->query('UPDATE menu_items SET status = 0 WHERE id = ' . $itemId);
        self::assertTrue($this->api('select', $session, ['token' => $token, 'spaceId' => 2])['is_message']);
        self::assertSame(1, $this->currentSpace($session));
        self::assertSame([], $this->api('options', $session, ['token' => $hidden])['spaces']);

        // Les membres conservent leur accès même sans destination d'inscription utilisable.
        $member = $this->session(1);
        $selected = $this->api('select', $member, ['token' => $this->token([], $member), 'spaceId' => 2]);
        self::assertFalse($selected['registration_required']);
        self::assertSame(2, $this->currentSpace($member));
    }

    #[Test]
    public function inheritedMembersCanEnterClosedSpacesAndChooseTheirDestination(): void
    {
        $this->configure(2, ['registration_enabled' => false]);
        $session = $this->session(1);
        $home = $this->api('select', $session, ['token' => $this->token(['destination' => 'home'], $session), 'spaceId' => 2]);
        self::assertFalse($home['error']);
        self::assertFalse($home['registration_required']);
        self::assertSame('/fr/', $home['url']);
        self::assertSame(2, $this->currentSpace($session));

        $user = $this->api('select', $session, ['token' => $this->token(['destination' => 'user'], $session), 'spaceId' => 2]);
        self::assertSame('/fr/mon-espace', $user['url']);
    }

    #[Test]
    public function nonMembersAreSentToRegisterAfterSelectingWithoutEnrollment(): void
    {
        $this->configure(2, ['registration_enabled' => true]);
        $session = $this->session(null);
        $token = $this->token(['from_member' => false], $session);
        self::assertSame($this->registrationUrl(), $this->api('options', $session, ['token' => $token])['spaces'][0]['url']);
        self::assertSame(1, $this->currentSpace($session));
        $db = $this->database->connection();
        $before = $db->query('SELECT COUNT(*) AS total FROM users')->fetch_assoc();
        $selected = $this->api('select', $session, ['token' => $token, 'spaceId' => 2]);
        self::assertTrue($selected['registration_required']);
        self::assertSame($this->registrationUrl(), $selected['url']);
        self::assertSame(2, $this->currentSpace($session));
        self::assertSame($before, $db->query('SELECT COUNT(*) AS total FROM users')->fetch_assoc());
        $response = $this->application(new Request(uri: $selected['url'], host: TestFixtures::SITE_HOST,
            cookies: ['session_key' => $session[0]]))->executeAndGetResponse();
        self::assertSame(200, $response->code);
        self::assertStringContainsString('core/authentication', $response->body);
    }

    #[Test]
    public function csrfForgedConfigurationAndUnlistedSpacesAreRejected(): void
    {
        $session = $this->session(1);
        $token = $this->token(['from_domain' => false, 'from_member' => false, 'from_list' => true, 'space_ids' => [2]], $session);
        self::assertTrue($this->api('select', $session, ['token' => $token, 'spaceId' => 2], false)['error']);
        self::assertTrue($this->api('options', $session, ['token' => $token . 'tampered'])['is_message']);
        self::assertTrue($this->api('select', $session, ['token' => $token, 'spaceId' => 1])['is_message']);
        self::assertSame(1, $this->currentSpace($session));
    }

    #[Test]
    public function onlyTheRootSpaceMayRemainWithoutAParent(): void
    {
        $session = $this->session(1, 2);
        $app = $this->application(new Request(uri: '/register', host: TestFixtures::SITE_HOST,
            cookies: ['session_key' => $session[0]]));
        $workflow = new \YTassy\Tycms\CoreExts\Core\WF\WFSpace($app);

        // Un Space ordinaire ne peut pas retomber sans parent, même par l'API.
        $values = $workflow->getSpaceEditorForm(2)['form']['initialValues'];
        $values['parent_id'] = 0;
        try {
            $workflow->updateSpace(2, (object) $values);
            self::fail('Un Space autre que la racine doit conserver un parent.');
        } catch (\YTassy\Tycms\Response\ExceptionResponse) {
        }

        // La racine ne peut pas recevoir de parent.
        $rootValues = $workflow->getSpaceEditorForm(1)['form']['initialValues'];
        $rootValues['parent_id'] = 2;
        try {
            $workflow->updateSpace(1, (object) $rootValues);
            self::fail('Le Space racine ne peut pas avoir de parent.');
        } catch (\YTassy\Tycms\Response\ExceptionResponse) {
        }
    }

    #[Test]
    public function spaceFormStoresPurifiedHtmlAndChecksLogoOwnership(): void
    {
        $session = $this->session(1, 2);
        $app = $this->application(new Request(uri: '/register', host: TestFixtures::SITE_HOST,
            cookies: ['session_key' => $session[0]]));
        $workflow = new \YTassy\Tycms\CoreExts\Core\WF\WFSpace($app);
        $definition = $workflow->getSpaceEditorForm(2)['form'];
        $fields = array_merge(...array_column($definition['sections'], 'fields'));
        $fields = array_column($fields, null, 'name');
        self::assertSame('core.rich_text', $fields['description']['type']);
        self::assertSame('image', $fields['logo']['mediaType']);
        $values = $definition['initialValues'];
        $space = $app->getOrAddSharedExtensionsClass(\YTassy\Tycms\CoreExts\Core\Helper\Space\SpaceHelper::class)->getCurrentSpace();
        $file = $space->getMediasDir() . '/switch-logo.png';
        file_put_contents($file, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aD1cAAAAASUVORK5CYII='));
        $this->files[] = $file;
        $values['logo'] = '/medias/' . rawurlencode($space->medias_dir) . '/switch-logo.png';
        $values['description'] = '<p>Bonjour <strong>monde</strong><script>alert(1)</script><img src=x onerror="alert(1)"></p>';
        $updated = $workflow->updateSpace(2, (object) $values);
        self::assertSame($values['logo'], $updated->params->logo);
        self::assertStringContainsString('<strong>monde</strong>', $updated->params->description);
        self::assertStringNotContainsString('<script', $updated->params->description);
        self::assertStringNotContainsString('onerror', $updated->params->description);

        $values['logo'] = '/medias/root-fixture/switch-logo.png';
        $this->expectException(\YTassy\Tycms\Response\ExceptionResponse::class);
        $workflow->updateSpace(2, (object) $values);
    }

    #[Test]
    public function spaceMediaDirectoryIsRenamedAndExistingReferencesArePreserved(): void
    {
        $session = $this->session(1, 2);
        $app = $this->application(new Request(uri: '/register', host: TestFixtures::SITE_HOST,
            cookies: ['session_key' => $session[0]]));
        $workflow = new \YTassy\Tycms\CoreExts\Core\WF\WFSpace($app);
        $values = $workflow->getSpaceEditorForm(2)['form']['initialValues'];
        $oldName = $values['medias_dir'];
        $newName = 'test-rename-' . bin2hex(random_bytes(6));
        $oldPath = $app->getMediasRoot() . '/' . $oldName;
        $newPath = $app->getMediasRoot() . '/' . $newName;
        $filename = 'rename-' . bin2hex(random_bytes(6)) . '.txt';
        file_put_contents($oldPath . '/' . $filename, 'media');

        try {
            $values['medias_dir'] = '../outside';
            try {
                $workflow->updateSpace(2, (object) $values);
                self::fail('Un chemin invalide doit être refusé.');
            } catch (\YTassy\Tycms\Response\ExceptionResponse) {
                self::assertFileExists($oldPath . '/' . $filename);
            }

            mkdir($newPath);
            $values['medias_dir'] = $newName;
            try {
                $workflow->updateSpace(2, (object) $values);
                self::fail('Une destination existante doit être refusée.');
            } catch (\YTassy\Tycms\Response\ExceptionResponse) {
                self::assertFileExists($oldPath . '/' . $filename);
            }
            rmdir($newPath);

            $updated = $workflow->updateSpace(2, (object) $values);
            self::assertSame($newName, $updated->medias_dir);
            self::assertFileExists($newPath . '/' . $filename);
            self::assertSame($values['logo'], $updated->params->logo);
        } finally {
            if (is_file($newPath . '/' . $filename)) unlink($newPath . '/' . $filename);
            if (is_file($oldPath . '/' . $filename)) unlink($oldPath . '/' . $filename);
            if (is_dir($newPath) && !is_dir($oldPath)) rename($newPath, $oldPath);
            elseif (is_dir($newPath)) rmdir($newPath);
            $statement = $this->database->connection()->prepare('UPDATE spaces SET medias_dir = ? WHERE id = 2');
            $statement->bind_param('s', $oldName);
            $statement->execute();
        }
    }

    private function registrationUrl(): string
    {
        $app = $this->application(new Request(uri: '/', host: TestFixtures::SITE_HOST));
        return $this->authPages->url($app, 2);
    }

    private function configure(int $id, array $values): void
    {
        $db = $this->database->connection();
        $params = json_decode($db->query('SELECT params FROM spaces WHERE id = ' . $id)->fetch_assoc()['params'], true);
        $json = json_encode(array_replace($params, $values), JSON_THROW_ON_ERROR);
        $statement = $db->prepare('UPDATE spaces SET params = ? WHERE id = ?');
        $statement->bind_param('si', $json, $id);
        $statement->execute();
    }

    private function session(?int $userId, int $spaceId = 1): array
    {
        $key = bin2hex(random_bytes(24));
        $csrf = bin2hex(random_bytes(32));
        $data = serialize(['user_id' => $userId, 'current_space_id' => $spaceId, 'csrf_token' => $csrf]);
        $statement = $this->database->connection()->prepare('INSERT INTO sessions (`key`, user_id, data) VALUES (?, ?, ?)');
        $statement->bind_param('sis', $key, $userId, $data);
        $statement->execute();
        $this->sessions[] = $key;
        return [$key, $csrf];
    }

    private function token(array $overrides, array $session): string
    {
        $app = $this->application(new Request(uri: '/register', host: TestFixtures::SITE_HOST,
            cookies: ['session_key' => $session[0]]));
        return $app->getOrAddSharedExtensionsClass(SpaceSwitchHelper::class)->configurationToken(
            (object) array_replace(SpaceSwitch::getDefaultData(), $overrides));
    }

    private function api(string $action, array $session, array $body, bool $csrf = true): array
    {
        $app = $this->application(new Request(method: RequestMethod::POST, uri: '/space/api/switch/' . $action,
            body: $body, headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $csrf ? $session[1] : ''],
            cookies: ['session_key' => $session[0]], host: TestFixtures::SITE_HOST));
        return json_decode($app->executeAndGetResponse()->body, true, flags: JSON_THROW_ON_ERROR);
    }

    private function application(Request $request): Application
    {
        $app = new Application($this->environment->configuration());
        $app->initialiseOrThrow($request);
        return $app;
    }

    private function currentSpace(array $session): int
    {
        $statement = $this->database->connection()->prepare('SELECT data FROM sessions WHERE `key` = ?');
        $statement->bind_param('s', $session[0]);
        $statement->execute();
        return unserialize($statement->get_result()->fetch_assoc()['data'], ['allowed_classes' => false])['current_space_id'];
    }
}
