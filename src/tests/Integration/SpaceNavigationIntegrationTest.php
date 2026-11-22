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

final class SpaceNavigationIntegrationTest extends TestCase
{
    private TestEnvironment $environment;
    private TestDatabase $database;
    private string $sessionKey;
    private string $csrfToken;

    protected function setUp(): void
    {
        $this->environment = TestEnvironment::load();
        $this->database = new TestDatabase($this->environment);
        TestFixtures::assertPrepared($this->database);

        $connection = $this->database->connection();
        $connection->query('UPDATE spaces SET menu_item_home_id = 10, menu_item_user_space_id = 50 WHERE id = 2');
        $connection->query('UPDATE space_languages SET menu_item_home_id = NULL, menu_item_user_space_id = NULL WHERE space_id = 2');
        $connection->query('UPDATE menu_items SET status = 1 WHERE id IN (10, 50, 502, 503)');
        $connection->query('DELETE FROM menu_item_languages WHERE menu_item_id IN (10, 20, 50, 502, 503)');

        $this->sessionKey = bin2hex(random_bytes(24));
        $this->csrfToken = bin2hex(random_bytes(32));
        $data = serialize([
            'user_id' => 1,
            'current_space_id' => TestFixtures::SITE_SPACE_ID,
            'csrf_token' => $this->csrfToken,
        ]);
        $statement = $connection->prepare('INSERT INTO sessions (`key`, `user_id`, `data`) VALUES (?, 1, ?)');
        $statement->bind_param('ss', $this->sessionKey, $data);
        $statement->execute();
    }

    protected function tearDown(): void
    {
        $connection = $this->database->connection();
        $connection->query('UPDATE space_languages SET menu_item_home_id = NULL, menu_item_user_space_id = NULL WHERE space_id = 2');
        $connection->query('UPDATE spaces SET menu_item_home_id = 10, menu_item_user_space_id = 50 WHERE id = 2');
        $connection->query('UPDATE menu_items SET status = 1 WHERE id IN (10, 50, 502, 503)');
        $connection->query('DELETE FROM menu_item_languages WHERE menu_item_id IN (10, 20, 50, 502, 503)');
        $statement = $connection->prepare('DELETE FROM sessions WHERE `key` = ?');
        $statement->bind_param('s', $this->sessionKey);
        $statement->execute();
    }

    #[Test]
    public function legacyHomeIsUsedUntilALanguageChoosesAnotherItem(): void
    {
        $navigation = $this->api('spaces/navigation', RequestMethod::GET, ['spaceId' => '2']);
        self::assertSame(10, $navigation['home_item_id']);
        self::assertSame(50, $navigation['user_space_item_id']);
        self::assertSame(['fr', 'en'], array_column($navigation['languages'], 'locale'));
        self::assertSame([0, 0], array_column($navigation['languages'], 'home_item_id'));
        self::assertSame([0, 0], array_column($navigation['languages'], 'user_space_item_id'));

        self::assertSame(10, $this->resolvedHome('/fr/'));
        self::assertSame(10, $this->resolvedHome('/en/'));

        $saved = $this->saveNavigation(10, 50, 0, 502);
        self::assertFalse($saved['error']);
        self::assertSame([0, 502], array_column($saved['languages'], 'home_item_id'));
        self::assertSame(10, $this->resolvedHome('/fr/'));
        self::assertSame(502, $this->resolvedHome('/en/'));
        self::assertSame(200, $this->application('/en/')->executeAndGetResponse()->code);
    }

    #[Test]
    public function authenticationLinksCanBeSavedThroughTheHttpApi(): void
    {
        $connection = $this->database->connection();
        $original = $connection->query('SELECT menu_item_login_id, menu_item_register_id FROM spaces WHERE id = 2')->fetch_assoc();
        $navigation = [
            'home_item_id' => 10, 'user_space_item_id' => 50,
            'login_item_id' => 50, 'register_item_id' => 502,
            'languages' => [
                ['locale' => 'fr', 'home_item_id' => 0, 'user_space_item_id' => 0],
                ['locale' => 'en', 'home_item_id' => 0, 'user_space_item_id' => 0],
            ],
        ];
        $save = fn (array $values): array => $this->api('spaces/navigation/save', RequestMethod::POST, [], [
            'spaceId' => '2', 'navigation' => json_encode($values, JSON_THROW_ON_ERROR),
        ]);

        try {
            // Exercer le schéma HTTP, puis vérifier l'écriture réelle des deux nouveaux champs.
            $saved = $save($navigation);
            self::assertFalse($saved['error']);
            self::assertFalse($saved['is_message'] ?? false, json_encode($saved));
            self::assertSame(50, $saved['login_item_id']);
            self::assertSame(502, $saved['register_item_id']);
            $stored = $connection->query('SELECT menu_item_login_id, menu_item_register_id FROM spaces WHERE id = 2')->fetch_assoc();
            self::assertSame(50, (int) $stored['menu_item_login_id']);
            self::assertSame(502, (int) $stored['menu_item_register_id']);

            // Les clients qui omettent ces options conservent les choix déjà enregistrés.
            $unchanged = $this->saveNavigation(10, 50, 0, 0);
            self::assertSame(50, $unchanged['login_item_id']);
            self::assertSame(502, $unchanged['register_item_id']);

            // Garder le refus des formats invalides, des autres Spaces et des propriétés inconnues.
            foreach ([['register_item_id' => -1], ['register_item_id' => 1], ['unexpected' => true]] as $overrides) {
                $this->assertRejected($save(array_replace($navigation, $overrides)));
            }
            $read = $this->api('spaces/navigation', RequestMethod::GET, ['spaceId' => '2']);
            self::assertSame(50, $read['login_item_id']);
            self::assertSame(502, $read['register_item_id']);

            $cleared = $save(array_replace($navigation, ['login_item_id' => 0, 'register_item_id' => 0]));
            self::assertFalse($cleared['is_message'] ?? false);
            self::assertSame(0, $cleared['login_item_id']);
            self::assertSame(0, $cleared['register_item_id']);
        } finally {
            $statement = $connection->prepare('UPDATE spaces SET menu_item_login_id = ?, menu_item_register_id = ? WHERE id = 2');
            $statement->bind_param('ii', $original['menu_item_login_id'], $original['menu_item_register_id']);
            $statement->execute();
        }
    }

    #[Test]
    public function originalHomeCanBeHiddenOnlyAfterAnotherEnglishHomeIsChosen(): void
    {
        $blocked = $this->saveItemLanguages(10, true, false);
        $this->assertRejected($blocked);

        $this->saveNavigation(10, 50, 0, 502);
        $saved = $this->saveItemLanguages(10, true, false);
        self::assertFalse($saved['error']);
        $saved = $this->saveItemLanguages(502, false, true);
        self::assertFalse($saved['error']);

        self::assertSame(10, $this->resolvedHome('/fr/'));
        self::assertSame(502, $this->resolvedHome('/en/'));
        self::assertNull($this->application('/en/')->getRouter()->resolve(
            new Request(uri: '/en/legal/cgu', host: TestFixtures::SITE_HOST)
        ));
        self::assertNull($this->application('/fr/')->getRouter()->resolve(
            new Request(uri: '/fr/legal/cgu', host: TestFixtures::SITE_HOST)
        ));

        $this->assertRejected($this->saveItemLanguages(10, false, false));
        $this->assertRejected($this->saveItemLanguages(502, false, false));

        $status = $this->api('menus/item/status', RequestMethod::POST, [], [
            'menuItemId' => '502', 'status' => '0',
        ]);
        $this->assertRejected($status);
        self::assertSame('1', $this->database->connection()->query(
            'SELECT status FROM menu_items WHERE id = 502'
        )->fetch_assoc()['status']);

        $form = $this->api('menus/item/form', RequestMethod::GET, ['menuId' => '4', 'menuItemId' => '502']);
        self::assertSame(['en'], $form['homeLocales']);
    }

    #[Test]
    public function invalidSelectionsAreRejectedWithoutChangingNavigation(): void
    {
        $this->assertRejected($this->saveNavigation(10, 50, 0, 1));

        $connection = $this->database->connection();
        $connection->query('UPDATE menu_items SET status = 0 WHERE id = 503');
        $this->assertRejected($this->saveNavigation(10, 50, 0, 503));
        $connection->query('UPDATE menu_items SET status = 1 WHERE id = 503');

        $connection->query("INSERT INTO menu_item_languages (menu_item_id, locale, enabled) VALUES (502, 'en', 0)");
        $this->assertRejected($this->saveNavigation(10, 50, 0, 502));

        $unknown = $this->api('spaces/navigation/save', RequestMethod::POST, [], [
            'spaceId' => '2',
            'navigation' => json_encode([
                'home_item_id' => 10, 'user_space_item_id' => 50,
                'languages' => [
                    ['locale' => 'fr', 'home_item_id' => 0, 'user_space_item_id' => 0],
                    ['locale' => 'de', 'home_item_id' => 502, 'user_space_item_id' => 0],
                ],
            ], JSON_THROW_ON_ERROR),
        ]);
        $this->assertRejected($unknown);

        $duplicate = $this->api('spaces/navigation/save', RequestMethod::POST, [], [
            'spaceId' => '2',
            'navigation' => json_encode([
                'home_item_id' => 10, 'user_space_item_id' => 50,
                'languages' => [
                    ['locale' => 'fr', 'home_item_id' => 0, 'user_space_item_id' => 0],
                    ['locale' => 'fr', 'home_item_id' => 502, 'user_space_item_id' => 0],
                ],
            ], JSON_THROW_ON_ERROR),
        ]);
        $this->assertRejected($duplicate);

        $missing = $this->api('spaces/navigation/save', RequestMethod::POST, [], [
            'spaceId' => '2',
            'navigation' => json_encode([
                'home_item_id' => 10, 'user_space_item_id' => 50,
                'languages' => [['locale' => 'fr', 'home_item_id' => 0, 'user_space_item_id' => 0]],
            ], JSON_THROW_ON_ERROR),
        ]);
        $this->assertRejected($missing);

        $otherSpaceUserItem = $this->saveNavigation(10, 1, 0, 0);
        $this->assertRejected($otherSpaceUserItem);

        self::assertSame(10, $this->resolvedHome('/fr/'));
        self::assertSame(10, $this->resolvedHome('/en/'));
        self::assertSame('0', $connection->query(
            "SELECT COUNT(*) AS total FROM space_languages WHERE space_id = 2 AND menu_item_home_id IS NOT NULL"
        )->fetch_assoc()['total']);
    }

    #[Test]
    public function removingLanguageOverrideRestoresGeneralHome(): void
    {
        $this->saveNavigation(10, 50, 0, 502);
        self::assertSame(502, $this->resolvedHome('/en/'));

        $saved = $this->saveNavigation(10, 50, 0, 0);
        self::assertFalse($saved['error']);
        self::assertSame(10, $this->resolvedHome('/en/'));
        self::assertSame('0', $this->database->connection()->query(
            'SELECT COUNT(*) AS total FROM space_languages WHERE space_id = 2 AND menu_item_home_id IS NOT NULL'
        )->fetch_assoc()['total']);
    }

    #[Test]
    public function spaceWithoutHomeStillServesItsOtherMenuPages(): void
    {
        $this->database->connection()->query('UPDATE spaces SET menu_item_home_id = NULL WHERE id = 2');
        $request = new Request(uri: '/fr/legal/cgu', host: TestFixtures::SITE_HOST);
        $application = $this->application('/fr/legal/cgu');

        self::assertSame(502, $application->getRouter()->resolve($request)?->routeData['menu_item']->id);
        $response = $this->application('/fr/')->executeAndGetResponse();
        self::assertSame(200, $response->code);
        self::assertStringContainsString('Configurez votre page Home', $response->body);
        self::assertSame(200, $this->application('/en/')->executeAndGetResponse()->code);
    }

    #[Test]
    public function userSpaceCanDifferByLanguageAndFallsBackToGeneralChoice(): void
    {
        self::assertSame('/fr/mon-espace', $this->authenticatedResponse('fr')->httpHeaders['Location']);
        self::assertSame('/en/mon-espace', $this->authenticatedResponse('en')->httpHeaders['Location']);

        $saved = $this->saveNavigation(10, 50, 0, 0, 0, 502);
        self::assertFalse($saved['error']);
        self::assertSame([0, 502], array_column($saved['languages'], 'user_space_item_id'));
        self::assertSame('/fr/mon-espace', $this->authenticatedResponse('fr')->httpHeaders['Location']);
        self::assertSame('/en/legal/cgu', $this->authenticatedResponse('en')->httpHeaders['Location']);

        $this->assertRejected($this->saveItemLanguages(502, true, false));
        self::assertFalse($this->saveItemLanguages(50, true, false)['error']);
        self::assertSame('/en/legal/cgu', $this->authenticatedResponse('en')->httpHeaders['Location']);

        // Le parent de l'entrée active ne peut pas être masqué ni dépublié.
        $this->assertRejected($this->saveItemLanguages(20, false, true));
        $parentForm = $this->api('menus/item/form', RequestMethod::GET,
            ['menuId' => '3', 'menuItemId' => '20']);
        self::assertSame(['fr'], $parentForm['requiredLocales']);
        $englishForm = $this->api('menus/item/form', RequestMethod::GET,
            ['menuId' => '4', 'menuItemId' => '502']);
        self::assertSame(['en'], $englishForm['userSpaceLocales']);
        $this->assertRejected($this->api('menus/item/status', RequestMethod::POST, [], [
            'menuItemId' => '20', 'status' => '0',
        ]));

        $saved = $this->saveNavigation(10, 50, 0, 0, 0, 0);
        $this->assertRejected($saved);
        self::assertSame('502', $this->database->connection()->query(
            "SELECT menu_item_user_space_id FROM space_languages WHERE space_id = 2 AND locale = 'en'"
        )->fetch_assoc()['menu_item_user_space_id']);
    }

    #[Test]
    public function invalidUserSpaceSelectionsAreRejected(): void
    {
        $this->assertRejected($this->saveNavigation(10, 50, 0, 0, 0, 1));

        $this->database->connection()->query('UPDATE menu_items SET status = 0 WHERE id = 503');
        $this->assertRejected($this->saveNavigation(10, 50, 0, 0, 0, 503));

        $this->database->connection()->query("INSERT INTO menu_item_languages (menu_item_id, locale, enabled) VALUES (502, 'en', 0)");
        $this->assertRejected($this->saveNavigation(10, 50, 0, 0, 0, 502));
        self::assertSame('0', $this->database->connection()->query(
            'SELECT COUNT(*) AS total FROM space_languages WHERE space_id = 2 AND menu_item_user_space_id IS NOT NULL'
        )->fetch_assoc()['total']);
    }

    #[Test]
    public function newSpaceCanKeepGeneralNavigationEmptyWhileUsingLanguageOverrides(): void
    {
        $saved = $this->saveNavigation(0, 0, 10, 0, 0, 502);
        self::assertFalse($saved['error']);
        self::assertSame(0, $saved['home_item_id']);
        self::assertSame(0, $saved['user_space_item_id']);
        self::assertSame(10, $this->resolvedHome('/fr/'));
        self::assertStringContainsString('Configurez votre page Home',
            $this->application('/en/')->executeAndGetResponse()->body);
        self::assertSame('/en/legal/cgu', $this->authenticatedResponse('en')->httpHeaders['Location']);
        self::assertSame('/fr/', $this->authenticatedResponse('fr')->httpHeaders['Location']);
    }

    #[Test]
    public function generalSpaceEditorKeepsNavigationOwnedByItsSeparatePanel(): void
    {
        $this->saveNavigation(10, 55, 0, 502);

        $form = $this->api('spaces/form', RequestMethod::GET, ['spaceId' => '2'])['form'];
        $fields = array_merge(...array_map(static fn (array $section): array =>
            $section['fields'] ?? [], $form['sections']));
        $names = array_column($fields, 'name');
        self::assertNotContains('menu_item_home_id', $names);
        self::assertNotContains('menu_item_user_space_id', $names);

        $values = [];
        foreach ($fields as $field) {
            $name = $field['name'] ?? null;
            if ($name !== null && ($field['submittable'] ?? true)
                && array_key_exists($name, $form['initialValues'])) {
                $values[$name] = $form['initialValues'][$name];
            }
        }

        $updated = $this->api('spaces/update', RequestMethod::POST, [], [
            'spaceId' => '2',
            'data' => json_encode($values, JSON_THROW_ON_ERROR),
        ]);
        self::assertFalse($updated['error']);

        $navigation = $this->api('spaces/navigation', RequestMethod::GET, ['spaceId' => '2']);
        self::assertSame(55, $navigation['user_space_item_id']);
        self::assertSame([0, 502], array_column($navigation['languages'], 'home_item_id'));
        self::assertSame(502, $this->resolvedHome('/en/'));
    }

    #[Test]
    public function guestAndRequestWithoutCsrfCannotChangeNavigation(): void
    {
        $guest = $this->api('spaces/navigation', RequestMethod::GET, ['spaceId' => '2'], [], false);
        $this->assertRejected($guest);

        $withoutCsrf = $this->api('spaces/navigation/save', RequestMethod::POST, [], [
            'spaceId' => '2',
            'navigation' => json_encode([
                'home_item_id' => 10, 'user_space_item_id' => 50,
                'languages' => [
                    ['locale' => 'fr', 'home_item_id' => 0, 'user_space_item_id' => 0],
                    ['locale' => 'en', 'home_item_id' => 502, 'user_space_item_id' => 0],
                ],
            ], JSON_THROW_ON_ERROR),
        ], true, false);
        $this->assertRejected($withoutCsrf);
        self::assertSame(10, $this->resolvedHome('/en/'));
    }

    private function resolvedHome(string $uri): ?int
    {
        return $this->application($uri)->getRouter()->resolve(
            new Request(uri: $uri, host: TestFixtures::SITE_HOST)
        )?->routeData['menu_item']->id;
    }

    private function application(string $uri): Application
    {
        $application = new Application($this->environment->configuration());
        $application->initialiseOrThrow(new Request(uri: $uri, host: TestFixtures::SITE_HOST));
        return $application;
    }

    private function authenticatedResponse(string $locale): \YTassy\Tycms\Response\Response
    {
        $request = new Request(uri: '/login-fb', headers: ['Accept-Language' => $locale], host: TestFixtures::SITE_HOST,
            cookies: ['session_key' => $this->sessionKey]);
        $application = new Application($this->environment->configuration());
        $application->initialiseOrThrow($request);
        return $application->executeAndGetResponse();
    }

    private function saveNavigation(int $general, int $user, int $fr, int $en,
        int $userFr = 0, int $userEn = 0): array
    {
        return $this->api('spaces/navigation/save', RequestMethod::POST, [], [
            'spaceId' => '2',
            'navigation' => json_encode([
                'home_item_id' => $general,
                'user_space_item_id' => $user,
                'languages' => [
                    ['locale' => 'fr', 'home_item_id' => $fr, 'user_space_item_id' => $userFr],
                    ['locale' => 'en', 'home_item_id' => $en, 'user_space_item_id' => $userEn],
                ],
            ], JSON_THROW_ON_ERROR),
        ]);
    }

    private function saveItemLanguages(int $itemId, bool $fr, bool $en): array
    {
        return $this->api('menus/item/update', RequestMethod::POST, [], [
            'menuItemId' => (string) $itemId,
            'data' => json_encode(['languages' => [
                ['locale' => 'fr', 'enabled' => $fr],
                ['locale' => 'en', 'enabled' => $en],
            ]], JSON_THROW_ON_ERROR),
        ]);
    }

    private function assertRejected(array $response): void
    {
        self::assertTrue(($response['is_message'] ?? false) || ($response['error'] ?? false));
    }

    /** @param array<string,string> $query @param array<string,string> $body */
    private function api(string $path, RequestMethod $method, array $query = [], array $body = [],
        bool $authenticated = true, bool $withCsrf = true): array
    {
        $request = new Request(
            method: $method,
            uri: '/administrator/api/' . $path,
            query: $query,
            headers: $withCsrf ? ['X-CSRF-Token' => $this->csrfToken] : [],
            cookies: $authenticated ? ['session_key' => $this->sessionKey] : [],
            body: $body,
            host: TestFixtures::SITE_HOST,
        );
        $application = new Application($this->environment->configuration());
        $application->initialiseOrThrow($request);
        return json_decode($application->executeAndGetResponse()->body ?? '', true, 512, JSON_THROW_ON_ERROR);
    }
}
