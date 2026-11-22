<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\CoreExts\Core\Helper\Admin\AdminHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Forms\FormTypesHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Fonts\FontHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Space\SpaceHelper;
use YTassy\Tycms\CoreExts\MainTemplate\Config as MainTemplateConfig;
use YTassy\Tycms\Lib\Forms\Form;
use YTassy\Tycms\Lib\Forms\FormFactory;
use YTassy\Tycms\Lib\Forms\FormOptionContext;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class PublishedPageFieldTest extends FrameworkTestCase
{
    public function testPageCatalogueFiltersOptionsOnFormSpace(): void
    {
        $app = $this->createApplication();
        $factory = $app->getOrAddSharedExtensionsClass(FormFactory::class);
        $spaces = $this->createStub(SpaceHelper::class);
        $spaces->method('getAllSpacesByIds')->willReturn([
            1 => (object) ['title' => 'Site'],
            2 => (object) ['title' => 'Autre'],
        ]);
        $admin = $this->createStub(AdminHelper::class);
        $admin->method('getMenusICanlinkForEditors')->willReturn([]);
        $admin->method('getPagesICanlinkForEditors')->willReturn([
            (object) ['id' => 1, 'space_id' => 1, 'name' => 'Visible', 'status' => 1],
            (object) ['id' => 5, 'space_id' => 2, 'name' => 'Autre', 'status' => 1],
        ]);
        $helper = new PublishedPageFieldHelperStub($app);
        $fonts = $this->createStub(FontHelper::class);
        $fonts->method('options')->willReturn(['arial' => 'Arial']);
        $helper->services = [FormFactory::class => $factory, SpaceHelper::class => $spaces,
            AdminHelper::class => $admin, FontHelper::class => $fonts];
        $helper->registerCoreFieldTypes();

        $published = $factory->getType('catalog.core_page_item');
        $context = new FormOptionContext(new Form($app), [], [], ['spaceId' => 2]);
        self::assertSame([5], array_keys($published->getDefaultOptions($context)));
    }

    public function testPageCataloguesFilterOptionsAndValidationByStatus(): void
    {
        $app = $this->createApplication();
        $factory = $app->getOrAddSharedExtensionsClass(FormFactory::class);
        $spaces = $this->createStub(SpaceHelper::class);
        $spaces->method('getAllSpacesByIds')->willReturn([1 => (object) ['title' => 'Site']]);
        $admin = $this->createStub(AdminHelper::class);
        $admin->method('getMenusICanlinkForEditors')->willReturn([(object) ['id' => 1, 'space_id' => 1, 'name' => 'Menu', 'items' => []]]);
        $admin->method('getPagesICanlinkForEditors')->willReturn([
            (object) ['id' => 1, 'space_id' => 1, 'name' => 'Visible', 'status' => 1],
            (object) ['id' => 2, 'space_id' => 1, 'name' => 'Masquée', 'status' => 0],
            (object) ['id' => 3, 'space_id' => 1, 'name' => 'Archivée', 'status' => -1],
            (object) ['id' => 4, 'space_id' => 1, 'name' => 'Corbeille', 'status' => -2],
        ]);
        $helper = new PublishedPageFieldHelperStub($app);
        $fonts = $this->createStub(FontHelper::class);
        $fonts->method('options')->willReturn(['arial' => 'Arial']);
        $helper->services = [FormFactory::class => $factory, SpaceHelper::class => $spaces, AdminHelper::class => $admin, FontHelper::class => $fonts];
        $helper->registerCoreFieldTypes();

        $published = $factory->getType('catalog.core_page_item');
        $all = $factory->getType('catalog.core_all_page_item');
        $context = new FormOptionContext(new Form($app), [], []);
        self::assertSame([1, null], $published->getValueSchema([])['enum']);
        self::assertSame([1], $published->getValueSchema(['required' => true])['enum']);
        self::assertSame([1, 2, 3, 4], $all->getValueSchema([])['enum']);
        self::assertCount(1, $published->getDefaultOptions($context));
        self::assertCount(4, $all->getDefaultOptions($context));

        // Les champs facultatifs acceptent null sans accepter une page non publiée.
        $form = (new MainTemplateConfig($app))->getConfigForm();
        self::assertNull($form->getDefaultValues()['main_menu_id']);
        self::assertNull($form->getDefaultValues()['footer_page_id']);
        self::assertTrue($form->validateSubmittedValues((object) ['main_menu_id' => null, 'footer_page_id' => null])->isValid());
        self::assertTrue($form->validateSubmittedValues((object) ['main_menu_id' => 1, 'footer_page_id' => 1])->isValid());
        self::assertFalse($form->validateSubmittedValues((object) ['main_menu_id' => 99])->isValid());
        self::assertFalse($form->validateSubmittedValues((object) ['footer_page_id' => 2])->isValid());
    }
}

class PublishedPageFieldHelperStub extends FormTypesHelper
{
    public array $services = [];

    public function getSharedExtensionsClass(string $className): BaseApplicationItem
    {
        return $this->services[$className];
    }
}
