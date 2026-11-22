<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Core\Helper\Menu\MenuHelper;
use YTassy\Tycms\Request\Request;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Tests\Support\Integration\TestDatabase;
use YTassy\Tycms\Tests\Support\Integration\TestEnvironment;
use YTassy\Tycms\Tests\Support\Integration\TestFixtures;

final class MenuLanguageIntegrationTest extends TestCase
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

        // Chaque test conserve uniquement ses propres variantes et sa session.
        $this->database->connection()->query('DELETE FROM menu_item_languages WHERE menu_item_id IN (10, 502, 503)');
        $this->database->connection()->query('UPDATE menu_items SET parent_id = NULL WHERE id = 503');

        $this->sessionKey = bin2hex(random_bytes(24));
        $this->csrfToken = bin2hex(random_bytes(32));
        $data = serialize([
            'user_id' => 1,
            'current_space_id' => TestFixtures::SITE_SPACE_ID,
            'csrf_token' => $this->csrfToken,
        ]);
        $statement = $this->database->connection()->prepare(
            'INSERT INTO sessions (`key`, `user_id`, `data`) VALUES (?, 1, ?)'
        );
        $statement->bind_param('ss', $this->sessionKey, $data);
        $statement->execute();
    }

    protected function tearDown(): void
    {
        $connection = $this->database->connection();
        $connection->query('DELETE FROM menu_item_languages WHERE menu_item_id IN (10, 502, 503)');
        $connection->query('UPDATE menu_items SET parent_id = NULL WHERE id = 503');
        $statement = $connection->prepare('DELETE FROM sessions WHERE `key` = ?');
        $statement->bind_param('s', $this->sessionKey);
        $statement->execute();
    }

    #[Test]
    public function hiddenParentAffectsRoutesAndRenderedMenu(): void
    {
        $connection = $this->database->connection();
        $connection->query('UPDATE menu_items SET parent_id = 502 WHERE id = 503');
        // Sans exception, l'enfant est accessible dans les deux langues.
        $english = $this->application('/en/legal/cgu/confidentialite');
        self::assertSame(503, $english->getRouter()->resolve(
            new Request(uri: '/en/legal/cgu/confidentialite', host: TestFixtures::SITE_HOST)
        )?->routeData['menu_item']->id);

        $connection->query("INSERT INTO menu_item_languages (menu_item_id, locale, enabled) VALUES (502, 'en', 0)");

        // La liste d'administration distingue le choix de l'enfant du masquage hérité.
        $listing = $this->api('', RequestMethod::GET);
        $items = $listing['menus'][TestFixtures::SITE_SPACE_ID][4]['items'];
        $itemsById = array_column($items, null, 'id');
        self::assertSame([
            ['locale' => 'fr', 'enabled' => true, 'visible' => true],
            ['locale' => 'en', 'enabled' => false, 'visible' => false],
        ], $itemsById[502]['languages']);
        self::assertSame([
            ['locale' => 'fr', 'enabled' => true, 'visible' => true],
            ['locale' => 'en', 'enabled' => true, 'visible' => false],
        ], $itemsById[503]['languages']);

        $hidden = $this->application('/en/');
        self::assertNull($hidden->getRouter()->resolve(new Request(uri: '/en/legal/cgu', host: TestFixtures::SITE_HOST)));
        self::assertNull($hidden->getRouter()->resolve(new Request(uri: '/en/legal/cgu/confidentialite', host: TestFixtures::SITE_HOST)));

        $menu = $hidden->getOrAddSharedExtensionsClass(MenuHelper::class);
        self::assertStringNotContainsString('/en/legal/cgu', $menu->getHtml(4, 'simple'));
        self::assertStringNotContainsString('Confidentialité et protection des données', $menu->getHtml(4, 'simple'));

        // Sans surcharge française, les chemins historiques du contenu restent disponibles.
        $french = $this->application('/fr/legal/cgu/confidentialite');
        self::assertSame(503, $french->getRouter()->resolve(
            new Request(uri: '/fr/legal/cgu/confidentialite', host: TestFixtures::SITE_HOST)
        )?->routeData['menu_item']->id);
    }

    #[Test]
    public function apiSavesBothLanguagesAndProtectsTheHomeEntry(): void
    {
        $form = $this->api('item/form', RequestMethod::GET, [
            'menuId' => '4', 'menuItemId' => '502',
        ]);
        self::assertSame(['fr', 'en'], array_column($form['languages'], 'locale'));
        self::assertSame([true, true], array_column($form['languages'], 'enabled'));

        $saved = $this->api('item/update', RequestMethod::POST, [], [
            'menuItemId' => '502',
            'data' => json_encode(['languages' => [
                ['locale' => 'fr', 'enabled' => true],
                ['locale' => 'en', 'enabled' => false],
            ]], JSON_THROW_ON_ERROR),
        ]);
        self::assertFalse($saved['error']);

        $rows = $this->database->connection()->query(
            'SELECT locale, enabled FROM menu_item_languages WHERE menu_item_id = 502 ORDER BY locale'
        )->fetch_all(MYSQLI_ASSOC);
        self::assertSame([['locale' => 'en', 'enabled' => '0']], $rows);

        $home = $this->api('item/update', RequestMethod::POST, [], [
            'menuItemId' => '10',
            'data' => json_encode(['languages' => [
                ['locale' => 'fr', 'enabled' => true],
                ['locale' => 'en', 'enabled' => false],
            ]], JSON_THROW_ON_ERROR),
        ]);
        self::assertTrue($home['is_message']);
        self::assertSame(0, (int) $this->database->connection()->query(
            'SELECT COUNT(*) AS total FROM menu_item_languages WHERE menu_item_id = 10'
        )->fetch_assoc()['total']);
    }

    #[Test]
    public function apiRejectsInactiveLanguageAndAliasOverride(): void
    {
        $inactive = $this->api('item/update', RequestMethod::POST, [], [
            'menuItemId' => '502',
            'data' => json_encode(['languages' => [
                ['locale' => 'fr', 'enabled' => true],
                ['locale' => 'de', 'enabled' => false],
            ]], JSON_THROW_ON_ERROR),
        ]);
        self::assertTrue($inactive['is_message']);

        // Les alias restent communs aux langues : l'API ne les accepte pas ici.
        $alias = $this->api('item/update', RequestMethod::POST, [], [
            'menuItemId' => '502',
            'data' => json_encode(['languages' => [
                ['locale' => 'fr', 'enabled' => true],
                ['locale' => 'en', 'enabled' => false, 'alias' => 'terms'],
            ]], JSON_THROW_ON_ERROR),
        ]);
        self::assertTrue($alias['is_message'] ?? $alias['error']);
        self::assertSame(0, (int) $this->database->connection()->query(
            'SELECT COUNT(*) AS total FROM menu_item_languages WHERE menu_item_id = 502'
        )->fetch_assoc()['total']);
    }

    private function application(string $uri): Application
    {
        $application = new Application($this->environment->configuration());
        $application->initialiseOrThrow(new Request(uri: $uri, host: TestFixtures::SITE_HOST));
        return $application;
    }

    /** @param array<string,string> $query @param array<string,string> $body */
    private function api(string $path, RequestMethod $method, array $query = [], array $body = []): array
    {
        $request = new Request(
            method: $method,
            uri: '/administrator/api/menus' . ($path === '' ? '' : '/' . $path),
            query: $query,
            headers: ['X-CSRF-Token' => $this->csrfToken],
            cookies: ['session_key' => $this->sessionKey],
            body: $body,
            host: TestFixtures::SITE_HOST,
        );
        $application = new Application($this->environment->configuration());
        $application->initialiseOrThrow($request);
        return json_decode($application->executeAndGetResponse()->body ?? '', true, 512, JSON_THROW_ON_ERROR);
    }
}
