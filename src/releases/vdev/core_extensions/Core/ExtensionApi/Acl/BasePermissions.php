<?php

namespace YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl;

use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\Exceptions\TechException;

class BasePermissions extends BaseApplicationItem
{
    
    /** @return array<PermDef> */
    public function getPermissions(): array {
        static $permissions = [];

        $class = static::class;
        if (isset($permissions[$class])) {
            return $permissions[$class];
        }

        $definitions = [];
        foreach ((new \ReflectionClass($class))->getReflectionConstants() as $constant) {
            $attribute = $constant->getAttributes(PermDef::class)[0] ?? null;
            if ($attribute === null) {
                continue;
            }

            $permissionName = $constant->getValue();

            if ($this->in_dev) {
                if (str_contains($permissionName, '__') == false
                    || (str_starts_with($permissionName, 'plg_') == false
                        && str_starts_with($permissionName, 'comp_') == false
                        && str_starts_with($permissionName, 'mod_') == false
                        && str_starts_with($permissionName, 'tpl_') == false)) {
                    throw new TechException('Une permission doit avoir le format: <EXT>__<PERM_NAME>');
                }
            }

            $permission = $attribute->newInstance();

            $permission->name = $constant->getValue();
            $permission->manageableByRoleCrud = $constant->getAttributes(RoleCrudManageable::class) !== [];
            $group = $constant->getAttributes(UiGroup::class)[0] ?? null;
            $permission->uiGroup = $group?->newInstance();
            $requirements = $constant->getAttributes(RequiresPermissions::class)[0] ?? null;
            $permission->requiresPermissions = $requirements?->newInstance()->permissions ?? [];
            $definitions[] = $permission;
        }

        // Ne pas publier de catalogue partiel si une annotation est invalide.
        return $permissions[$class] = $definitions;
    }
}
