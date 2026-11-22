<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Core\Controllers\PageController;
use YTassy\Tycms\DB\Database;
use YTassy\Tycms\Routing\Route;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageRenderMode;
use YTassy\Tycms\CoreExts\Core\Helper\Acl\AclHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Page\Renderer\PageRenderer;
use YTassy\Tycms\CoreExts\Core\Models\Page;
use YTassy\Tycms\CoreExts\Core\WF\WFPage;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Exceptions\NotFoundException;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class PageStatusTest extends FrameworkTestCase
{
    public static function unpublishedStatuses(): iterable
    {
        yield [0];
        yield [-1];
        yield [-2];
    }

    #[DataProvider('unpublishedStatuses')]
    public function testUnpublishedPageIsEmptyPubliclyButCanBePreviewed(int $status): void
    {
        $page = new Page($this->createApplication());
        $page->id = 7;
        $page->status = $status;
        $page->type = 1;
        $page->raw_html = '<p>Contenu</p>';
        $page->raw_css = 'p { color: red; }';
        $renderer = new PageRenderer($this->createApplication());

        self::assertSame('', $renderer->render($page)->getHtml());
        self::assertStringContainsString('Contenu', $renderer->render($page, null, PageRenderMode::PREVIEW)->getHtml());
    }

    public function testMissingPageProducesEmptyFooterAnd404ForMenu(): void
    {
        $app = $this->createApplication();
        $database = $this->createStub(Database::class);
        $database->method('selectOrmOneOrNull')->willReturn(null);
        (new \ReflectionProperty(Application::class, 'database'))->setValue($app, $database);
        $helper = new PageHelper($app);
        self::assertSame('', $helper->renderPage(42)->getHtml());

        $controller = new PageStatusControllerStub($app);
        $controller->helper = $helper;
        $route = (new \ReflectionClass(Route::class))->newInstanceWithoutConstructor();
        $this->expectException(NotFoundException::class);
        $controller->page($route, ['menu_item' => (object) ['params' => (object) ['page_id' => 42]], 'menu' => null]);
    }

    public function testMenuEntryDoesNotRenderUnpublishedPage(): void
    {
        $app = $this->createApplication();
        $page = new Page($app);
        $page->id = 7;
        $page->space_id = 2;
        $page->status = 0;
        $helper = $this->createStub(PageHelper::class);
        $helper->method('getPage')->willReturnCallback(
            static fn (int $id): ?Page => $id === 7 ? $page : null
        );
        $controller = new PageStatusControllerStub($app);
        $controller->helper = $helper;
        $route = (new \ReflectionClass(Route::class))->newInstanceWithoutConstructor();

        $this->expectException(NotFoundException::class);
        $controller->page($route, [
            'menu_item' => (object) ['params' => (object) ['page_id' => 7]],
            'menu' => (object) ['space_id' => 2],
        ]);
    }

    public function testMenuEntryDoesNotRenderPageFromAnotherSpace(): void
    {
        $app = $this->createApplication();
        $page = new Page($app);
        $page->id = 7;
        $page->space_id = 1;
        $page->status = 1;
        $helper = $this->createStub(PageHelper::class);
        $helper->method('getPage')->willReturnCallback(
            static fn (int $id): ?Page => $id === 7 ? $page : null
        );
        $controller = new PageStatusControllerStub($app);
        $controller->helper = $helper;
        $route = (new \ReflectionClass(Route::class))->newInstanceWithoutConstructor();

        $this->expectException(NotFoundException::class);
        $controller->page($route, [
            'menu_item' => (object) ['params' => (object) ['page_id' => 7]],
            'menu' => (object) ['space_id' => 2],
        ]);
    }

    public function testDeleteRequiresTrash(): void
    {
        [$workflow, $helper, $page] = $this->workflow(true);
        $page->status = 1;
        $helper->expects(self::never())->method('deleteTrashedPage');
        $this->expectException(ExceptionResponse::class);
        $workflow->delete($page->id);
    }

    public function testDeleteRequiresPermissionOnRealSpace(): void
    {
        [$workflow, $helper, $page] = $this->workflow(false);
        $page->status = -2;
        $helper->expects(self::never())->method('deleteTrashedPage');
        $this->expectException(ExceptionResponse::class);
        $workflow->delete($page->id);
    }

    public function testOnlyAllowedStatusCanBeWritten(): void
    {
        [$workflow, $helper, $page] = $this->workflow(true);
        $helper->expects(self::never())->method('updateStatus');
        $this->expectException(ExceptionResponse::class);
        $workflow->changeStatus($page->id, '42');
    }

    public function testAllowedStatusIsWritten(): void
    {
        [$workflow, $helper, $page] = $this->workflow(true);
        $helper->expects(self::once())->method('updateStatus')->with($page->id, -1);
        $workflow->changeStatus($page->id, '-1');
    }

    public function testTrashedPageCanBeDeleted(): void
    {
        [$workflow, $helper, $page] = $this->workflow(true);
        $page->status = -2;
        $helper->expects(self::once())->method('deleteTrashedPage')->with($page->id);
        $workflow->delete($page->id);
    }

    private function workflow(bool $canEdit): array
    {
        $app = $this->createApplication();
        $page = new Page($app);
        $page->id = 42;
        $page->space_id = 8;
        $page->type = 2;
        $helper = $this->createMock(PageHelper::class);
        $helper->expects(self::once())->method('getPageForUpdate')->with(42)->willReturn($page);
        $acl = $this->createStub(AclHelper::class);
        $acl->method('hasGlobalPermission')->willReturnCallback(
            static fn (string $permission, int $spaceId): bool => $spaceId === 8
                && ($permission === Permissions::PAGE_READ || ($permission === Permissions::PAGE_CRUD && $canEdit))
        );
        $workflow = new PageStatusWorkflowStub($app);
        $workflow->services = [PageHelper::class => $helper, AclHelper::class => $acl];
        return [$workflow, $helper, $page];
    }
}

class PageStatusWorkflowStub extends WFPage
{
    public array $services = [];

    public function getSharedExtensionsClass(string $className): BaseApplicationItem
    {
        return $this->services[$className];
    }
}

class PageStatusControllerStub extends PageController
{
    public PageHelper $helper;

    public function getSharedExtensionsClass(string $className): BaseApplicationItem
    {
        return $this->helper;
    }
}
