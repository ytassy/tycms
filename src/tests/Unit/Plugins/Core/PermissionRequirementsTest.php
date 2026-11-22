<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use InvalidArgumentException;
use ReflectionMethod;
use ReflectionProperty;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Automation\Permissions as AutomationPermissions;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\PermDef;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\PermissionRequirements;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\CoreExts\Core\Helper\Acl\AclHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Acl\RoleAdminHelper;
use YTassy\Tycms\CoreExts\Core\WF\WFRole;
use YTassy\Tycms\DB\Database;
use YTassy\Tycms\Exceptions\TechException;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class PermissionRequirementsTest extends FrameworkTestCase
{
    public function testCoreAnnotationsCoverRootWithoutGivingSpaceAdminSensitivePermissions(): void
    {
        $catalog = $this->catalog();
        $graph = new PermissionRequirements($catalog);
        $root = $graph->forPermission(Permissions::ROOT);
        $all = array_values(array_diff(array_keys($catalog['plg_core']), [Permissions::ROOT]));
        sort($all);
        self::assertSame($all, $root);
        self::assertContains(Permissions::MEMBER, $root);
        self::assertContains(Permissions::GROUP_ROLES_ASSIGN, $root);

        foreach ([Permissions::ROOT, Permissions::USERS_ADMIN, Permissions::SSO_CRUD,
            Permissions::EXTENSION_EDIT, Permissions::FONT_MANAGE] as $sensitive) {
            self::assertNotContains($sensitive, $graph->forPermission(Permissions::SPACE_ADMIN));
        }
        self::assertNotContains(Permissions::USERS_READ, $graph->forPermission(Permissions::USER_ROLES_READ));
    }

    public function testEachRoleMustContainItsOwnTransitiveRequirements(): void
    {
        $graph = new PermissionRequirements($this->catalog());
        self::assertSame([Permissions::MENU_CRUD => [Permissions::MENU_READ, Permissions::PAGE_READ]],
            $graph->missingFrom([Permissions::MENU_CRUD]));
        self::assertSame([], $graph->missingFrom([Permissions::MENU_READ, Permissions::PAGE_READ]));
        self::assertSame([], $graph->missingFrom([Permissions::MENU_CRUD, Permissions::MENU_READ, Permissions::PAGE_READ]));

        $roles = $graph->forPermission(Permissions::USER_ROLES_CRUD);
        self::assertContains(Permissions::ROLE_READ, $roles);
        self::assertContains(Permissions::USER_ROLES_READ, $roles);
        self::assertSame([], $graph->missingFrom([Permissions::USER_ROLES_CRUD, ...$roles]));
    }

    public function testAutomationPublicationDoesNotRequireEditing(): void
    {
        $graph = new PermissionRequirements($this->catalog());
        self::assertSame([AutomationPermissions::VIEW], $graph->forPermission(AutomationPermissions::PUBLISH));
        self::assertSame([AutomationPermissions::PUBLISH, AutomationPermissions::VIEW],
            $graph->forPermission(AutomationPermissions::RUN_AS_AUTHOR));
        self::assertSame([AutomationPermissions::RUN, AutomationPermissions::VIEW],
            $graph->forPermission(AutomationPermissions::RUN_MANAGE));
    }

    public function testUnknownDependencyIsRejectedEvenIfNoRoleUsesIt(): void
    {
        $this->expectException(TechException::class);
        $this->expectExceptionMessage('Prérequis de permission inconnu');
        new PermissionRequirements(['plg_test' => [$this->definition('plg_test__A', ['plg_test__MISSING'])]]);
    }

    public function testCyclesBetweenExtensionsAreRejected(): void
    {
        $this->expectException(TechException::class);
        $this->expectExceptionMessage('Cycle de prérequis');
        new PermissionRequirements([
            'plg_first' => [$this->definition('plg_first__A', ['plg_second__B'])],
            'plg_second' => [$this->definition('plg_second__B', ['plg_first__A'])],
        ]);
    }

    public function testSelfDependencyAndDuplicateDefinitionsAreRejected(): void
    {
        $a = $this->definition('plg_test__A', []);
        try {
            new PermissionRequirements(['plg_test' => [$a, $a]]);
            self::fail('Les doublons doivent être refusés.');
        } catch (TechException $error) {
            self::assertStringContainsString('plusieurs fois', $error->getMessage());
        }

        $this->expectException(TechException::class);
        $this->expectExceptionMessage('Cycle de prérequis');
        new PermissionRequirements(['plg_test' => [$this->definition('plg_test__A', ['plg_test__A'])]]);
    }

    public function testUnloadedLegacyPermissionCanBeKeptWithoutInventingDependencies(): void
    {
        $graph = new PermissionRequirements($this->catalog());
        self::assertSame([], $graph->missingFrom(['plg_absent__LEGACY', Permissions::PAGE_READ]));
    }

    public function testRegisteringAnAdditionalCatalogInvalidatesTheGraph(): void
    {
        $app = $this->createApplication();
        $db = $this->createStub(Database::class);
        $db->method('selectOrm')->willReturn([]);
        new ReflectionProperty(Application::class, 'database')->setValue($app, $db);
        $acl = new AclHelper($app);
        $acl->definePermissionsDefinition('plg_test', [$this->definition('plg_test__READ', [])]);
        $first = $acl->getPermissionRequirements();

        $acl->definePermissionsDefinition('plg_test', [$this->definition('plg_test__WRITE', ['plg_test__READ'])]);
        self::assertNotSame($first, $acl->getPermissionRequirements());
        self::assertSame(['plg_test__READ'], $acl->getPermissionRequirements()->forPermission('plg_test__WRITE'));
    }

    public function testWorkflowRefusesMissingPrerequisitesEvenWhenEditorHasThem(): void
    {
        $app = $this->applicationWithCatalog();
        $this->expectException(ExceptionResponse::class);
        // Le garde du rôle ne doit jamais interroger les droits effectifs de l'éditeur.
        new ReflectionMethod(WFRole::class, 'validateData')->invoke(new WFRole($app), (object) [
            'name' => 'Éditeur', 'description' => '', 'permissions' => [Permissions::PAGE_CRUD],
        ]);
    }

    public function testWorkflowAcceptsCompleteRoleAndRefusesRemovalOfARequiredPermission(): void
    {
        $wf = new WFRole($this->applicationWithCatalog());
        $validate = new ReflectionMethod(WFRole::class, 'validateData');
        $complete = [Permissions::PAGE_CRUD, Permissions::PAGE_READ];
        [, , $saved] = $validate->invoke($wf, (object) ['name' => 'Éditeur', 'description' => '', 'permissions' => $complete]);
        self::assertSame($complete, $saved);

        $this->expectException(ExceptionResponse::class);
        $validate->invoke($wf, (object) ['name' => 'Éditeur', 'description' => '',
            'permissions' => [Permissions::PAGE_CRUD]], $complete);
    }

    public function testPersistenceRefusesIncompleteRoleBeforeDeletingExistingPermissions(): void
    {
        $app = $this->applicationWithCatalog();
        $db = $this->createMock(Database::class);
        $db->expects(self::never())->method('execute');
        new ReflectionProperty(Application::class, 'database')->setValue($app, $db);

        $this->expectException(InvalidArgumentException::class);
        (new RoleAdminHelper($app))->replacePermissions(123, [Permissions::PAGE_CRUD]);
    }

    private function applicationWithCatalog(): Application
    {
        $app = $this->createApplication();
        $catalog = $this->catalog();
        $acl = $this->createMock(AclHelper::class);
        $acl->method('getAllPermissionsDefinitions')->willReturn($catalog);
        $acl->method('getPermissionRequirements')->willReturn(new PermissionRequirements($catalog));
        $acl->expects(self::never())->method('hasGlobalPermission');
        new ReflectionProperty(Application::class, 'sharedExtensionsClasses')->setValue($app, [AclHelper::class => $acl]);

        return $app;
    }

    private function catalog(): array
    {
        $app = $this->createApplication();
        $index = static fn (array $definitions): array => array_column($definitions, null, 'name');

        return [
            'plg_core' => $index((new Permissions($app))->getPermissions()),
            'plg_automation' => $index((new AutomationPermissions($app))->getPermissions()),
        ];
    }

    private function definition(string $name, array $requirements): PermDef
    {
        $definition = new PermDef($name, '');
        $definition->name = $name;
        $definition->requiresPermissions = $requirements;

        return $definition;
    }
}
