<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\CoreExts\Core\Helper\Acl\AclHelper;

/** Vérifie la politique de délégation indépendamment du SQL et de l'interface. */
final class ObjectAclDelegationTest extends TestCase
{
    #[DataProvider('delegationAttempts')]
    public function testManagementPermissionCannotBeUsedToCreateMorePowerfulGrants(
        array $held, array $requested, int $spaceId, int $objectId, bool $expected): void
    {
        $acl = $this->acl($held);
        $acl->expects(self::once())->method('isRoot')->willReturn(false);

        self::assertSame($expected, $acl->canDelegatePermissionsOn(
            'plg_ty_catalog__ACCESS_MANAGE', $requested, 'plg_ty_catalog', 'catalog', $objectId, $spaceId
        ));
    }

    public static function delegationAttempts(): iterable
    {
        // Les droits détenus portent uniquement sur le catalogue 101 du Space 2.
        yield 'gestion seule ne donne pas publication' => [
            ['ACCESS_MANAGE'], ['plg_ty_catalog__PUBLISH'], 2, 101, false,
        ];
        yield 'publication seule ne permet pas de gérer les accès' => [
            ['PUBLISH'], ['plg_ty_catalog__PUBLISH'], 2, 101, false,
        ];
        yield 'une permission supérieure dissimulée dans un lot est refusée' => [
            ['ACCESS_MANAGE', 'UPLOAD'], ['plg_ty_catalog__UPLOAD', 'plg_ty_catalog__PUBLISH'], 2, 101, false,
        ];
        yield 'gestionnaire autorisé à transmettre un droit détenu' => [
            ['ACCESS_MANAGE', 'UPLOAD'], ['plg_ty_catalog__UPLOAD'], 2, 101, true,
        ];
        yield 'gestionnaire autorisé à transmettre tous les droits détenus' => [
            ['ACCESS_MANAGE', 'UPLOAD', 'PUBLISH'], ['plg_ty_catalog__UPLOAD', 'plg_ty_catalog__PUBLISH'], 2, 101, true,
        ];
        yield 'les droits sur A ne donnent pas accès à B' => [
            ['ACCESS_MANAGE', 'UPLOAD'], ['plg_ty_catalog__UPLOAD'], 2, 102, false,
        ];
        yield 'changer le Space ne transporte pas les droits' => [
            ['ACCESS_MANAGE', 'UPLOAD'], ['plg_ty_catalog__UPLOAD'], 3, 101, false,
        ];
        yield 'une révocation totale nécessite aussi le droit de gestion' => [
            ['UPLOAD'], [], 2, 101, false,
        ];
    }

    #[DataProvider('invalidTargetsAndPermissions')]
    public function testMalformedRequestsAreDeniedEvenBeforeTheRootBypass(
        string $managePermission, array $requested, string $objectType, int $objectId, int $spaceId): void
    {
        $acl = $this->acl([], root: true);
        $acl->expects(self::never())->method('hasPermissionOn');

        self::assertFalse($acl->canDelegatePermissionsOn($managePermission,
            $requested, 'plg_ty_catalog', $objectType, $objectId, $spaceId));
    }

    public static function invalidTargetsAndPermissions(): iterable
    {
        yield 'ROOT injecté dans les permissions' => ['plg_ty_catalog__ACCESS_MANAGE', ['plg_core__ROOT'], 'catalog', 101, 2];
        yield 'permission de gestion prise dans une autre extension' => ['plg_core__ROOT', [], 'catalog', 101, 2];
        yield 'permission vide' => ['plg_ty_catalog__ACCESS_MANAGE', ['plg_ty_catalog__'], 'catalog', 101, 2];
        yield 'permission non textuelle' => ['plg_ty_catalog__ACCESS_MANAGE', [42], 'catalog', 101, 2];
        yield 'type d’objet non enregistré' => ['plg_ty_catalog__ACCESS_MANAGE', [], 'unknown', 101, 2];
        yield 'identifiant nul' => ['plg_ty_catalog__ACCESS_MANAGE', [], 'catalog', 0, 2];
        yield 'identifiant négatif' => ['plg_ty_catalog__ACCESS_MANAGE', [], 'catalog', -1, 2];
        yield 'Space invalide' => ['plg_ty_catalog__ACCESS_MANAGE', [], 'catalog', 101, 0];
    }

    public function testRootCanDelegateValidPermissionsWithoutReceivingAnObjectRole(): void
    {
        $acl = $this->acl([], root: true);
        $acl->expects(self::never())->method('hasPermissionOn');

        self::assertTrue($acl->canDelegatePermissionsOn('plg_ty_catalog__ACCESS_MANAGE',
            ['plg_ty_catalog__PUBLISH'], 'plg_ty_catalog', 'catalog', 101, 2));
    }

    #[DataProvider('globalDelegationAttempts')]
    public function testGlobalManagementPermissionCannotBeUsedToCreateMorePowerfulGrants(
        array $held, array $requested, string $managePermission, bool $expected): void
    {
        $acl = $this->globalAcl($held);
        $acl->expects(self::once())->method('isRoot')->willReturn(false);

        self::assertSame($expected, $acl->canDelegateGlobalPermissions(
            $managePermission, $requested, 2
        ));
    }

    public static function globalDelegationAttempts(): iterable
    {
        yield 'gestion seule ne donne pas un rôle plus puissant' => [
            ['plg_core__USER_ROLES_CRUD'], ['plg_core__USERS_CRUD'], 'plg_core__USER_ROLES_CRUD', false,
        ];
        yield 'gestion et permissions détenues' => [
            ['plg_core__USER_ROLES_CRUD', 'plg_core__USERS_READ', 'plg_core__USERS_CRUD'],
            ['plg_core__USERS_CRUD'], 'plg_core__USER_ROLES_CRUD', true,
        ];
        yield 'une permission supérieure dans un lot est refusée' => [
            ['plg_core__GROUP_ROLES_ASSIGN', 'plg_core__ROLE_READ'],
            ['plg_core__ROLE_READ', 'plg_core__ROLE_CRUD'], 'plg_core__GROUP_ROLES_ASSIGN', false,
        ];
        yield 'un lot entièrement détenu passe' => [
            ['plg_core__GROUP_ROLES_ASSIGN', 'plg_core__ROLE_READ', 'plg_core__ROLE_CRUD'],
            ['plg_core__ROLE_READ', 'plg_core__ROLE_CRUD'], 'plg_core__GROUP_ROLES_ASSIGN', true,
        ];
    }

    #[DataProvider('invalidGlobalDelegations')]
    public function testMalformedGlobalDelegationsAreDeniedEvenForRoot(
        string $managePermission, array $permissions, int $spaceId): void
    {
        $acl = $this->globalAcl([], root: true);
        $acl->expects(self::never())->method('hasGlobalPermission');

        self::assertFalse($acl->canDelegateGlobalPermissions($managePermission, $permissions, $spaceId));
    }

    public static function invalidGlobalDelegations(): iterable
    {
        yield 'permission vide' => ['plg_core__', [], 2];
        yield 'permission non textuelle' => ['plg_core__GROUP_ROLES_ASSIGN', [42], 2];
        yield 'permission sans préfixe' => ['plg_core__GROUP_ROLES_ASSIGN', ['ROOT'], 2];
        yield 'espace nul' => ['plg_core__GROUP_ROLES_ASSIGN', [], 0];
        yield 'espace négatif' => ['plg_core__GROUP_ROLES_ASSIGN', [], -1];
    }

    public function testRootCanDelegateValidGlobalPermissions(): void
    {
        $acl = $this->globalAcl([], root: true);
        $acl->expects(self::never())->method('hasGlobalPermission');

        self::assertTrue($acl->canDelegateGlobalPermissions('plg_core__GROUP_ROLES_ASSIGN',
            ['plg_core__ROLE_READ'], 2));
    }

    private function globalAcl(array $held, bool $root = false): AclHelper
    {
        $acl = $this->getMockBuilder(AclHelper::class)->disableOriginalConstructor()
            ->onlyMethods(['hasGlobalPermission', 'isRoot'])->getMock();
        $acl->method('isRoot')->willReturn($root);
        $acl->method('hasGlobalPermission')->willReturnCallback(
            static fn (string $permission, $space): bool =>
                $space === 2 && in_array($permission, $held, true)
        );

        return $acl;
    }

    private function acl(array $held, bool $root = false): AclHelper
    {
        $acl = $this->getMockBuilder(AclHelper::class)->disableOriginalConstructor()
            ->onlyMethods(['getObjectTypeId', 'hasPermissionOn', 'isRoot'])->getMock();
        $acl->method('isRoot')->willReturn($root);
        $acl->method('getObjectTypeId')->willReturnCallback(
            static fn (string $extension, string $object): ?int =>
                $extension === 'plg_ty_catalog' && $object === 'catalog' ? 99 : null
        );
        $acl->method('hasPermissionOn')->willReturnCallback(
            static fn (string $permission, string $extension, string $object, int $spaceId, int $objectId): bool =>
                $extension === 'plg_ty_catalog' && $object === 'catalog' && $spaceId === 2 && $objectId === 101
                && in_array($permission, $held, true)
        );

        return $acl;
    }
}
