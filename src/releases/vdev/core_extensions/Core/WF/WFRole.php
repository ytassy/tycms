<?php

namespace YTassy\Tycms\CoreExts\Core\WF;

use YTassy\Tycms\CoreExts\Core\Helper\TraitAllHelpers;
use YTassy\Tycms\CoreExts\Core\Models\AclRole;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Roles;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\WF\BaseWF;

class WFRole extends BaseWF
{
    use TraitAllHelpers;

    /** @return array<string, mixed> */
    public function getDirectory(): array
    {
        $spaces = [];
        foreach ($this->coreSpaceHelper->getAllSpacesByIds() as $space) {
            if ($this->canRead($space->id)) {
                $spaces[] = [
                    'id' => $space->id,
                    'title' => $space->title,
                    'can_create' => $this->canCrud($space->id),
                ];
            }
        }
        if ($spaces === []) {
            ExceptionResponse::throwDanger('Vous ne disposez pas des droits pour consulter les rôles.');
        }

        $roles = [];
        foreach ($this->coreAclHelper->getAllRoles() as $role) {
            if ($this->canRead($role->space_id)) {
                $roles[] = $this->presentRole($role);
            }
        }

        $catalog = [];
        foreach ($this->coreAclHelper->getAllPermissionsDefinitions() as $extensionName => $definitions) {
            foreach ($definitions as $definition) {
                $allowedSpaces = [];
                foreach ($spaces as $space) {
                    if ($space['can_create'] && $this->canManagePermission($definition->name, $space['id'])) {
                        $allowedSpaces[] = $space['id'];
                    }
                }

                $catalog[] = [
                    'name' => $definition->name,
                    'label' => $definition->uiTitle,
                    'description' => $definition->uiDescription,
                    'required_permissions' => $this->coreAclHelper->getPermissionRequirements()->forPermission($definition->name),
                    'direct_required_permissions' => $definition->requiresPermissions,
                    'tab' => $definition->uiGroup?->tab ?? 'Extensions',
                    'group' => $definition->uiGroup?->group ?? $extensionName,
                    'manageable_space_ids' => $allowedSpaces,
                ];
            }
        }

        return ['spaces' => $spaces, 'roles' => $roles, 'permissions' => $catalog];
    }

    /** @return array<string, mixed> */
    public function createRole(object $data): array
    {
        $spaceId = $data->space_id ?? null;
        $type = $data->type ?? null;
        if (!is_int($spaceId) || $this->coreSpaceHelper->getSpace($spaceId) === null
            || !is_int($type) || !in_array($type, [0, 1, 2], true)) {
            ExceptionResponse::throwWarning('Espace ou type de rôle invalide.');
        }
        $this->assertCanCrud($spaceId);

        [$name, $description, $permissions] = $this->validateData($data);
        $this->coreRoleAdminHelper->assertRootRoleScope($permissions, $spaceId, $type);
        if ($this->coreRoleAdminHelper->hasName($spaceId, $name)) {
            ExceptionResponse::throwWarning('Ce nom de rôle existe déjà dans cet espace.');
        }

        // Un rôle neuf ne possède aucune permission implicite.
        $this->assertPermissionChanges([], $permissions, $spaceId, []);
        $roleId = $this->coreRoleAdminHelper->createRole($spaceId, $name, $description, $type);
        $this->coreRoleAdminHelper->replacePermissions($roleId, $permissions);

        return $this->presentRole($this->coreRoleAdminHelper->getRole($roleId));
    }

    /** @return array<string, mixed> */
    public function updateRole(int $roleId, object $data): array
    {
        // Le verrou sérialise les éditions et empêche une attribution concurrente
        // pendant la vérification des permissions du rôle.
        $role = $this->getRoleForCrud($roleId, true);
        $existing = $this->coreRoleAdminHelper->getPermissions($roleId, true);
        [$name, $description, $permissions] = $this->validateData($data, $existing);
        $this->coreRoleAdminHelper->assertRootRoleScope($permissions, $role->space_id, $role->type, $roleId);

        // Conserver l'identité des deux rôles implicites : seules leurs permissions sont éditables.
        if (Roles::isImplicit($roleId) && ($name !== $role->name || $description !== $role->description)) {
            ExceptionResponse::throwWarning('Seules les permissions des rôles Invité et Utilisateur peuvent être modifiées.');
        }

        if ($roleId === Roles::ROLE_MEMBER && !in_array(Permissions::MEMBER, $permissions, true)) {
            ExceptionResponse::throwWarning('La permission MEMBER est obligatoire pour le rôle 5.');
        }
        if ($roleId === Roles::ROLE_ROOT && !in_array(Permissions::ROOT, $permissions, true)) {
            ExceptionResponse::throwWarning('La permission ROOT est obligatoire pour le rôle système 2.');
        }
        if ($roleId === Roles::ROLE_MEMBER && ($role->space_id !== 1 || $role->type !== 0)) {
            ExceptionResponse::throwDanger('Le rôle 5 doit rester global dans l’espace racine.');
        }
        if ($this->coreRoleAdminHelper->hasName($role->space_id, $name, $roleId)) {
            ExceptionResponse::throwWarning('Ce nom de rôle existe déjà dans cet espace.');
        }

        $assignments = $this->coreRoleAdminHelper->getAssignmentSpaceIds($roleId);
        $this->assertPermissionChanges($existing, $permissions, $role->space_id, $assignments);

        $this->coreRoleAdminHelper->updateRole($roleId, $name, $description);
        if ($existing !== $permissions) {
            $this->coreRoleAdminHelper->replacePermissions($roleId, $permissions);
        }

        return $this->presentRole($this->coreRoleAdminHelper->getRole($roleId));
    }

    public function deleteRole(int $roleId): bool
    {
        $role = $this->getRoleForCrud($roleId, true);
        if (!$role->user_defined || $roleId <= Roles::ROLE_MEMBER) {
            ExceptionResponse::throwWarning('Un rôle système ne peut pas être supprimé.');
        }
        if ($this->coreRoleAdminHelper->hasAssignments($roleId)) {
            ExceptionResponse::throwWarning('Ce rôle est encore attribué à un groupe.');
        }
        if ($this->coreRoleAdminHelper->hasReferences($roleId)) {
            ExceptionResponse::throwWarning('Ce rôle est encore utilisé par un menu, une page ou un espace.');
        }

        $this->coreRoleAdminHelper->deleteRole($roleId);
        return true;
    }

    private function getRoleForCrud(int $roleId, bool $lock): AclRole
    {
        $role = $this->coreRoleAdminHelper->getRole($roleId, $lock);
        if ($role === null) {
            ExceptionResponse::throwWarning('Rôle introuvable.');
        }
        $this->assertCanCrud($role->space_id);

        if (!$role->user_defined && !$this->coreAclHelper->isRoot()) {
            ExceptionResponse::throwDanger('Seul un super administrateur peut modifier un rôle système.');
        }

        return $role;
    }

    /** @return array{string,string,list<string>} */
    private function validateData(object $data, array $existing = []): array
    {
        $name = $data->name ?? null;
        $description = $data->description ?? null;
        $permissions = $data->permissions ?? null;
        if (!is_string($name) || !is_string($description) || !is_array($permissions)) {
            ExceptionResponse::throwWarning('Données du rôle invalides.');
        }

        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100 || mb_strlen($description) > 5000) {
            ExceptionResponse::throwWarning('Le nom ou la description du rôle est invalide.');
        }
        if (count($permissions) > 250) {
            ExceptionResponse::throwWarning('Liste de permissions invalide.');
        }
        foreach ($permissions as $permission) {
            if (!is_string($permission)) {
                ExceptionResponse::throwWarning('Liste de permissions invalide.');
            }
        }
        if (count($permissions) !== count(array_unique($permissions))) {
            ExceptionResponse::throwWarning('Liste de permissions invalide.');
        }

        $catalog = [];
        foreach ($this->coreAclHelper->getAllPermissionsDefinitions() as $definitions) {
            foreach ($definitions as $definition) {
                $catalog[$definition->name] = $definition;
            }
        }
        foreach ($permissions as $permission) {
            if (!isset($catalog[$permission]) && !in_array($permission, $existing, true)) {
                ExceptionResponse::throwWarning('Permission inconnue.');
            }
        }

        // La cohérence est propre au rôle : les droits de l'éditeur ou d'autres rôles ne la complètent pas.
        $missing = $this->coreAclHelper->getPermissionRequirements()->missingFrom($permissions);
        if ($missing !== []) {
            $permission = array_key_first($missing);
            $labels = array_map(static fn (string $name): string => $catalog[$name]->uiTitle, $missing[$permission]);
            ExceptionResponse::throwWarning('La permission « ' . $catalog[$permission]->uiTitle
                . ' » nécessite aussi : ' . implode(', ', $labels) . '.');
        }

        sort($permissions);
        return [$name, $description, $permissions];
    }

    /** @param list<string> $before @param list<string> $after @param list<int> $assignmentSpaces */
    private function assertPermissionChanges(array $before, array $after, int $spaceId, array $assignmentSpaces): void
    {
        $changed = array_unique(array_merge(array_diff($before, $after), array_diff($after, $before)));

        foreach ($changed as $permission) {
            if (!$this->canManagePermission($permission, $spaceId)) {
                ExceptionResponse::throwDanger('Vous ne pouvez pas modifier la permission ' . $permission . '.');
            }

            // Une attribution existante peut placer ce rôle sur un autre espace.
            // Le changement ne doit pas y étendre les droits de l’éditeur.
            foreach ($assignmentSpaces as $assignedSpaceId) {
                if (!$this->canCrud($assignedSpaceId)
                    || !$this->canManagePermission($permission, $assignedSpaceId)) {
                    ExceptionResponse::throwDanger('Une attribution de ce rôle empêche ce changement de permission.');
                }
            }
        }
    }

    private function canManagePermission(string $permission, int $spaceId): bool
    {
        if ($this->coreAclHelper->isRoot()) {
            return true;
        }

        foreach ($this->coreAclHelper->getAllPermissionsDefinitions() as $definitions) {
            if (isset($definitions[$permission])) {
                return $definitions[$permission]->manageableByRoleCrud
                    && $this->coreAclHelper->hasGlobalPermission($permission, $spaceId);
            }
        }

        return false;
    }

    private function canRead(int $spaceId): bool
    {
        return $this->canCrud($spaceId)
            || $this->coreAclHelper->hasGlobalPermission(Permissions::ROLE_READ, $spaceId);
    }

    private function canCrud(int $spaceId): bool
    {
        return $this->coreAclHelper->isRoot()
            || $this->coreAclHelper->hasGlobalPermission(Permissions::ROLE_CRUD, $spaceId);
    }

    private function assertCanCrud(int $spaceId): void
    {
        if (!$this->canCrud($spaceId)) {
            ExceptionResponse::throwDanger('Vous ne disposez pas des droits pour modifier les rôles de cet espace.');
        }
    }

    /** @return array<string, mixed> */
    private function presentRole(AclRole $role): array
    {
        // Le nom technique vient des constantes PHP, sans seconde liste dans Vue.
        static $systemNames = null;
        $systemNames ??= array_flip((new \ReflectionClass(Roles::class))->getConstants());

        return [
            'id' => $role->id,
            'space_id' => $role->space_id,
            'space_title' => $role->space_title,
            'name' => $role->name,
            'description' => $role->description,
            'type' => $role->type,
            'user_defined' => $role->user_defined,
            'technical_name' => $role->user_defined ? null : ($systemNames[$role->id] ?? null),
            'permissions' => $this->coreRoleAdminHelper->getPermissions($role->id),
            'can_edit_details' => !Roles::isImplicit($role->id) && $this->canCrud($role->space_id)
                && ($role->user_defined || $this->coreAclHelper->isRoot()),
            'assignment_notice' => match ($role->id) {
                Roles::ROLE_GEST => 'Ce rôle et le groupe Invité sont réservés aux personnes non connectées. Ils ne peuvent pas être attribués manuellement. Seules les permissions de ce rôle sont modifiables.',
                Roles::ROLE_USER => 'Tous les utilisateurs connectés reçoivent automatiquement ce rôle via le groupe Utilisateurs. Ils ne peuvent pas être attribués manuellement. Seules les permissions de ce rôle sont modifiables.',
                default => null,
            },
            'can_edit' => $this->canCrud($role->space_id)
                && ($role->user_defined || $this->coreAclHelper->isRoot()),
            'can_delete' => $role->id > Roles::ROLE_MEMBER && $role->user_defined
                && $this->canCrud($role->space_id),
        ];
    }
}
