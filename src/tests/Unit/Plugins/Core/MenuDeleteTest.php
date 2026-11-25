<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use PHPUnit\Framework\TestCase;
use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\CoreExts\Core\Helper\Acl\AclHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Menu\MenuAdminHelper;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\CoreExts\Core\Models\Menu;
use YTassy\Tycms\CoreExts\Core\WF\WFMenu;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class MenuDeleteTest extends FrameworkTestCase
{
    private function workflow(bool $allowed = true, bool $userDefined = true, int $itemsCount = 0): MenuDeleteWorkflowStub
    {
        $menu = new Menu($this->createApplication());
        $menu->id = 12;
        $menu->space_id = 7;
        $menu->user_defined = $userDefined;

        $menus = $this->createMock(MenuAdminHelper::class);
        $menus->expects(self::once())->method('getMenu')->with(12)->willReturn($menu);
        $menus->expects($allowed && $userDefined ? self::once() : self::never())
            ->method('countItems')->with(12)->willReturn($itemsCount);
        $menus->expects($allowed && $userDefined && $itemsCount === 0 ? self::once() : self::never())
            ->method('deleteMenu')->with(12)->willReturn(true);

        $acl = $this->createMock(AclHelper::class);
        $acl->expects(self::once())->method('hasGlobalPermission')
            ->with(Permissions::MENU_CRUD, 7)->willReturn($allowed);

        $workflow = new MenuDeleteWorkflowStub($this->createApplication());
        $workflow->services = [MenuAdminHelper::class => $menus, AclHelper::class => $acl];

        return $workflow;
    }

    public function testEmptyUserMenuIsDeleted(): void
    {
        $this->workflow()->deleteMenu(12);

        self::assertTrue(true);
    }

    public function testNonEmptyMenuIsRejected(): void
    {
        $this->expectException(ExceptionResponse::class);

        $this->workflow(itemsCount: 1)->deleteMenu(12);
    }

    public function testSystemMenuIsRejected(): void
    {
        $this->expectException(ExceptionResponse::class);

        $this->workflow(userDefined: false)->deleteMenu(12);
    }

    public function testPermissionOnRealSpaceIsRequired(): void
    {
        $this->expectException(ExceptionResponse::class);

        $this->workflow(allowed: false)->deleteMenu(12);
    }
}

class MenuDeleteWorkflowStub extends WFMenu
{
    /** @var array<class-string,BaseApplicationItem> */
    public array $services = [];

    public function getSharedExtensionsClass(string $className): BaseApplicationItem
    {
        return $this->services[$className];
    }
}
