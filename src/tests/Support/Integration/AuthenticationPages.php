<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Support\Integration;

use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Core\Helper\AuthenticationHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Space\SpaceHelper;
use YTassy\Tycms\CoreExts\Core\PageComponents\{Login, Register};

/** Pages temporaires propres au scénario, sans modifier les données de migration. */
final class AuthenticationPages
{
    private array $created = [];

    public function __construct(private TestDatabase $database) {}

    public function install(int $spaceId, string $kind = 'register', array $overrides = []): void
    {
        $class = $kind === 'register' ? Register::class : Login::class;
        $field = $kind === 'register' ? 'menu_item_register_id' : 'menu_item_login_id';
        $db = $this->database->connection();
        $original = $db->query('SELECT ' . $field . ' FROM spaces WHERE id = ' . $spaceId)->fetch_assoc()[$field];
        $props = (object) array_replace($class::getDefaultData(), $overrides);
        $node = (object) ['id' => $kind . $spaceId, 'type' => 'core.' . $kind, 'props' => $props, 'zones' => (object) []];
        $document = (object) ['version' => 3, 'container' => (object) ['fluid' => false], 'items' => [
            (object) ['id' => 'row', 'type' => 'core.row', 'props' => (object) [], 'zones' => (object) ['columns' => [
                (object) ['id' => 'column', 'props' => (object) [], 'items' => [$node], 'sizes' => (object) []],
            ]]],
        ]];
        $json = json_encode($document, JSON_THROW_ON_ERROR);
        $name = 'Auth fixture ' . $kind . ' ' . bin2hex(random_bytes(6));
        $statement = $db->prepare("INSERT INTO pages (space_id, user_defined, name, description, type, data) VALUES (?, 1, ?, '', 2, ?)");
        $statement->bind_param('iss', $spaceId, $name, $json);
        $statement->execute();
        $pageId = (int) $db->insert_id;
        $menuId = (int) $db->query('SELECT id FROM menus WHERE space_id = ' . $spaceId . ' ORDER BY id LIMIT 1')->fetch_assoc()['id'];
        $params = json_encode(['page_id' => $pageId], JSON_THROW_ON_ERROR);
        $alias = 'auth-' . $kind . '-' . $pageId;
        $statement = $db->prepare("INSERT INTO menu_items (menu_id, ordering, extension, type, params, title, alias, css, icon, browser_title, meta_description, meta_robots, page_title) VALUES (?, 999, 'plg_core', 'page', ?, 'Authentication fixture', ?, '', '', '', '', '', '')");
        $statement->bind_param('iss', $menuId, $params, $alias);
        $statement->execute();
        $itemId = (int) $db->insert_id;
        $db->query('UPDATE spaces SET ' . $field . ' = ' . $itemId . ' WHERE id = ' . $spaceId);
        $this->created[$spaceId . ':' . $kind] = compact('spaceId', 'kind', 'field', 'original', 'itemId', 'pageId');
    }

    public function token(Application $app, int $spaceId, string $kind = 'register', string $locale = 'fr'): string
    {
        $fixture = $this->created[$spaceId . ':' . $kind];
        $space = $app->getOrAddSharedExtensionsClass(SpaceHelper::class)->getSpace($spaceId);
        return $app->getOrAddSharedExtensionsClass(AuthenticationHelper::class)
            ->token($space, $kind, $locale, $kind . $spaceId, $fixture['pageId']) ?? throw new \RuntimeException('Fixture d’authentification indisponible.');
    }

    public function url(Application $app, int $spaceId, string $kind = 'register'): string
    {
        $space = $app->getOrAddSharedExtensionsClass(SpaceHelper::class)->getSpace($spaceId);
        return $app->getOrAddSharedExtensionsClass(AuthenticationHelper::class)->pageUrl($space, $kind, 'fr');
    }

    public function pageId(int $spaceId, string $kind = 'register'): int
    {
        return $this->created[$spaceId . ':' . $kind]['pageId'];
    }

    public function itemId(int $spaceId, string $kind = 'register'): int
    {
        return $this->created[$spaceId . ':' . $kind]['itemId'];
    }

    public function restore(): void
    {
        $db = $this->database->connection();
        foreach ($this->created as $fixture) {
            $original = $fixture['original'] === null ? 'NULL' : (string) (int) $fixture['original'];
            $db->query('UPDATE spaces SET ' . $fixture['field'] . ' = ' . $original . ' WHERE id = ' . $fixture['spaceId']);
            $db->query('DELETE FROM menu_items WHERE id = ' . $fixture['itemId']);
            $db->query('DELETE FROM pages WHERE id = ' . $fixture['pageId']);
        }
        $this->created = [];
    }
}
