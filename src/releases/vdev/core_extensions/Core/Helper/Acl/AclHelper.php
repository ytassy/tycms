<?php

namespace YTassy\Tycms\CoreExts\Core\Helper\Acl;

use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\Application;
use YTassy\Tycms\DB\FormatIn;
use YTassy\Tycms\CoreExts\Core\Models\AclObject;
use YTassy\Tycms\CoreExts\Core\Models\AclPermission;
use YTassy\Tycms\CoreExts\Core\Models\AclRole;
use YTassy\Tycms\CoreExts\Core\Models\Space;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\PermDef;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\PermissionRequirements;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\Tools\ArrayTools;

class AclHelper extends BaseHelper
{
    // *********** Global

    /** @var array<int, AclRole> */
    private array $allRoles;
    /** @var array<int, array<string,int>> */
    private array $allObjects;

    /** @var array<int, array<string,array<string>>> */
    private array $allPermissionsByRoles;

    /** @var array<string, array<string,PermDef>> */
    private array $allPermissionsDefs = [];

    private ?PermissionRequirements $permissionRequirements = null;

    /**
     * Instantanés limités à la requête, indexés par compte ; « guest » reste distinct de tout ID.
     * @var array<int|string, array{group_ids: list<int>, roles: array<int, list<int>>, permissions: array<int, array<string, list<string>>>}>
     */
    private array $contextsByUserId = [];

    public function __construct(BaseApplicationItem|Application $app)
    {
        parent::__construct($app);
        $this->load();
    }

    public function isRoot(): bool
    {
        return $this->hasGlobalPermission(Permissions::ROOT, 1);
    }

    public function getAllRoles(){
        return $this->allRoles;
    }

    /** @return array<string, array<string, PermDef>> */
    public function getAllPermissionsDefinitions(): array
    {
        return $this->allPermissionsDefs ?? [];
    }

    public function getPermissionRequirements(): PermissionRequirements
    {
        return $this->permissionRequirements ??= new PermissionRequirements($this->allPermissionsDefs);
    }

    private function load(){

        // ************ Load Global

        $this->allRoles = ArrayTools::indexArray(
            $this->db->selectOrm(AclRole::class, "SELECT r.*, s.title as space_title FROM acl_roles r
                INNER JOIN spaces s ON s.id = r.space_id ORDER BY r.name ASC, r.id ASC")
            , 'id'
        );

        $this->allObjects = ArrayTools::indexArray($this->db->selectOrm(AclObject::class, "SELECT * FROM acl_objects"),
            ["extension", 'name'], false, 'id'
        );

        $this->allPermissionsByRoles = ArrayTools::indexArray($this->db->selectOrm(AclPermission::class, "SELECT * FROM acl_permissions"),
            ["role_id", 'extension'], true, 'name'
        );
    }

    /** Invalider après une attribution ; le prochain contrôle relit les droits de ce compte. */
    public function invalidateUser(int $userId): void
    {
        unset($this->contextsByUserId[$userId]);
    }

    /** @return array{group_ids: list<int>, roles: array<int, list<int>>, permissions: array<int, array<string, list<string>>>} */
    private function getUserContext(?int $userId): array
    {
        $key = $userId ?? 'guest';
        if (isset($this->contextsByUserId[$key])) {
            return $this->contextsByUserId[$key];
        }

        // Une seule lecture charge les groupes, leurs parents et leurs rôles globaux.
        // Seule la graine diffère pour l’invité ; les fragments SQL restent internes.
        $seed = $userId === null
            ? 'SELECT id, parent_id FROM user_groups WHERE id = 1'
            : 'SELECT ug.id, ug.parent_id FROM user_groups ug '
                . 'INNER JOIN user_groups_map gm ON gm.group_id = ug.id WHERE gm.user_id = %';
        $maps = $this->db->select(<<<SQL
            WITH RECURSIVE groups_for_user AS (
                $seed
                UNION DISTINCT
                SELECT parent.id, parent.parent_id FROM user_groups parent
                INNER JOIN groups_for_user child ON child.parent_id = parent.id
            )
            SELECT user_group.id AS group_id, assignment.space_id, assignment.role_id
            FROM groups_for_user user_group
            LEFT JOIN acl_map assignment ON assignment.group_id = user_group.id
                AND assignment.acl_object_id IS NULL AND assignment.object_id IS NULL
                AND assignment.space_id IS NOT NULL
            SQL, $userId === null ? [] : [$userId]);

        // Dédupliquer les chemins de groupes avant de projeter les permissions du catalogue partagé.
        $groups = [];
        $roles = [];
        $permissions = [];
        foreach ($maps as $map) {
            $groups[(int) $map->group_id] = true;
            if ($map->role_id === null) continue;

            $spaceId = (int) $map->space_id;
            $roleId = (int) $map->role_id;
            if (isset($roles[$spaceId][$roleId])) continue;
            $roles[$spaceId][$roleId] = true;
            foreach ($this->allPermissionsByRoles[$roleId] ?? [] as $extension => $names) {
                foreach ($names as $name) $permissions[$spaceId][$extension][$name] = true;
            }
        }

        return $this->contextsByUserId[$key] = [
            'group_ids' => array_keys($groups),
            'roles' => array_map(array_keys(...), $roles),
            'permissions' => array_map(
                static fn (array $byExtension): array => array_map(array_keys(...), $byExtension),
                $permissions
            ),
        ];
    }

    /** Le même calcul sert la session, le SSO et les contrôles après attribution. */
    private function contextHasGlobalPermission(array $context, string $permissionFullName, int|Space|null $space): bool
    {
        // ROOT est une autorité de l'installation, jamais une permission locale à une branche.
        if ($permissionFullName === Permissions::ROOT) {
            return in_array('ROOT', $context['permissions'][1]['plg_core'] ?? [], true);
        }

        [$extension, $permission] = explode('__', $permissionFullName);
        $spaceId = $space instanceof Space ? $space->id : $space;
        foreach ($this->coreSpaceHelper->getSpaceIdsWithParents($spaceId) as $ancestorId) {
            if (in_array($permission, $context['permissions'][$ancestorId][$extension] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @var array<PermDef> $permDefs
     */
    public function definePermissionsDefinition(string $extensionName, array $permDefs){
        foreach($permDefs as $permDef) $this->allPermissionsDefs[$extensionName][$permDef->name] = $permDef;
        $this->permissionRequirements = null;
    }
    
    public function hasGlobalRole(int $roleId, int|Space|null $space = null): bool
    {
        $spaceId = $space instanceof Space ? $space->id : $space;

        foreach ($this->coreSpaceHelper->getSpaceIdsWithParents($spaceId) as $spaceId) {
            if (in_array($roleId, $this->getUserGlobalRolesOfSpace($spaceId), true)) return true;
        }

        return false;
    }

    public function hasGlobalRoleOnSpace(int $roleId, int|Space $space): bool
    {
        $spaceId = $space instanceof Space ? $space->id : $space;

        // Ce contrôle explicite du rôle n'inclut pas les espaces ancêtres.
        return in_array($roleId, $this->getUserGlobalRolesOfSpace($spaceId), true);
    }

    public function userHasGlobalRoleOnSpace(int $userId, int $roleId, int|Space $space): bool
    {
        $spaceId = $space instanceof Space ? $space->id : $space;
        return in_array($roleId, $this->getUserContext($userId)['roles'][$spaceId] ?? [], true);
    }

    public function userHasGlobalPermission(int $userId, string $permissionFullName, int|Space $space): bool
    {
        return $this->contextHasGlobalPermission($this->getUserContext($userId), $permissionFullName, $space);
    }

    public function hasGlobalPermission(string $permissionFullName, int|Space|null $space = null): bool
    {
        return $this->contextHasGlobalPermission(
            $this->getUserContext($this->session->getUserId()), $permissionFullName, $space
        );
    }


    /** Sans objectId, conserver le contrôle historique portant sur tout le type d'objet. */
    public function hasPermissionOn(string $permission, string $extension, string $object,
        int|Space|null $space = null, ?int $objectId = null): bool
    {
        // Un identifiant invalide ne doit pas devenir un contrôle plus large.
        if ($objectId !== null && $objectId <= 0) return false;

        $spaceId = $space instanceof Space ? $space->id : $space;

        if ($this->hasGlobalPermission($extension . '__' . $permission, $spaceId)) return true;

        // Une ligne d'ACL sur un type ou une instance ne peut pas accorder ROOT.
        if ($extension . '__' . $permission === Permissions::ROOT) return false;

        $aclObjectId = $this->getObjectTypeId($extension, $object);
        if ($aclObjectId === null) return false;

        $spaceIds = $this->coreSpaceHelper->getSpaceIdsWithParents($spaceId);
        if ($spaceIds === []) return false;

        $groupIds = $this->getUserContext($this->session->getUserId())['group_ids'];
        if ($groupIds === []) return false;

        // Les droits de type sont hérités ; le droit d'instance reste limité à son ID.
        $objectCondition = $objectId === null
            ? 'map.object_id IS NULL'
            : '(map.object_id IS NULL OR map.object_id = %)';
        $values = [FormatIn::get($groupIds), $aclObjectId];
        if ($objectId !== null) $values[] = $objectId;
        array_push($values, $extension, $permission, FormatIn::get($spaceIds));

        return $this->db->selectOneOrNull(<<<SQL
            SELECT 1
            FROM acl_map map
            INNER JOIN acl_permissions permissions ON permissions.role_id = map.role_id
            WHERE map.group_id IN (%)
              AND map.acl_object_id = %
              AND $objectCondition
              AND permissions.extension = %
              AND permissions.name = %
              AND map.space_id IN (%)
            LIMIT 1
            SQL, $values) !== null;
    }

    public function getObjectTypeId(string $extension, string $object): ?int
    {
        return $this->allObjects[$extension][$object] ?? null;
    }

    /**
     * Vérifier la délégation sur une ressource déjà relue par le WF.
     * Les noms de permissions sont complets ; le WF borne aussi les rôles au métier.
     * @param list<string> $permissions Permissions des rôles ajoutés ou retirés.
     */
    public function canDelegatePermissionsOn(string $managePermission, array $permissions,
        string $extension, string $object, int $objectId, int|Space $space): bool
    {
        $spaceId = $space instanceof Space ? $space->id : $space;
        if ($objectId <= 0 || $spaceId <= 0 || $this->getObjectTypeId($extension, $object) === null) return false;

        // Une permission d'une autre extension ne peut pas être déléguée par ce contrôle.
        $prefix = $extension . '__';
        foreach ([$managePermission, ...$permissions] as $permission) {
            if (!is_string($permission) || !str_starts_with($permission, $prefix)
                || strlen($permission) <= strlen($prefix)) return false;
        }

        if ($this->isRoot()) return true;

        // Gérer les accès ne donne pas implicitement les droits à transmettre.
        foreach ([$managePermission, ...$permissions] as $permission) {
            if (!$this->hasPermissionOn(substr($permission, strlen($prefix)),
                $extension, $object, $spaceId, $objectId)) return false;
        }

        return true;
    }

    /**
     * Vérifier la délégation de permissions globales sur un Space.
     * Le WF a relu et verrouillé les rôles/groupes concernés avant l'appel.
     * @param list<string> $permissions Permissions des rôles ajoutés ou retirés.
     */
    public function canDelegateGlobalPermissions(string $managePermission, array $permissions,
        int $spaceId): bool
    {
        if ($spaceId <= 0) return false;

        // Refuser une requête mal formée, même pour ROOT.
        foreach ([$managePermission, ...$permissions] as $permission) {
            if (!is_string($permission) || !str_contains($permission, '__')) return false;
            [$extension, $name] = explode('__', $permission, 2);
            if ($extension === '' || $name === '') return false;
        }

        if ($this->isRoot()) return true;

        // Gérer les attributions ne donne pas implicitement les droits transmis.
        foreach ([$managePermission, ...$permissions] as $permission) {
            if (!$this->hasGlobalPermission($permission, $spaceId)) return false;
        }

        return true;
    }

    /** @return array<int, array<int, int>> */
    public function getUserGlobalRoles(): array
    {
        return $this->getUserContext($this->session->getUserId())['roles'];
    }

    /** @return array<int, int> */
    public function getUserGlobalRolesOfSpace(?int $spaceId = null): array
    {
        if ($spaceId === null) $spaceId = $this->coreSpaceHelper->getCurrentSpace()->id;
        return $this->getUserGlobalRoles()[$spaceId] ?? [];
    }

    /** @return array<int, array<string, array<int, string>>> */
    public function getUserGlobalPermissions(bool $flat = false): array
    {
        return array_map(function($item){
            return self::toFlatPerms($item);
        }, $this->getUserContext($this->session->getUserId())['permissions']);
    }

    /** @return array<string, array<int, string>> */
    public function getUserGlobalPermissionsOfSpace(?int $spaceId = null, bool $flat = false): array
    {
        if ($spaceId === null) $spaceId = $this->coreSpaceHelper->getCurrentSpace()->id;
        return self::toFlatPerms($this->getUserContext($this->session->getUserId())['permissions'][$spaceId] ?? []);
    }


    private static function toFlatPerms(array $listByExt){
        $res = [];
        foreach($listByExt as $ext => $perms)
        {
            foreach($perms as $perm) $res[] = $ext . '__' . $perm;
        }
        return $res;
    }


    /** @return array<int> */
    public function getSpacesHavingGlobalPermission(string|array $permissionsFullNames): array
    {
        $spaces = [];

        if (is_string($permissionsFullNames)) $permissionsFullNames = [ $permissionsFullNames ];

        foreach ($this->coreSpaceHelper->getAllSpaceIds() as $spaceId) {
            foreach($permissionsFullNames as $permissionFullName)
            {
                if ($this->hasGlobalPermission($permissionFullName, $spaceId)) {    
                    $spaces[] = $spaceId;
                    break;
                }
            }
        }

        return $spaces;
    }

}
