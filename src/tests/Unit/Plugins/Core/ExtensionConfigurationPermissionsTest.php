<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use ReflectionProperty;
use PHPUnit\Framework\Attributes\DataProvider;
use YTassy\Tycms\Application;
use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\Exceptions\AccessDeniedException;
use YTassy\Tycms\CoreExts\Core\Helper\Acl\AclHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Admin\ExtensionsHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Space\SpaceHelper;
use YTassy\Tycms\CoreExts\Core\Models\Space;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\CoreExts\Core\WF\Admin\WFExtensions;
use YTassy\Tycms\CoreExts\Core\Plugin as AppPlugin;
use YTassy\Tycms\CoreExts\Security\Plugin as SecurityPlugin;
use YTassy\Tycms\CoreExts\MainTemplate\Template;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class ExtensionConfigurationPermissionsTest extends FrameworkTestCase
{
    public function testHlsConfigurationRequiresSpaceAdminEvenForAnExtensionEditor(): void
    {
        $app = $this->createApplication();
        $plugin = new AppPlugin($app);
        (new ReflectionProperty(Application::class, 'extensions'))->setValue($app, ['plg_core' => $plugin]);

        $acl = $this->createStub(AclHelper::class);
        $acl->method('hasGlobalPermission')->willReturnCallback(
            static fn (string $permission): bool => $permission === Permissions::EXTENSION_EDIT,
        );
        $extensionsHelper = $this->createStub(ExtensionsHelper::class);
        $extensionsHelper->method('getExtensionsConfForSpaces')->willReturn([
            'plg_core' => [1 => (object) ['params' => (object) [
                'video_hls_libraries' => '{"bib1":"/tmp"}',
                'video_hls_password' => 'secret',
            ]]],
        ]);
        (new ReflectionProperty(Application::class, 'sharedExtensionsClasses'))
            ->setValue($app, [AclHelper::class => $acl, ExtensionsHelper::class => $extensionsHelper]);
        self::assertSame(['hls'], $plugin->getConfigHiddenSections(1));
        $visible = $plugin->prepareConfigForEditor(1, [
            'video_hls_libraries' => '{"bib1":"/tmp"}',
            'video_hls_password' => 'secret',
        ]);
        self::assertArrayNotHasKey('video_hls_libraries', $visible);
        self::assertArrayNotHasKey('video_hls_password', $visible);
        $preserved = $plugin->prepareConfigForStorage(1, ['video_bunny_tokens' => '{}']);
        self::assertSame('{"bib1":"/tmp"}', $preserved['video_hls_libraries']);
        self::assertSame('secret', $preserved['video_hls_password']);
        $workflow = new class($app, $acl) extends WFExtensions {
            public function __construct(Application $app, private AclHelper $acl)
            {
                parent::__construct($app);
            }

            public function getSharedExtensionsClass(string $className): BaseApplicationItem
            {
                return $className === AclHelper::class ? $this->acl : parent::getSharedExtensionsClass($className);
            }
        };

        foreach (['video_hls_libraries', 'video_hls_password', 'video_hls_password_encrypted'] as $field) {
            try {
                $workflow->saveConfiguration(1, 'plg_core', (object) [$field => 'interdit']);
                self::fail('Le paramètre HLS a été accepté sans SPACE_ADMIN : ' . $field);
            } catch (AccessDeniedException) {
                self::assertTrue(true);
            }
        }
    }

    public function testDefaultsAndConfigurationListRespectEachExtensionPermission(): void
    {
        $app = $this->createApplication();
        $security = new SecurityPlugin($app);
        $template = new Template($app);
        $custom = new class($app) extends SecurityPlugin {
            public function getConfigEditPermissions(): array
            {
                return ['plg_core__CUSTOM_CONFIG', Permissions::TEMPLATE_EDIT];
            }
        };
        (new ReflectionProperty(Application::class, 'extensions'))->setValue($app, [
            'plg_security' => $security,
            'tpl_main' => $template,
            'plg_custom' => $custom,
        ]);

        self::assertSame([Permissions::ROOT], $security->getConfigEditPermissions());
        self::assertSame([Permissions::TEMPLATE_EDIT], $template->getConfigEditPermissions());
        self::assertSame(['plg_core__CUSTOM_CONFIG', Permissions::TEMPLATE_EDIT], $custom->getConfigEditPermissions());

        $acl = $this->createStub(AclHelper::class);
        $acl->method('getSpacesHavingGlobalPermission')->willReturnCallback(
            static fn (array $permissions): array => match ($permissions) {
                [Permissions::ROOT] => [1],
                [Permissions::TEMPLATE_EDIT] => [2],
                ['plg_core__CUSTOM_CONFIG', Permissions::TEMPLATE_EDIT] => [2],
                default => [],
            },
        );
        $acl->method('hasGlobalPermission')->willReturnCallback(
            static fn (string $permission, int $spaceId): bool =>
                ($permission === Permissions::ROOT && $spaceId === 1)
                || ($permission === Permissions::TEMPLATE_EDIT && $spaceId === 2),
        );

        $spaceHelper = $this->createStub(SpaceHelper::class);
        $spaceHelper->method('getSpacesByIds')->willReturn([
            1 => $this->space($app, 1),
            2 => $this->space($app, 2),
        ]);

        $extensionsHelper = $this->createStub(ExtensionsHelper::class);
        $extensionsHelper->method('getFormsByExtension')->willReturn([]);
        $extensionsHelper->method('getExtensionsConfForSpaces')->willReturn([
            'plg_security' => [1 => ['secret' => 'plugin'], 2 => ['secret' => 'hidden']],
            'tpl_main' => [1 => ['secret' => 'hidden'], 2 => ['secret' => 'template']],
        ]);

        $workflow = new class($app, $acl, $spaceHelper, $extensionsHelper) extends WFExtensions {
            public function __construct(
                Application $app,
                private AclHelper $acl,
                private SpaceHelper $spaces,
                private ExtensionsHelper $extensions,
            ) {
                parent::__construct($app);
            }

            public function getSharedExtensionsClass(string $className): BaseApplicationItem
            {
                return match ($className) {
                    AclHelper::class => $this->acl,
                    SpaceHelper::class => $this->spaces,
                    ExtensionsHelper::class => $this->extensions,
                    default => parent::getSharedExtensionsClass($className),
                };
            }
        };

        $data = $workflow->getDatas();
        self::assertSame([1], $data['extensions']['plg_security']['space_ids']);
        self::assertSame([2], $data['extensions']['tpl_main']['space_ids']);
        self::assertSame([2], $data['extensions']['plg_custom']['space_ids']);
        self::assertSame([1], array_keys($data['extensions']['plg_security']['conf_by_spaces']));
        self::assertSame([2], array_keys($data['extensions']['tpl_main']['conf_by_spaces']));
        // Le Core transmet seulement l’identifiant logique des vues spécialisées.
        self::assertSame('security/config', $data['extensions']['plg_security']['config_view']);
        self::assertSame('main-template/config', $data['extensions']['tpl_main']['config_view']);

        // Un droit sur l'autre extension ne permet pas d'écrire sa configuration.
        try {
            $workflow->saveConfiguration(1, 'tpl_main', (object) []);
            self::fail('La configuration de Template ne doit pas accepter EXTENSION_EDIT.');
        } catch (AccessDeniedException) {
            self::assertTrue(true);
        }

        $this->expectException(AccessDeniedException::class);
        $workflow->saveConfiguration(2, 'plg_security', (object) []);
    }

    public function testSecurityRejectsExtensionEditorsAndAllowsRootToSave(): void
    {
        foreach ([false, true] as $root) {
            $app = $this->createApplication();
            $plugin = new SecurityPlugin($app);
            (new ReflectionProperty(Application::class, 'extensions'))->setValue($app, ['plg_security' => $plugin]);

            // Le même compte conserve EXTENSION_EDIT ; seul ROOT change entre les deux cas.
            $acl = $this->createStub(AclHelper::class);
            $acl->method('hasGlobalPermission')->willReturnCallback(
                static fn (string $permission, int $spaceId): bool => $spaceId === 1
                    && ($permission === Permissions::EXTENSION_EDIT || ($root && $permission === Permissions::ROOT)),
            );
            $acl->method('getSpacesHavingGlobalPermission')->willReturnCallback(
                static fn (array $permissions): array => $root && $permissions === [Permissions::ROOT] ? [1] : [],
            );
            $spaces = $this->createStub(SpaceHelper::class);
            $spaces->method('getSpacesByIds')->willReturn([1 => $this->space($app, 1)]);

            $extensions = $this->createMock(ExtensionsHelper::class);
            $extensions->method('getFormsByExtension')->willReturn(['plg_security' => $plugin->coreRegisterConfigForm()]);
            $extensions->method('getExtensionsConfForSpaces')->willReturn([]);
            $extensions->expects($root ? self::once() : self::never())->method('saveExtensionConfig')
                ->with(1, 'plg_security', \YTassy\Tycms\CoreExts\Security\Configuration::defaults());
            (new ReflectionProperty(Application::class, 'sharedExtensionsClasses'))->setValue($app, [
                AclHelper::class => $acl, SpaceHelper::class => $spaces, ExtensionsHelper::class => $extensions,
            ]);
            $workflow = new WFExtensions($app);
            self::assertSame($root, $workflow->canEditAnyConfiguration());

            if ($root) {
                self::assertArrayHasKey('plg_security', $workflow->getDatas()['extensions']);
                $saved = $workflow->saveConfiguration(1, 'plg_security', (object) \YTassy\Tycms\CoreExts\Security\Configuration::defaults());
                self::assertSame(\YTassy\Tycms\CoreExts\Security\Configuration::defaults(), $saved['params']);
                continue;
            }

            // Les lectures comme les écritures directes sont refusées avant toute persistance.
            foreach (['read', 'save'] as $action) {
                try {
                    if ($action === 'read') $workflow->getDatas();
                    else $workflow->saveConfiguration(1, 'plg_security', (object) \YTassy\Tycms\CoreExts\Security\Configuration::defaults());
                    self::fail('La configuration Sécurité exige ROOT : ' . $action);
                } catch (AccessDeniedException) {
                    self::assertTrue(true);
                }
            }
        }
    }

    public static function nonRootPermissions(): iterable
    {
        yield 'aucune permission' => [[]];
        yield 'éditeur extensions' => [[Permissions::EXTENSION_EDIT]];
        yield 'administrateur de Space' => [[Permissions::SPACE_ADMIN]];
        yield 'administrateur avec tous les droits éditoriaux' => [[
            Permissions::EXTENSION_EDIT, Permissions::SPACE_ADMIN, Permissions::SPACE_EDIT,
            Permissions::TEMPLATE_EDIT, Permissions::PAGE_READ, Permissions::PAGE_CRUD,
        ]];
    }

    #[DataProvider('nonRootPermissions')]
    public function testSecurityConfigurationCannotBeModifiedThroughDirectApiWorkflowWithoutRoot(array $permissions): void
    {
        $app = $this->createApplication();
        (new ReflectionProperty(Application::class, 'extensions'))->setValue($app, ['plg_security' => new SecurityPlugin($app)]);
        $acl = $this->createStub(AclHelper::class);
        $acl->method('hasGlobalPermission')->willReturnCallback(
            static fn (string $permission): bool => in_array($permission, $permissions, true),
        );
        $extensions = $this->createMock(ExtensionsHelper::class);
        // Le refus doit précéder la lecture du formulaire, des données et toute tentative d’écriture.
        $extensions->expects(self::never())->method('getFormsByExtension');
        $extensions->expects(self::never())->method('getExtensionsConfForSpaces');
        $extensions->expects(self::never())->method('saveExtensionConfig');
        (new ReflectionProperty(Application::class, 'sharedExtensionsClasses'))->setValue($app, [
            AclHelper::class => $acl, ExtensionsHelper::class => $extensions,
        ]);
        $params = (object) [...\YTassy\Tycms\CoreExts\Security\Configuration::defaults(),
            'iframe_mode' => 'allowlist', 'iframe_origins' => 'https://attacker.example'];

        $this->expectException(AccessDeniedException::class);
        (new WFExtensions($app))->saveConfiguration(1, 'plg_security', $params);
    }

    public function testInvalidConfigViewIdentifierIsRejectedBeforeRendering(): void
    {
        $app = $this->createApplication();
        $invalid = new class($app) extends SecurityPlugin {
            public function getConfigViewComponentName(): ?string
            {
                return '../config';
            }
        };
        (new ReflectionProperty(Application::class, 'extensions'))->setValue($app, ['plg_invalid' => $invalid]);

        $acl = $this->createStub(AclHelper::class);
        $acl->method('getSpacesHavingGlobalPermission')->willReturn([1]);
        $spaces = $this->createStub(SpaceHelper::class);
        $spaces->method('getSpacesByIds')->willReturn([1 => $this->space($app, 1)]);
        $extensions = $this->createStub(ExtensionsHelper::class);
        $extensions->method('getFormsByExtension')->willReturn([]);
        $extensions->method('getExtensionsConfForSpaces')->willReturn([]);
        (new ReflectionProperty(Application::class, 'sharedExtensionsClasses'))->setValue($app, [
            AclHelper::class => $acl, SpaceHelper::class => $spaces, ExtensionsHelper::class => $extensions,
        ]);

        $this->expectException(\RuntimeException::class);
        (new WFExtensions($app))->getDatas();
    }

    private function space(Application $app, int $id): Space
    {
        $space = new Space($app);
        $space->id = $id;
        $space->parent_id = null;
        $space->template = 'tpl_main';
        $space->medias_dir = '';
        $space->hosts = [];
        $space->title = "Space $id";
        $space->params = [];
        $space->menu_item_home_id = null;
        $space->menu_item_user_space_id = null;

        return $space;
    }
}
