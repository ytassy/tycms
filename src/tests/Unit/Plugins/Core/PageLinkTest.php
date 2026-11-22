<?php

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\Tests\Support\PageFixtures;
use ReflectionClass;
use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\CoreExts\Core\Controllers\PageController;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Menu\MenuItemDef;
use YTassy\Tycms\CoreExts\Core\Helper\Acl\AclHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Menu\MenuHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Menu\MenuLanguageHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Menu\MenuRouteHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageComponentRegistry;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageDocument;
use YTassy\Tycms\CoreExts\Core\PageComponents\Support\PageLinks;
use YTassy\Tycms\CoreExts\Core\Helper\Page\Renderer\PageRenderer;
use YTassy\Tycms\CoreExts\Core\Helper\Space\SpaceLanguageHelper;
use YTassy\Tycms\CoreExts\Core\Models\Menu;
use YTassy\Tycms\CoreExts\Core\Models\MenuItem;
use YTassy\Tycms\CoreExts\Core\Models\Page;
use YTassy\Tycms\CoreExts\Core\WF\WFPage;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Routing\Annotation\Doc\Controller;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerMethod;

final class PageLinkTest extends TestCase
{
    private function instance(string $class): object
    {
        return (new ReflectionClass($class))->newInstanceWithoutConstructor();
    }

    private function fixture(bool $allowRole = true, bool $english = true): array
    {
        $menu = $this->instance(Menu::class);
        $menu->id = 10; $menu->space_id = 7; $menu->name = 'Navigation'; $menu->alias = 'nav';
        foreach ([1 => [null, 'Parent', 'parent'], 2 => [1, 'Enfant', 'child'], 3 => [null, 'Accueil', 'home']] as $id => [$parent, $title, $alias]) {
            $item = $this->instance(MenuItem::class);
            $item->id = $id; $item->menu_id = 10; $item->parent_id = $parent;
            $item->title = $title; $item->alias = $alias; $item->status = 1;
            $item->access_role_id = null; $item->extension = 'test'; $item->type = 'page';
            $menu->items[] = $item;
        }
        $menus = $this->createStub(MenuHelper::class);
        $menus->method('getMenusAnItemsOfSpace')->willReturnCallback(fn ($space) => $space === 7 ? [$menu] : []);
        $menus->method('getMenuItemAndMenu')->willReturnCallback(static function ($id) use ($menu) {
            foreach ($menu->items as $item) if ($item->id === $id && $item->status === 1) return [$menu, $item];
            return [null, null];
        });
        $languages = $this->createStub(SpaceLanguageHelper::class);
        $languages->method('listActiveForSpace')->willReturn([(object) ['locale' => 'fr'], (object) ['locale' => 'en']]);
        $languages->method('getHomeMenuItemId')->willReturnCallback(fn ($space, $locale) => $locale === 'fr' ? 3 : 2);
        $visibility = $this->createStub(MenuLanguageHelper::class);
        $visibility->method('isVisible')->willReturnCallback(fn ($item, $locale) => $locale === 'fr' || $english);
        $acl = $this->createStub(AclHelper::class);
        $acl->method('hasGlobalRole')->willReturnCallback(fn ($id, $space) => $allowRole && $space === 7);
        $routes = $this->instance(PageLinkRoutesStub::class);
        $routes->services = [MenuHelper::class => $menus, SpaceLanguageHelper::class => $languages,
            MenuLanguageHelper::class => $visibility, AclHelper::class => $acl];
        $routes->menuDefs['test__page'] = new MenuItemDef('test', 'page', $this->instance(PageController::class), ['GET' => 'page'], 'Page', '', '');
        $link = new PageLinks($menus, $routes, $languages);
        $services = [MenuHelper::class => $menus, SpaceLanguageHelper::class => $languages, MenuRouteHelper::class => $routes];
        return [$link, $routes, $menu, $services];
    }

    public static function invalidOptions(): iterable
    {
        foreach ([['href' => 'javascript:alert(1)'], ['href' => '//evil.test/x'], ['href' => '/\\evil.test'],
            ['href' => '/%2f%2fevil.test'], ['href' => "https://example.test/\nfoo"], ['href' => '/%0afoo'],
            ['href' => 'http://example.test'], ['href' => []], ['link_new_tab' => 'true'],
            ['menu_item_id' => '2'], ['menu_item_id' => -1], ['link_type' => 'other'],
            ['click_action' => 'other'], ['click_action' => null], ['link_type' => null]] as $options) yield [$options];
    }

    #[DataProvider('invalidOptions')]
    public function testInvalidLinksAreRejectedBeforeSaving(array $options): void
    {
        $this->expectException(ExceptionResponse::class);
        PageLinks::validate((object) $options, true);
    }

    public function testFreeLinksAndBlankAttributesUseTheSharedPolicy(): void
    {
        self::assertSame('/contact?x=1&y=2', PageLinks::safeUrl('/contact?x=1&y=2'));
        self::assertSame('https://example.test/x', PageLinks::safeUrl('https://example.test/x'));
        self::assertSame(' target="_blank" rel="noopener noreferrer"', PageLinks::targetAttributes((object) ['link_new_tab' => true]));
        self::assertSame('', PageLinks::targetAttributes((object) []));
    }

    public function testMenuUrlsFollowAliasesLanguageAndLocaleSpecificHome(): void
    {
        [, $routes, $menu] = $this->fixture();
        self::assertSame('/fr/nav/parent/child', $routes->getUrlForContext(2, 7, 'fr'));
        self::assertSame('/en/', $routes->getUrlForContext(2, 7, 'en'));
        self::assertSame('/fr/', $routes->getUrlForContext(3, 7, 'fr'));
        $menu->items[0]->alias = 'renamed';
        self::assertSame('/fr/nav/renamed/child', $routes->getUrlForContext(2, 7, 'fr'));
        self::assertNull($routes->getUrlForContext(2, 8, 'fr'));
        self::assertNull($routes->getUrlForContext(2, 7, 'de'));
    }

    public function testUnpublishedAncestorsOrCyclesCannotBecomeDestinations(): void
    {
        [, $routes, $menu] = $this->fixture();
        $menu->items[0]->status = 0;
        self::assertNull($routes->getUrlForContext(2, 7, 'fr'));
        $menu->items[0]->status = 1;
        $menu->items[0]->parent_id = 2;
        self::assertNull($routes->getUrlForContext(2, 7, 'fr'));
        $menu->items[0]->parent_id = 999;
        self::assertNull($routes->getUrlForContext(2, 7, 'fr'));
    }

    public function testVisitorRolesAndLanguageDisableTheLinkWithoutAffectingTheEditorialList(): void
    {
        [$link, $routes, $menu] = $this->fixture(false, false);
        $menu->items[0]->access_role_id = 9;
        self::assertNull($routes->getUrlForContext(2, 7, 'fr'));
        self::assertNull($routes->getUrlForContext(2, 7, 'en', false));
        $options = array_column($link->menuOptions(7), null, 'id');
        self::assertSame('Parent / Enfant', $options[2]['label']);
        self::assertSame(['fr'], $options[2]['locales']);
        self::assertArrayNotHasKey('params', $options[2]);
        self::assertSame([], $link->menuOptions(8));
    }

    public function testNonNavigableMenuTypesAreExcludedAndTechnicalPathsRemainUnprefixed(): void
    {
        [$link, $routes, $menu] = $this->fixture();
        $routes->menuDefs['test__page'] = new MenuItemDef('test', 'page', null, null, 'Texte', '', '');
        self::assertSame([], $link->menuOptions(7));
        $routes->menuDefs['test__page'] = new MenuItemDef('test', 'page', $this->instance(PageLinkTechnicalController::class), ['GET' => 'login'], 'Connexion', '', '');
        self::assertSame('/auth/login', $routes->getUrlForContext(2, 7, 'en'));
        $routes->menuDefs['test__page'] = new MenuItemDef('test', 'page', $this->instance(PageLinkTechnicalController::class), ['GET' => 'parameter'], 'Paramètre', '', '');
        self::assertNull($routes->getUrlForContext(2, 7, 'fr'));
    }

    public function testWorkflowCatalogueRequiresOnlyPageCrudAndUsesTheRequestedSpace(): void
    {
        [$link, , , $services] = $this->fixture();
        $acl = $this->createStub(AclHelper::class);
        $acl->method('hasGlobalPermission')->willReturnCallback(fn ($permission, $space) => $permission === Permissions::PAGE_CRUD && $space === 7);
        $workflow = new PageLinkWorkflowStub(PageFixtures::application($services));
        $workflow->services = [AclHelper::class => $acl, ...$services];
        self::assertCount(3, $workflow->linkMenuItems(7)['items']);
        $this->expectException(ExceptionResponse::class);
        $workflow->linkMenuItems(8);
    }

    public function testExternalDraftCannotLinkToAnotherSpace(): void
    {
        [$link, , , $services] = $this->fixture();
        $acl = $this->createStub(AclHelper::class);
        $acl->method('hasGlobalPermission')->willReturn(true);
        $workflow = new PageLinkWorkflowStub(PageFixtures::application($services));
        $workflow->services = [AclHelper::class => $acl, ...$services,
            PageComponentRegistry::class => PageFixtures::application($services)->getOrAddSharedExtensionsClass(PageComponentRegistry::class)];
        $this->expectException(ExceptionResponse::class);
        $workflow->validateDraft((object) ['space_id' => 8, 'data' => $this->document((object) [
            'link_type' => 'menu', 'menu_item_id' => 2,
        ])]);
    }

    private function document(object $props, string $type = 'core.button'): object
    {
        $class = $type === 'core.image' ? \YTassy\Tycms\CoreExts\Core\PageComponents\Image::class : \YTassy\Tycms\CoreExts\Core\PageComponents\Button::class;
        $props = (object) array_intersect_key(get_object_vars($props), $class::getPropsSchema()['properties']);
        return PageFixtures::document((object) ['items' => [(object) ['id' => 'r', 'type' => 'core.row', 'columns' => [
            (object) ['id' => 'c', 'items' => [(object) ['id' => 'n', 'type' => $type, 'props' => $props]]],
        ]]]]);
    }

    public function testImageAndButtonRenderTheirSharedLinkAndSafeBlankAttributes(): void
    {
        [$link, , , $services] = $this->fixture();
        $renderer = new PageRenderer(PageFixtures::application($services));
        $page = $this->instance(Page::class);
        $page->id = 1; $page->space_id = 7; $page->type = 2; $page->raw_css = null;
        foreach (['core.image', 'core.button'] as $type) {
            $props = (object) ['click_action' => 'link', 'src' => '/photo.png', 'text' => 'Voir',
                'link_type' => 'menu', 'menu_item_id' => 2, 'link_new_tab' => true, 'width' => '50%'];
            $page->data = $this->document($props, $type);
            $html = $renderer->render($page, 'fr')->getHtml();
            self::assertStringContainsString('href="/fr/nav/parent/child"', $html);
            self::assertStringContainsString('target="_blank" rel="noopener noreferrer"', $html);
            self::assertStringNotContainsString('data-page-image-lightbox', $html);
            if ($type === 'core.image') {
                self::assertStringContainsString('max-width:100%;width:50%;', $html);
                self::assertStringContainsString('style="width:100%;"', $html);
            }
        }
    }

    public function testUnavailableDestinationsKeepVisibleContentWithoutAnAnchor(): void
    {
        [$link, , , $services] = $this->fixture();
        $renderer = new PageRenderer(PageFixtures::application($services));
        $page = $this->instance(Page::class);
        $page->id = 1; $page->space_id = 8; $page->type = 2; $page->raw_css = null;
        foreach (['core.image', 'core.button'] as $type) {
            $page->data = $this->document((object) [
                'click_action' => 'link', 'src' => '/photo.png', 'text' => 'Voir', 'link_type' => 'menu', 'menu_item_id' => 2,
            ], $type);
            $html = $renderer->render($page, 'fr')->getHtml();
            self::assertStringNotContainsString('<a ', $html);
            self::assertStringContainsString($type === 'core.image' ? '<img ' : 'Voir', $html);
        }
    }
}

trait PageLinkTestServices
{
    public array $services = [];
    public function getSharedExtensionsClass(string $className): BaseApplicationItem { return $this->services[$className]; }
}
class PageLinkRoutesStub extends MenuRouteHelper { use PageLinkTestServices; }
class PageLinkWorkflowStub extends WFPage { use PageLinkTestServices; }

#[Controller(name: 'page_link_test_', path: 'auth')]
class PageLinkTechnicalController extends PageController
{
    #[ControllerMethod(name: 'login', path: 'login')]
    public function login(): void {}
    #[ControllerMethod(name: 'parameter', path: '{id}')]
    public function parameter(): void {}
}
