<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageHelper;
use YTassy\Tycms\CoreExts\Core\Models\Menu;
use YTassy\Tycms\CoreExts\Core\Models\Page;
use YTassy\Tycms\CoreExts\Core\WF\WFMenu;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class MenuPageTargetTest extends FrameworkTestCase
{
    #[DataProvider('targets')]
    public function testPageMenuItemMustBePublishedInTheMenuSpace(
        ?int $status, int $pageSpaceId, int $menuSpaceId, bool $valid): void
    {
        $app = $this->createApplication();
        $page = $status === null ? null : new Page($app);
        if ($page !== null) {
            $page->id = 7;
            $page->space_id = $pageSpaceId;
            $page->status = $status;
        }

        $helper = $this->createStub(PageHelper::class);
        $helper->method('getPage')->willReturn($page);

        $workflow = new MenuPageTargetStub($app);
        $workflow->services = [PageHelper::class => $helper];
        $menu = new Menu($app);
        $menu->space_id = $menuSpaceId;

        $method = new \ReflectionMethod(WFMenu::class, 'assertPageMenuItemTarget');
        $method->setAccessible(true);
        if (!$valid) $this->expectException(ExceptionResponse::class);

        $method->invoke($workflow, $menu, (object) ['page_id' => 7]);
        self::assertTrue($valid);
    }

    public static function targets(): iterable
    {
        yield 'page absente' => [null, 1, 2, false];
        yield 'page non publiée' => [0, 2, 2, false];
        yield 'page d’un autre Space' => [1, 1, 2, false];
        yield 'page publiée du même Space' => [1, 2, 2, true];
    }
}

class MenuPageTargetStub extends WFMenu
{
    /** @var array<class-string, BaseApplicationItem> */
    public array $services = [];

    public function getSharedExtensionsClass(string $className): BaseApplicationItem
    {
        return $this->services[$className];
    }
}
