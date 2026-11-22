<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\CoreExts\Core\Helper\Menu\MenuHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Space\SpaceHelper;
use YTassy\Tycms\CoreExts\Core\Models\Page;
use YTassy\Tycms\CoreExts\MainTemplate\Template;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class MainTemplateConfigTargetTest extends FrameworkTestCase
{
    public function testTemplateLinksMustStayInTheConfiguredSpace(): void
    {
        $app = $this->createApplication();
        $workflow = new MainTemplateConfigTargetStub($app);
        $menu = (object) ['id' => 10];
        $menus = $this->createStub(MenuHelper::class);
        $menus->method('getMenusAnItemsOfSpace')->willReturnCallback(
            static fn (int $spaceId): array => $spaceId === 2 ? [$menu] : []
        );
        $page = new Page($app);
        $page->id = 7;
        $page->space_id = 2;
        $page->status = 1;
        $pages = $this->createStub(PageHelper::class);
        $pages->method('getPage')->willReturnCallback(
            static fn (int $id): ?Page => $id === 7 ? $page : null
        );
        $spaces = $this->createStub(SpaceHelper::class);
        $spaces->method('getSpaceIdsWithParents')->willReturnCallback(
            static fn (int $spaceId): array => $spaceId === 2 ? [2, 1] : [3]
        );
        $workflow->services = [MenuHelper::class => $menus, PageHelper::class => $pages,
            SpaceHelper::class => $spaces];

        // Le même Space est accepté.
        $workflow->assertConfigSubmissionAllowed(2, (object) ['main_menu_id' => 10, 'footer_page_id' => 7]);

        // Un menu ou une page d'un autre Space est refusé.
        $this->expectException(ExceptionResponse::class);
        $workflow->assertConfigSubmissionAllowed(3, (object) ['main_menu_id' => 10]);
    }

    #[DataProvider('invalidFooterPages')]
    public function testFooterPageMustBePublishedInTheConfiguredSpace(int $status, int $pageSpaceId): void
    {
        $app = $this->createApplication();
        $page = new Page($app);
        $page->id = 7;
        $page->space_id = $pageSpaceId;
        $page->status = $status;
        $pages = $this->createStub(PageHelper::class);
        $pages->method('getPage')->willReturn($page);
        $spaces = $this->createStub(SpaceHelper::class);
        $spaces->method('getSpaceIdsWithParents')->willReturn([2, 1]);
        $workflow = new MainTemplateConfigTargetStub($app);
        $workflow->services = [PageHelper::class => $pages, SpaceHelper::class => $spaces];

        $this->expectException(ExceptionResponse::class);
        $workflow->assertConfigSubmissionAllowed(2, (object) ['footer_page_id' => 7]);
    }

    public static function invalidFooterPages(): iterable
    {
        yield 'page non publiée' => [0, 2];
        yield 'page d’un autre Space' => [1, 3];
    }
}

class MainTemplateConfigTargetStub extends Template
{
    /** @var array<class-string, BaseApplicationItem> */
    public array $services = [];

    public function getSharedExtensionsClass(string $className): BaseApplicationItem
    {
        return $this->services[$className];
    }
}
