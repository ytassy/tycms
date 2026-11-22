<?php

namespace YTassy\Tycms\Tests\Support;

use ReflectionProperty;
use YTassy\Tycms\CoreExts\Core\Helper\Template\TemplateHelper;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Core\Models\Page;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageRenderContext;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageRenderMode;
use YTassy\Tycms\CoreExts\Core\Plugin;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageComponentRegistry;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageDocument;
use YTassy\Tycms\CoreExts\Core\Helper\Forms\FormTypesHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Space\SpaceHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Space\SpaceLanguageHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Acl\AclHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Menu\MenuHelper;

/** Fabriques unitaires sans SQL. Les raccourcis de fixtures ne sont jamais utilisés en production. */
final class PageFixtures
{
    public static function application(array $services = []): Application
    {
        $app = new Application(new \Configuration());
        self::initialize($app, $services);
        return $app;
    }

    public static function initialize(Application $app, array $services = []): PageComponentRegistry
    {
        $property = new ReflectionProperty(Application::class, 'sharedExtensionsClasses');
        $existing = $property->getValue($app);
        $registry = new PageComponentRegistry($app);
        $defaults = [
            \YTassy\Tycms\CoreExts\Security\Helper\SecurityPolicyHelper::class => new class($app) extends \YTassy\Tycms\CoreExts\Security\Helper\SecurityPolicyHelper {
                public function forSpace(int $spaceId): \YTassy\Tycms\CoreExts\Security\SecurityPolicy
                {
                    return new \YTassy\Tycms\CoreExts\Security\SecurityPolicy(\YTassy\Tycms\CoreExts\Security\SecurityPolicy::defaults());
                }
            },
            SpaceLanguageHelper::class => new PageFixtureLanguages($app),
            SpaceHelper::class => new PageFixtureSpaces($app),
            AclHelper::class => new PageFixtureAcl($app),
            MenuHelper::class => new PageFixtureMenus($app),
            TemplateHelper::class => new PageFixtureTemplate($app),
        ];
        $property->setValue($app, array_replace($defaults, $existing, $services, [PageComponentRegistry::class => $registry]));
        (new Plugin($app))->coreOnRegisterPageComponents($registry);
        $forms = new FormTypesHelper($app);
        if (!$app->getOrAddSharedExtensionsClass(\YTassy\Tycms\Lib\Forms\FormFactory::class)->hasType('core.localized_text')) $forms->registerLocalizedTextFieldType();
        return $registry;
    }

    /** Les anciennes fixtures abrégées restent une commodité de construction des tests seulement. */
    public static function document(object $input): object
    {
        if (($input->version ?? null) === 3) return $input;
        $number = 0;
        $visit = function (array $items) use (&$visit, &$number): array {
            foreach ($items as $item) {
                $item->id ??= 'fixture_' . ++$number;
                $item->type = str_contains($item->type, '.') ? $item->type : 'core.' . $item->type;
                $item->props ??= (object) [];
                if ($item->props === []) $item->props = (object) [];
                foreach (['content', 'src', 'alt', 'menu_id', 'display_type', 'width', 'max_width', 'max_height'] as $field) {
                    if (isset($item->{$field})) $item->props->{$field} = $item->{$field};
                }
                $zones = (object) [];
                foreach (['columns', 'tabs', 'panels'] as $name) {
                    if (!property_exists($item, $name)) continue;
                    $zones->{$name} = $item->{$name};
                    unset($item->{$name});
                }
                $item->zones ??= $zones;
                foreach (get_object_vars($item->zones) as $name => $bodies) {
                    foreach ($bodies as $body) {
                        $body->id ??= 'fixture_' . ++$number;
                        $body->props ??= (object) [];
                        if ($body->props === []) $body->props = (object) [];
                        if (isset($body->align)) $body->props->align = $body->align;
                        if (is_array($body->sizes ?? null)) {
                            $sizes = (object) [];
                            foreach ($body->sizes as $class) if (preg_match('/^col(?:-(sm|md|lg|xl|xxl))?-(\d+)$/', $class, $m)) $sizes->{$m[1] ?: 'xs'} = (int) $m[2];
                            $body->sizes = $sizes;
                        }
                        $body->items = $visit($body->items ?? []);
                    }
                }
            }
            return $items;
        };
        return (object) ['version' => 3, 'container' => $input->container ?? (object) ['fluid' => false], 'items' => $visit($input->items)];
    }

    public static function decode(string $json, bool $associative = false, int $depth = 512, int $flags = 0): mixed
    {
        $value = json_decode($json, $associative, $depth, $flags);
        if (is_object($value) && isset($value->type)) return self::document((object) ['items' => [$value]])->items[0];
        return is_object($value) && is_array($value->items ?? null) ? self::document($value) : $value;
    }

    public static function normalize(object $input): object
    {
        $app = self::application();
        return $app->getOrAddSharedExtensionsClass(PageComponentRegistry::class)->normalizeDocument(self::document($input), 1);
    }

    /** Contexte minimal pour exercer directement le contrat d’un composant. */
    public static function renderContext(int $spaceId = 7): PageRenderContext
    {
        $page = new Page(self::application());
        $page->space_id = $spaceId;
        return new PageRenderContext(
            $page, 'fr', PageRenderMode::PUBLIC,
            static fn (array $items): string => '', static fn (object $props): bool => true,
            static fn (object $props): string => '', static fn (mixed $value): string => (string) $value,
        );
    }

    public static function normalizeForRender(object $input): object
    {
        return PageDocument::normalize(self::document($input));
    }
}

class PageFixtureLanguages extends SpaceLanguageHelper
{
    public function listForSpace(int $spaceId): array { return [(object) ['locale' => 'fr'], (object) ['locale' => 'en']]; }
    public function listActiveForSpace(int $spaceId): array { return $this->listForSpace($spaceId); }
    public function getDefaultLocale(int $spaceId): string { return 'fr'; }
}
class PageFixtureSpaces extends SpaceHelper
{
    public function getAllSpacesByIds(): array { return []; }
    public function getSpacesMatchingRequestHost(): array { return []; }
    public function __construct(Application $app) { \YTassy\Tycms\BaseApplicationItem::__construct($app); }
    public function getRolesForSpace(?int $spaceId = null): array { return [1 => (object) ['id' => 1], 3 => (object) ['id' => 3]]; }
    public function getSpaceIdsWithParents(?int $spaceId = null): array { return [$spaceId]; }
}
class PageFixtureAcl extends AclHelper
{
    public function __construct(Application $app) { \YTassy\Tycms\BaseApplicationItem::__construct($app); }
    public function hasGlobalRole(int $roleId, int|\YTassy\Tycms\CoreExts\Core\Models\Space|null $space = null): bool { return false; }
}
class PageFixtureMenus extends MenuHelper
{
    public function __construct(Application $app) { \YTassy\Tycms\BaseApplicationItem::__construct($app); }
    public function getMenusAnItemsOfSpace(?int $spaceId = null): array { return []; }
}

class PageFixtureTemplate extends TemplateHelper
{
    public function getEditorColorVariables(int $spaceId): array { return []; }
}
