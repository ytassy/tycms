<?php

namespace YTassy\Tycms\CoreExts\Core\WF;

use YTassy\Tycms\CoreExts\Core\Helper\TraitAllHelpers;
use YTassy\Tycms\CoreExts\Core\Models\UserGroup;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\UserGroups;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Roles;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\WF\BaseWF;

class WFUserGroup extends BaseWF
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
                    'can_assign_roles' => $this->canAssignRoles($space->id),
                ];
            }
        }
        if ($spaces === []) {
            ExceptionResponse::throwDanger('Vous ne disposez pas des droits pour consulter les groupes.');
        }

        $readableSpaceIds = array_fill_keys(array_column($spaces, 'id'), true);
        $roleCatalog = [];
        foreach ($this->coreAclHelper->getAllRoles() as $role) {
            if (isset($readableSpaceIds[$role->space_id]) && in_array($role->type, [0, 2], true)
                && !Roles::isImplicit($role->id) && $this->canAssignRoles($role->space_id)) {
                $roleCatalog[] = [
                    'id' => $role->id,
                    'name' => $role->name,
                    'space_id' => $role->space_id,
                ];
            }
        }

        // Ne proposer que les rôles que l'appelant peut réellement déléguer.
        $roleCatalog = $this->filterDelegableRoles($roleCatalog);

        $groups = [];
        $referenced = $this->coreUserGroupAdminHelper->getReferencedGroupIds();
        $assignments = $this->coreUserGroupAdminHelper->getRoleAssignments();
        foreach ($this->coreUserGroupAdminHelper->getGroups() as $group) {
            if ($this->canRead($group->space_id)) {
                $groups[] = $this->present($group, $referenced, $assignments[$group->id] ?? []);
            }
        }

        return ['spaces' => $spaces, 'groups' => $groups, 'roles' => $roleCatalog];
    }

    /** @return array<string, mixed> */
    public function createGroup(object $data): array
    {
        $spaceId = $data->space_id ?? null;
        if (!is_int($spaceId) || !isset($this->coreSpaceHelper->getAllSpacesByIds()[$spaceId])) {
            ExceptionResponse::throwWarning('Espace invalide.');
        }
        $this->assertCanCrud($spaceId);

        [$parentId, $name, $description] = $this->validateData($data, $spaceId);
        $roleIds = $this->validateRoleIds($data->role_ids ?? [], $spaceId);
        $this->assertCanDelegateGroup($parentId);
        if ($this->coreUserGroupAdminHelper->hasName($spaceId, $parentId, $name)) {
            ExceptionResponse::throwWarning('Ce nom de groupe existe déjà à ce niveau.');
        }

        $groupId = $this->coreUserGroupAdminHelper->create($spaceId, $parentId, $name, $description);
        $this->coreUserGroupAdminHelper->replaceRoleAssignments($groupId, $spaceId, $roleIds);

        return $this->present($this->coreUserGroupAdminHelper->getGroup($groupId), null,
            $this->coreUserGroupAdminHelper->getRoleAssignmentsForGroup($groupId));
    }

    /** @return array<string, mixed> */
    public function updateGroup(int $groupId, object $data): array
    {
        $group = $this->getForCrud($groupId);
        [$parentId, $name, $description] = $this->validateData($data, $group->space_id, $groupId);
        $existingRoleIds = $this->coreUserGroupAdminHelper->getGlobalRoleIdsForGroup($groupId, $group->space_id);
        $roleIds = property_exists($data, 'role_ids')
            ? $this->validateRoleIds($data->role_ids, $group->space_id, $groupId, $existingRoleIds) : null;
        if ($parentId !== $group->parent_id) {
            $this->assertCanDelegateGroup($parentId);
        }
        if ($this->coreUserGroupAdminHelper->hasName($group->space_id, $parentId, $name, $groupId)) {
            ExceptionResponse::throwWarning('Ce nom de groupe existe déjà à ce niveau.');
        }

        $this->coreUserGroupAdminHelper->update($groupId, $parentId, $name, $description);
        if ($roleIds !== null) {
            $this->coreUserGroupAdminHelper->replaceRoleAssignments($groupId, $group->space_id, $roleIds);
        }

        return $this->present($this->coreUserGroupAdminHelper->getGroup($groupId), null,
            $this->coreUserGroupAdminHelper->getRoleAssignmentsForGroup($groupId));
    }

    /** @param list<int> $roleIds @return array<string, mixed> */
    public function updateRoleAssignments(int $groupId, array $roleIds): array
    {
        $group = $this->getForRoles($groupId);
        if (UserGroups::isImplicit($groupId)) {
            ExceptionResponse::throwWarning('Les rôles des groupes Invité et Utilisateurs sont fixes. Modifiez les permissions du rôle système correspondant.');
        }

        $roleIds = $this->validateRoleIds($roleIds, $group->space_id, $groupId,
            $this->coreUserGroupAdminHelper->getGlobalRoleIdsForGroup($groupId, $group->space_id));

        $this->coreUserGroupAdminHelper->replaceRoleAssignments($groupId, $group->space_id, $roleIds);

        return $this->present($group, null,
            $this->coreUserGroupAdminHelper->getRoleAssignmentsForGroup($groupId));
    }

    public function deleteGroup(int $groupId): bool
    {
        $this->getForCrud($groupId);

        // Protéger les adhésions, les droits et les références JSON des Spaces.
        if ($this->coreUserGroupAdminHelper->hasDatabaseReferences($groupId)
            || $this->coreUserGroupAdminHelper->hasSpaceReference($groupId)) {
            ExceptionResponse::throwWarning('Ce groupe est encore utilisé et ne peut pas être supprimé.');
        }

        $this->coreUserGroupAdminHelper->delete($groupId);

        return true;
    }

    private function getForCrud(int $groupId): UserGroup
    {
        $group = $this->getGroupForWrite($groupId);
        if (in_array($groupId, UserGroups::getAllIds(), true)) {
            ExceptionResponse::throwWarning('Un groupe système ne peut pas être modifié.');
        }
        $this->assertCanCrud($group->space_id);

        return $group;
    }

    private function getForRoles(int $groupId): UserGroup
    {
        $group = $this->getGroupForWrite($groupId);
        $this->assertCanAssignRoles($group->space_id);

        return $group;
    }

    private function getGroupForWrite(int $groupId): UserGroup
    {
        $group = $this->coreUserGroupAdminHelper->getGroup($groupId, true);
        if ($group === null || $group->type !== 0) {
            ExceptionResponse::throwWarning('Groupe introuvable.');
        }

        return $group;
    }

    /**
     * Valider les rôles d'un groupe, verrouiller leurs définitions et contrôler
     * la délégation des seuls rôles ajoutés ou retirés.
     * @param list<int> $existingRoleIds Rôles déjà attribués au groupe.
     * @return list<int>
     */
    private function validateRoleIds(mixed $roleIds, int $spaceId, ?int $groupId = null,
        array $existingRoleIds = []): array
    {
        if (!is_array($roleIds) || !array_is_list($roleIds) || count($roleIds) > 250) {
            ExceptionResponse::throwWarning('Liste de rôles invalide.');
        }

        $unique = [];
        foreach ($roleIds as $roleId) {
            if (!is_int($roleId) || $roleId < 1 || isset($unique[$roleId])) {
                ExceptionResponse::throwWarning('Un rôle est invalide ou appartient à un autre espace.');
            }
            $unique[$roleId] = true;
        }

        // Relire et verrouiller l'union des rôles avant, après et inchangés.
        $allIds = array_values(array_unique([...array_keys($unique), ...$existingRoleIds]));
        $locked = $this->coreRoleAdminHelper->getRolesForUpdate($allIds);
        if (count($locked) !== count($allIds)) {
            ExceptionResponse::throwWarning('Un rôle est invalide ou appartient à un autre espace.');
        }

        foreach ($locked as $role) {
            if ($role->space_id !== $spaceId || !in_array($role->type, [0, 2], true)
                || Roles::isImplicit($role->id)) {
                ExceptionResponse::throwWarning('Un rôle est invalide ou appartient à un autre espace.');
            }
        }

        // Le dernier accès root livré par la migration dépend de ce lien.
        if ($groupId === UserGroups::GROUP_ROOT && !isset($unique[Roles::ROLE_ROOT])) {
            ExceptionResponse::throwWarning('Le rôle Root doit rester attribué au groupe Super Admin.');
        }

        // Seuls les rôles ajoutés ou retirés portent le contrôle de délégation.
        $before = array_fill_keys($existingRoleIds, true);
        $changed = array_merge(
            array_keys(array_diff_key($unique, $before)),
            array_keys(array_diff_key($before, $unique)),
        );
        sort($changed);

        if ($changed !== []) {
            $this->assertCanAssignRoles($spaceId);

            // Les rôles sont déjà verrouillés ; verrouiller leurs permissions avant lecture.
            $permissions = $this->coreRoleAdminHelper->getPermissionsForRoles($changed, true);
            $delegated = [];
            foreach ($changed as $roleId) {
                $delegated = array_merge($delegated, $permissions[$roleId] ?? []);
            }

            if (!$this->coreAclHelper->canDelegateGlobalPermissions(
                Permissions::GROUP_ROLES_ASSIGN, $delegated, $spaceId
            )) {
                ExceptionResponse::throwDanger('Vous ne pouvez pas attribuer ces rôles avec vos droits actuels.');
            }
        }

        return $roleIds;
    }

    /** @return array{?int,string,string} */
    private function validateData(object $data, int $spaceId, ?int $groupId = null): array
    {
        $name = $data->name ?? null;
        $description = $data->description ?? null;
        $parentId = $data->parent_id ?? null;
        if (!is_string($name) || !is_string($description) || !is_int($parentId)
            || $parentId < 0) {
            ExceptionResponse::throwWarning('Données du groupe invalides.');
        }

        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100 || mb_strlen($description) > 5000) {
            ExceptionResponse::throwWarning('Nom ou description du groupe invalide.');
        }

        $parentId = $parentId === 0 ? null : $parentId;
        if ($parentId !== null) {
            $parent = $this->coreUserGroupAdminHelper->getGroup($parentId, true);
            if ($parent === null || $parent->type !== 0 || $parent->space_id !== $spaceId
                || in_array($parentId, UserGroups::getAllIds(), true) || $parentId === $groupId) {
                ExceptionResponse::throwWarning('Le parent doit être un groupe ordinaire du même espace.');
            }

            // Suivre les parents en base pour interdire tout cycle, même si le client
            // soumet une hiérarchie devenue obsolète entre deux lectures.
            $visited = [$parentId => true];
            while ($parent->parent_id !== null) {
                if ($parent->parent_id === $groupId || isset($visited[$parent->parent_id])) {
                    ExceptionResponse::throwWarning('Un groupe ne peut pas devenir son propre descendant.');
                }
                $visited[$parent->parent_id] = true;
                $parent = $this->coreUserGroupAdminHelper->getGroup($parent->parent_id, true);
                if ($parent === null) {
                    ExceptionResponse::throwWarning('Hiérarchie des groupes invalide.');
                }
            }
        }

        return [$parentId, $name, $description];
    }

    private function canRead(int $spaceId): bool
    {
        return $this->canCrud($spaceId)
            || $this->coreAclHelper->hasGlobalPermission(Permissions::USER_GROUPS_READ, $spaceId);
    }

    private function canCrud(int $spaceId): bool
    {
        return $this->coreAclHelper->hasGlobalPermission(Permissions::USER_GROUPS_CRUD, $spaceId);
    }

    private function assertCanCrud(int $spaceId): void
    {
        if (!$this->canCrud($spaceId)) {
            ExceptionResponse::throwDanger('Vous ne pouvez pas modifier les groupes de cet espace.');
        }
    }

    private function canAssignRoles(int $spaceId): bool
    {
        return $this->coreAclHelper->hasGlobalPermission(Permissions::GROUP_ROLES_ASSIGN, $spaceId);
    }

    private function assertCanAssignRoles(int $spaceId): void
    {
        if (!$this->canAssignRoles($spaceId)) {
            ExceptionResponse::throwDanger('Vous ne pouvez pas modifier les rôles de ce groupe.');
        }
    }

    private function canReadRoles(int $spaceId): bool
    {
        return $this->coreAclHelper->hasGlobalPermission(Permissions::ROLE_READ, $spaceId);
    }

    /** Contrôler les permissions héritées du parent avant de déplacer un groupe. */
    private function assertCanDelegateGroup(?int $parentId): void
    {
        if ($parentId === null) return;

        $permissions = $this->coreUserGroupAdminHelper->getGroupPermissionsBySpace([$parentId], true)[$parentId] ?? [];
        foreach ($permissions as $permissionSpaceId => $spacePermissions) {
            if (!$this->coreAclHelper->canDelegateGlobalPermissions(
                Permissions::USER_GROUPS_CRUD, $spacePermissions, $permissionSpaceId
            )) {
                ExceptionResponse::throwDanger('Vous ne pouvez pas placer un groupe sous ce parent avec vos droits actuels.');
            }
        }
    }

    /**
     * Filtrer un catalogue de rôles par la délégation réelle de l'appelant.
     * @param list<array{id:int,name:string,space_id:int}> $roles
     * @return list<array{id:int,name:string,space_id:int}>
     */
    private function filterDelegableRoles(array $roles): array
    {
        $roleIds = array_map(static fn (array $role): int => $role['id'], $roles);
        $permissions = $this->coreRoleAdminHelper->getPermissionsForRoles($roleIds);

        return array_values(array_filter($roles,
            fn (array $role): bool => $this->coreAclHelper->canDelegateGlobalPermissions(
                Permissions::GROUP_ROLES_ASSIGN, $permissions[$role['id']] ?? [], $role['space_id'])));
    }

    /** @return array<string, mixed> */
    private function present(UserGroup $group, ?array $referenced = null, array $assignments = []): array
    {
        static $systemNames = null;
        $systemNames ??= array_flip((new \ReflectionClass(UserGroups::class))->getConstants());
        $system = in_array($group->id, UserGroups::getAllIds(), true);

        // Les rôles attribués sont une donnée d'attribution : ROLE_READ les autorise.
        $visibleAssignments = $this->canReadRoles($group->space_id) ? $assignments : [];

        return [
            'id' => $group->id,
            'space_id' => $group->space_id,
            'space_title' => $this->coreSpaceHelper->getSpace($group->space_id)?->title ?? '',
            'parent_id' => $group->parent_id,
            'name' => $group->name,
            'description' => $group->description,
            'technical_name' => $system ? ($systemNames[$group->id] ?? null) : null,
            'roles' => $visibleAssignments,
            'role_ids' => array_values(array_map(static fn (array $role): int => $role['id'],
                array_filter($visibleAssignments, static fn (array $role): bool => $role['space_id'] === $group->space_id
                    && in_array($role['type'], [0, 2], true)))),
            'can_edit' => !$system && $this->canCrud($group->space_id),
            'can_assign_roles' => !UserGroups::isImplicit($group->id) && $this->canAssignRoles($group->space_id),
            'assignment_notice' => match ($group->id) {
                UserGroups::GROUP_GEST => 'Ce groupe et son rôle Invité sont réservés aux personnes non connectées. Leur lien est fixe ; seules les permissions du rôle Invité sont modifiables.',
                UserGroups::GROUP_USERS => 'Tous les utilisateurs connectés appartiennent automatiquement à ce groupe et reçoivent son rôle Utilisateur. Leur lien est fixe ; seules les permissions du rôle Utilisateur sont modifiables.',
                default => null,
            },
            'can_delete' => !$system && $this->canCrud($group->space_id)
                && ($referenced !== null
                    ? !isset($referenced[$group->id])
                    : (!$this->coreUserGroupAdminHelper->hasDatabaseReferences($group->id)
                        && !$this->coreUserGroupAdminHelper->hasSpaceReference($group->id))),
        ];
    }
}
