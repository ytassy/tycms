<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use ReflectionMethod;
use ReflectionProperty;
use YTassy\Tycms\Application;
use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\CoreExts\Core\Helper\Acl\AclHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Space\SpaceHelper;
use YTassy\Tycms\Lib\Forms\FormFactory;
use YTassy\Tycms\Lib\Forms\FormFieldType;
use YTassy\Tycms\CoreExts\Core\Helper\Space\FormsHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Forms\FormTypesHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Menu\MenuAdminHelper;
use YTassy\Tycms\CoreExts\Core\Models\Space;
use YTassy\Tycms\CoreExts\Core\WF\WFSpace;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class SpaceSsoFormTest extends FrameworkTestCase
{
    public function testNewTargetChoicesRequireAdministrationRightsOnTheTarget(): void
    {
        $app = $this->createApplication();
        $factory = $app->getOrAddSharedExtensionsClass(FormFactory::class);
        (new FormTypesHelper($app))->registerRichTextFieldType();
        $factory->registerType(new FormFieldType('core.media_file', 'forms/media-file',
            implicitDefault: '', valueSchema: ['type' => 'string']));
        $factory->registerType(new FormFieldType(
            'core.timezone', 'forms/select', acceptsOptions: true,
            implicitDefault: 'Europe/Paris', defaultOptions: ['Europe/Paris' => 'Europe/Paris']
        ));
        $root = new Space($app);
        $root->id = 1;
        $root->title = 'Racine';
        $site = new Space($app);
        $site->id = 2;
        $site->title = 'Site';
        $spaces = $this->createStub(SpaceHelper::class);
        $spaces->method('getAllSpacesByIds')->willReturn([1 => $root, 2 => $site]);
        $acl = $this->createStub(AclHelper::class);
        $acl->method('hasGlobalPermission')->willReturnCallback(
            static fn ($permission, $spaceId): bool => $spaceId === 2
        );
        new ReflectionProperty(Application::class, 'sharedExtensionsClasses')->setValue($app, [
            FormFactory::class => $factory, SpaceHelper::class => $spaces, AclHelper::class => $acl,
        ]);

        $definition = new FormsHelper($app)->getSpaceForm(null, true, false)->getDefinition();
        $fields = array_merge(...array_column($definition['sections'], 'fields'));
        self::assertArrayHasKey('medias_dir', array_column($fields, null, 'name'));
        $target = array_column($fields, null, 'name')['registration_target_space_id'];
        self::assertSame([null, 2], array_column($target['options'], 'value'));
        self::assertTrue(new FormsHelper($app)->getSpaceForm(null, true, false)
            ->getDefaultValues()['registration_auto_member_role']);
    }

    public function testOnlyTheRootSpaceCanRemainWithoutAParent(): void
    {
        $app = $this->createApplication();
        $root = new Space($app);
        $root->id = 1;
        $root->title = 'Racine';
        $site = new Space($app);
        $site->id = 2;
        $site->title = 'Site';
        $spaces = $this->createStub(SpaceHelper::class);
        $spaces->method('getAllSpacesByIds')->willReturn([1 => $root, 2 => $site]);
        $acl = $this->createStub(AclHelper::class);
        $acl->method('hasGlobalPermission')->willReturn(true);
        new ReflectionProperty(Application::class, 'sharedExtensionsClasses')->setValue($app, [
            SpaceHelper::class => $spaces, AclHelper::class => $acl,
        ]);
        $parentField = static fn (?Space $space): array => (new ReflectionMethod(FormsHelper::class, 'getParentField'))
            ->invoke(new FormsHelper($app), $space);

        // Création : aucun « Aucun parent », un parent doit être choisi.
        $create = $parentField(null);
        self::assertSame([1, 2], array_column($create['options'], 'value'));
        self::assertTrue($create['required']);
        self::assertSame('Choisir un espace parent', $create['placeholder']);

        // Racine : seul le choix vide reste possible et il n'est pas obligatoire.
        $editRoot = $parentField($root);
        self::assertSame([0], array_column($editRoot['options'], 'value'));
        self::assertFalse($editRoot['required']);
        self::assertArrayNotHasKey('placeholder', $editRoot);

        // Modification d'un autre Space : son parent reste obligatoire, sans lui-même.
        $editSite = $parentField($site);
        self::assertSame([1], array_column($editSite['options'], 'value'));
        self::assertTrue($editSite['required']);
    }

    public function testExistingBooleanSettingIsShownAsNumber(): void
    {
        $app = $this->createApplication();
        $space = new Space($app);
        $space->id = 2;
        $space->parent_id = 1;
        $space->template = 'Main';
        $space->medias_dir = 'space-test';
        $space->hosts = [];
        $space->title = 'Espace de test';
        $space->params = (object) ['sso_auto_link_existing_account' => true];

        $values = new ReflectionMethod(WFSpace::class, 'getFormValues')
            ->invoke(new WFSpace($app), $space);

        self::assertSame(1, $values['sso_auto_link_existing_account']);
        self::assertTrue($values['registration_auto_member_role']);
    }

    public function testProviderIdsAreRejectedWithoutSpaceAdmin(): void
    {
        $app = $this->createApplication();
        $factory = $app->getOrAddSharedExtensionsClass(FormFactory::class);
        (new FormTypesHelper($app))->registerRichTextFieldType();
        $factory->registerType(new FormFieldType('core.media_file', 'forms/media-file',
            implicitDefault: '', valueSchema: ['type' => 'string']));
        $factory->registerType(new FormFieldType(
            'core.timezone', 'forms/select', acceptsOptions: true,
            implicitDefault: 'Europe/Paris',
            defaultOptions: ['Europe/Paris' => 'Europe/Paris']
        ));

        $menuHelper = $this->createStub(MenuAdminHelper::class);
        $menuHelper->method('getMenusAndItemsOfSpace')->willReturn([]);
        $formsHelper = new class($app, $menuHelper) extends FormsHelper {
            public function __construct($app, private MenuAdminHelper $menuHelper)
            {
                parent::__construct($app);
            }

            public function getSharedExtensionsClass(string $className): BaseApplicationItem
            {
                return $className === MenuAdminHelper::class
                    ? $this->menuHelper
                    : parent::getSharedExtensionsClass($className);
            }
        };
        $space = new Space($app);
        $space->id = 2;
        $form = $formsHelper->getSpaceForm($space, false, true);
        $schema = $form->getSubmissionSchema();
        self::assertArrayNotHasKey('sso_provider_ids', $schema['properties']);
        self::assertArrayNotHasKey('medias_dir', $schema['properties']);
        self::assertArrayNotHasKey('sso_allow_reset_local_password', $schema['properties']);
        self::assertArrayNotHasKey('registration_target_space_id', $schema['properties']);
        self::assertArrayNotHasKey('registration_auto_member_role', $schema['properties']);
        self::assertArrayHasKey('registration_enabled', $schema['properties']);
        self::assertArrayHasKey('sso_auto_link_existing_account', $schema['properties']);
        self::assertSame(['enum' => [0, 1, 2]], $schema['properties']['sso_auto_link_existing_account']);

        $values = $form->getDefaultValues();
        $values['title'] = 'Espace de test';
        $values['sso_provider_ids'] = [42];
        $result = $form->validateSubmittedValues((object) $values);

        self::assertFalse($result->isValid());
        self::assertContains('/sso_provider_ids', array_map(
            static fn ($error) => $error->path,
            array_filter($result->getErrors(), static fn ($error) => $error->keyword === 'additionalProperties')
        ));

        unset($values['sso_provider_ids']);
        $values['medias_dir'] = 'other-space';
        self::assertFalse($form->validateSubmittedValues((object) $values)->isValid());
        unset($values['medias_dir']);
        $values['sso_allow_reset_local_password'] = true;
        self::assertFalse($form->validateSubmittedValues((object) $values)->isValid());
        unset($values['sso_allow_reset_local_password']);
        $values['registration_target_space_id'] = 1;
        self::assertFalse($form->validateSubmittedValues((object) $values)->isValid());
        unset($values['registration_target_space_id']);
        $values['registration_auto_member_role'] = false;
        self::assertFalse($form->validateSubmittedValues((object) $values)->isValid());
        unset($values['registration_auto_member_role']);
        foreach ([0, 1, 2] as $mode) {
            $values['sso_auto_link_existing_account'] = $mode;
            self::assertTrue($form->validateSubmittedValues((object) $values)->isValid());
        }
        foreach ([true, 3] as $invalidMode) {
            $values['sso_auto_link_existing_account'] = $invalidMode;
            self::assertFalse($form->validateSubmittedValues((object) $values)->isValid());
        }
    }
}
