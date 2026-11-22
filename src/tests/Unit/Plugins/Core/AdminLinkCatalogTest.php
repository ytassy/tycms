<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use PHPUnit\Framework\TestCase;
use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\CoreExts\Core\Helper\Acl\AclHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Admin\AdminHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Menu\MenuHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageHelper;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class AdminLinkCatalogTest extends FrameworkTestCase
{
    public function testCataloguesUseTheirReadPermissionWithoutSpaceFallback(): void
    {
        $app = $this->createApplication();
        $acl = $this->createMock(AclHelper::class);
        $acl->expects(self::exactly(2))->method('getSpacesHavingGlobalPermission')
            ->willReturnCallback(static fn (string|array $permissions): array =>
                $permissions === Permissions::PAGE_READ ? [2] : []);

        $pages = $this->createMock(PageHelper::class);
        $pages->expects(self::once())->method('getPageMetadataByIdForSpace')->with(2)
            ->willReturn([1 => (object) ['id' => 1, 'space_id' => 2]]);

        $menus = $this->createMock(MenuHelper::class);
        $menus->expects(self::never())->method('getMenusAnItemsOfSpace');

        $helper = new AdminLinkCatalogStub($app);
        $helper->services = [
            AclHelper::class => $acl,
            PageHelper::class => $pages,
            MenuHelper::class => $menus,
        ];

        self::assertSame([1], array_column($helper->getPagesICanlinkForEditors(), 'id'));
        self::assertSame([], $helper->getMenusICanlinkForEditors());
    }
}

class AdminLinkCatalogStub extends AdminHelper
{
    /** @var array<class-string, BaseApplicationItem> */
    public array $services = [];

    public function getSharedExtensionsClass(string $className): BaseApplicationItem
    {
        return $this->services[$className];
    }
}
