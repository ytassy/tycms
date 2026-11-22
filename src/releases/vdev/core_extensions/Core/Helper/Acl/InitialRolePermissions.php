<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Helper\Acl;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\PermDef;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\PermissionRequirements;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Roles;

/** Construction explicite des rôles d'une base neuve, sans service SQL ou calcul d'ACL. */
final class InitialRolePermissions
{
    /** @param list<PermDef> $definitions @return array<int, list<string>> */
    public static function fromCoreCatalog(array $definitions): array
    {
        $requirements = new PermissionRequirements(['plg_core' => $definitions]);
        $seeds = [Roles::ROLE_ROOT => Permissions::ROOT,
            Roles::ROLE_ADMIN_SPACE => Permissions::SPACE_ADMIN, Roles::ROLE_MEMBER => Permissions::MEMBER];

        $roles = [];
        foreach ($seeds as $roleId => $permission) {
            $roles[$roleId] = [$permission, ...$requirements->forPermission($permission)];
            sort($roles[$roleId]);
        }

        return $roles;
    }
}
