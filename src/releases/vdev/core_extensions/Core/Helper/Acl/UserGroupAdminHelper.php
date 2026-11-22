<?php

namespace YTassy\Tycms\CoreExts\Core\Helper\Acl;

use YTassy\Tycms\DB\FormatIn;
use YTassy\Tycms\CoreExts\Core\Models\UserGroup;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\CoreExts\Core\Helper\TraitCoreAdditionalHelpers;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\Exceptions\AccessDeniedException;

class UserGroupAdminHelper extends BaseHelper
{
    use TraitCoreAdditionalHelpers;

    public function assertRootGroupAssignmentAllowed(int $groupId, bool $automatic = false): void
    {
        // Verrouiller chaque parent réel avant de relire les rôles : ni cache ACL ni copie du navigateur.
        $ids = [];
        $nextId = $groupId;
        while ($nextId !== null) {
            if (isset($ids[$nextId])) throw new \InvalidArgumentException('Hiérarchie de groupes cyclique.');
            $group = $this->getGroup($nextId, true);
            if ($group === null) throw new \InvalidArgumentException('Groupe introuvable.');
            $ids[$nextId] = true;
            $nextId = $group->parent_id;
        }

        // ROOT est reçu uniquement par les attributions globales du Space 1, parents de groupe compris.
        $roles = $this->db->select(<<<SQL
            SELECT DISTINCT role.id FROM acl_roles role
            INNER JOIN acl_map map ON map.role_id = role.id
            WHERE map.group_id IN (%) AND map.space_id = 1
                AND map.acl_object_id IS NULL AND map.object_id IS NULL
            ORDER BY role.id FOR UPDATE
            SQL, [FormatIn::get(array_keys($ids))]);
        foreach ($roles as $role) {
            if (in_array(Permissions::ROOT, $this->coreRoleAdminHelper->getPermissions((int) $role->id, true), true)
                && ($automatic || !$this->coreAclHelper->isRoot())) {
                throw new AccessDeniedException();
            }
        }
    }

    /** @return list<UserGroup> */
    public function getGroups(): array
    {
        return $this->db->selectOrm(UserGroup::class,
            'SELECT * FROM user_groups WHERE type = 0 ORDER BY space_id, name, id');
    }

    public function getGroup(int $groupId, bool $lock = false): ?UserGroup
    {
        return $this->db->selectOrmOneOrNull(UserGroup::class,
            'SELECT * FROM user_groups WHERE id = %' . ($lock ? ' FOR UPDATE' : ''), [$groupId]);
    }

    /** @return array<int, list<array{id:int,name:string,space_id:int,type:int}>> */
    public function getRoleAssignments(): array
    {
        // Une lecture groupée suffit pour les badges de tous les groupes.
        $rows = $this->db->select(<<<SQL
            SELECT DISTINCT map.group_id, role.id, role.name, role.space_id, role.type
            FROM acl_map map
            INNER JOIN user_groups `group` ON `group`.id = map.group_id
            INNER JOIN acl_roles role ON role.id = map.role_id
            WHERE `group`.type = 0 AND map.space_id = `group`.space_id
                AND map.acl_object_id IS NULL AND map.object_id IS NULL
            ORDER BY role.name, role.id
            SQL);

        $assignments = [];
        foreach ($rows as $row) {
            $assignments[(int) $row->group_id][] = [
                'id' => (int) $row->id,
                'name' => $row->name,
                'space_id' => (int) $row->space_id,
                'type' => (int) $row->type,
            ];
        }

        return $assignments;
    }

    /** @return list<int> Rôles globaux du Space propriétaire, seuls remplacés par l'éditeur. */
    public function getGlobalRoleIdsForGroup(int $groupId, int $spaceId): array
    {
        $rows = $this->db->select(<<<SQL
            SELECT DISTINCT map.role_id FROM acl_map map
            INNER JOIN acl_roles role ON role.id = map.role_id
            WHERE map.group_id = % AND map.space_id = % AND role.space_id = %
                AND role.type IN (0, 2) AND map.acl_object_id IS NULL AND map.object_id IS NULL
            ORDER BY map.role_id
            SQL, [$groupId, $spaceId, $spaceId]);

        return array_map(static fn (object $row): int => (int) $row->role_id, $rows);
    }

    /** @return list<array{id:int,name:string,space_id:int,type:int}> */
    public function getRoleAssignmentsForGroup(int $groupId): array
    {
        $rows = $this->db->select(<<<SQL
            SELECT DISTINCT role.id, role.name, role.space_id, role.type
            FROM acl_map map
            INNER JOIN user_groups `group` ON `group`.id = map.group_id
            INNER JOIN acl_roles role ON role.id = map.role_id
            WHERE map.group_id = % AND `group`.type = 0
                AND map.space_id = `group`.space_id
                AND map.acl_object_id IS NULL AND map.object_id IS NULL
            ORDER BY role.name, role.id
            SQL, [$groupId]);

        return array_map(static fn (object $row): array => [
            'id' => (int) $row->id,
            'name' => $row->name,
            'space_id' => (int) $row->space_id,
            'type' => (int) $row->type,
        ], $rows);
    }

    /**
     * Permissions globales reçues par chaque groupe et ses parents, par Space.
     * Le WF utilise ce périmètre pour contrôler la délégation avant une écriture.
     * @param list<int> $groupIds
     * @return array<int, array<int, list<string>>> groupId => spaceId => permissions
     */
    public function getGroupPermissionsBySpace(array $groupIds, bool $lock = false): array
    {
        if ($groupIds === []) return [];

        // Suivre les parents réels en base, avec verrou lorsque demandé.
        $chains = [];
        $allGroupIds = [];
        foreach ($groupIds as $groupId) {
            $chain = [];
            $nextId = $groupId;
            while ($nextId !== null) {
                if (isset($chain[$nextId])) {
                    throw new \InvalidArgumentException('Hiérarchie de groupes cyclique.');
                }
                $group = $this->getGroup($nextId, $lock);
                if ($group === null) {
                    throw new \InvalidArgumentException('Groupe introuvable.');
                }
                $chain[$nextId] = true;
                $allGroupIds[$nextId] = true;
                $nextId = $group->parent_id;
            }
            $chains[$groupId] = $chain;
        }

        // Lire les attributions globales réelles des groupes de chaque chaîne.
        // Le groupe est déjà verrouillé par l'appelant ; les rôles le seront ensuite.
        $assignments = $this->db->select(
            'SELECT group_id, space_id, role_id FROM acl_map '
            . 'WHERE group_id IN (%) AND space_id IS NOT NULL '
            . 'AND acl_object_id IS NULL AND object_id IS NULL '
            . 'ORDER BY group_id, space_id, role_id',
            [FormatIn::get(array_keys($allGroupIds))],
        );

        $roleIds = [];
        $rolesByGroupSpace = [];
        foreach ($assignments as $assignment) {
            $roleId = (int) $assignment->role_id;
            $roleIds[$roleId] = true;
            $rolesByGroupSpace[(int) $assignment->group_id][(int) $assignment->space_id][$roleId] = true;
        }

        // Verrouiller les définitions avant leurs permissions, comme les autres parcours.
        if ($lock) {
            $this->coreRoleAdminHelper->getRolesForUpdate(array_keys($roleIds));
        }
        $permissions = $this->coreRoleAdminHelper->getPermissionsForRoles(array_keys($roleIds), $lock);

        $result = [];
        foreach ($chains as $groupId => $chain) {
            $permissionsBySpace = [];
            foreach (array_keys($chain) as $chainId) {
                foreach ($rolesByGroupSpace[$chainId] ?? [] as $spaceId => $chainRoleIds) {
                    foreach (array_keys($chainRoleIds) as $roleId) {
                        foreach ($permissions[$roleId] ?? [] as $permission) {
                            $permissionsBySpace[$spaceId][$permission] = true;
                        }
                    }
                }
            }
            $result[$groupId] = array_map('array_keys', $permissionsBySpace);
        }

        return $result;
    }

    /** @param list<int> $roleIds */
    public function replaceRoleAssignments(int $groupId, int $spaceId, array $roleIds): void
    {
        // Protéger aussi le retrait de ROOT, pas seulement sa transmission.
        $this->assertRootGroupAssignmentAllowed($groupId);
        foreach ($roleIds as $roleId) {
            $this->coreRoleAdminHelper->assertRootAssignmentAllowed($roleId, $spaceId, $groupId);
        }

        // Ne remplacer que les rôles globaux du même Space. Les autres
        // attributions éventuelles restent intactes.
        $existing = $this->db->select(<<<SQL
            SELECT map.id, map.role_id FROM acl_map map
            INNER JOIN acl_roles role ON role.id = map.role_id
            WHERE map.group_id = % AND map.space_id = % AND role.space_id = %
                AND role.type IN (0, 2) AND map.acl_object_id IS NULL
                AND map.object_id IS NULL FOR UPDATE
            SQL, [$groupId, $spaceId, $spaceId]);

        $present = [];
        foreach ($existing as $row) {
            $roleId = (int) $row->role_id;
            if (!in_array($roleId, $roleIds, true) || isset($present[$roleId])) {
                $this->db->execute('DELETE FROM acl_map WHERE id = %', [(int) $row->id]);
            } else {
                $present[$roleId] = true;
            }
        }

        foreach ($roleIds as $roleId) {
            if (!isset($present[$roleId])) {
                $this->db->execute(
                    'INSERT INTO acl_map (space_id, role_id, group_id) VALUES (%, %, %)',
                    [$spaceId, $roleId, $groupId]
                );
            }
        }
    }

    /** @return list<int> Attributions exactes, sans les droits globaux ou de type hérités. */
    public function getObjectRoleIds(int $groupId, int $spaceId, int $aclObjectId, int $objectId): array
    {
        $rows = $this->db->select(<<<SQL
            SELECT DISTINCT role_id FROM acl_map
            WHERE group_id = % AND space_id = % AND acl_object_id = % AND object_id = %
            ORDER BY role_id
            SQL, [$groupId, $spaceId, $aclObjectId, $objectId]);

        return array_map(static fn (object $row): int => (int) $row->role_id, $rows);
    }

    /**
     * Remplacer uniquement les rôles du groupe sur cette instance d'objet.
     * Le WF doit verrouiller la ressource et les rôles ajoutés/retirés avant de
     * vérifier la délégation et leur périmètre métier. Transaction du runner.
     * @param list<int> $roleIds
     */
    public function replaceObjectRoleAssignments(int $groupId, int $spaceId,
        int $aclObjectId, int $objectId, array $roleIds): void
    {
        if (min($groupId, $spaceId, $aclObjectId, $objectId) <= 0
            || !array_is_list($roleIds) || count($roleIds) > 250) {
            throw new \InvalidArgumentException('Périmètre ou liste de rôles invalide.');
        }
        foreach ($roleIds as $roleId) {
            if (!is_int($roleId) || $roleId <= 0) {
                throw new \InvalidArgumentException('Identifiant de rôle invalide.');
            }
        }
        if (count($roleIds) !== count(array_unique($roleIds))) {
            throw new \InvalidArgumentException('Rôle dupliqué.');
        }

        // Le groupe porte le même Space réel que la ressource. Les liens des
        // groupes implicites restent fixes, y compris pour une attribution locale.
        $group = $this->getGroup($groupId, true);
        if ($group === null || $group->space_id !== $spaceId
            || !in_array($group->type, [0, 1], true) || in_array($groupId, [1, 2], true)) {
            throw new \InvalidArgumentException('Groupe incompatible avec cette attribution.');
        }

        // Vérifier les rôles enregistrés, sans accepter une copie fournie par le client.
        // Le WF a déjà verrouillé ces rôles avant le contrôle de leurs permissions.
        sort($roleIds);
        if ($roleIds !== []) {
            $roles = $this->db->select(<<<SQL
                SELECT id, space_id, type FROM acl_roles WHERE id IN (%) ORDER BY id FOR UPDATE
                SQL, [FormatIn::get($roleIds)]);
            if (count($roles) !== count($roleIds)) {
                throw new \InvalidArgumentException('Rôle introuvable.');
            }
            foreach ($roles as $role) {
                if ((int) $role->space_id !== $spaceId || !in_array((int) $role->type, [1, 2], true)) {
                    throw new \InvalidArgumentException('Seuls les rôles locaux du même Space sont attribuables à un objet.');
                }
                $this->coreRoleAdminHelper->assertRootAssignmentAllowed((int) $role->id,
                    $spaceId, $groupId, onObject: true);
            }
        }

        // Les quatre clés de périmètre empêchent de toucher un autre objet,
        // un autre groupe ou les attributions globales existantes.
        $scope = [$groupId, $spaceId, $aclObjectId, $objectId];
        $this->db->execute(<<<SQL
            DELETE FROM acl_map
            WHERE group_id = % AND space_id = % AND acl_object_id = % AND object_id = %
            SQL, $scope);
        foreach ($roleIds as $roleId) {
            $this->db->execute(<<<SQL
                INSERT INTO acl_map (group_id, space_id, acl_object_id, object_id, role_id)
                VALUES (%, %, %, %, %)
                SQL, [...$scope, $roleId]);
        }
    }

    public function hasName(int $spaceId, ?int $parentId, string $name, ?int $exceptId = null): bool
    {
        $sql = 'SELECT id FROM user_groups WHERE space_id = % AND type = 0 AND parent_id <=> % AND name = %';
        $values = [$spaceId, $parentId, $name];
        if ($exceptId !== null) {
            $sql .= ' AND id != %';
            $values[] = $exceptId;
        }

        return $this->db->selectOneOrNull($sql . ' LIMIT 1', $values) !== null;
    }

    public function create(int $spaceId, ?int $parentId, string $name, string $description): int
    {
        if ($parentId !== null) $this->assertRootGroupAssignmentAllowed($parentId);

        $this->db->execute(
            'INSERT INTO user_groups (space_id, parent_id, type, name, description) VALUES (%, %, 0, %, %)',
            [$spaceId, $parentId, $name, $description]
        );

        return $this->db->getLastInsertId();
    }

    public function update(int $groupId, ?int $parentId, string $name, string $description): void
    {
        $this->assertRootGroupAssignmentAllowed($groupId);
        $group = $this->getGroup($groupId, true);
        if ($group === null) throw new \InvalidArgumentException('Groupe introuvable.');
        if ($parentId !== null && $parentId !== $group->parent_id) {
            $this->assertRootGroupAssignmentAllowed($parentId);
        }

        $this->db->execute(
            'UPDATE user_groups SET parent_id = %, name = %, description = %, updated = CURRENT_TIMESTAMP WHERE id = %',
            [$parentId, $name, $description, $groupId]
        );
    }

    public function delete(int $groupId): void
    {
        $this->db->execute('DELETE FROM user_groups WHERE id = %', [$groupId]);
    }

    public function hasDatabaseReferences(int $groupId): bool
    {
        foreach (['SELECT id FROM user_groups WHERE parent_id = % LIMIT 1',
            'SELECT id FROM user_groups_map WHERE group_id = % LIMIT 1',
            'SELECT id FROM acl_map WHERE group_id = % LIMIT 1'] as $sql) {
            if ($this->db->selectOneOrNull($sql, [$groupId]) !== null) {
                return true;
            }
        }

        return false;
    }

    public function hasSpaceReference(int $groupId): bool
    {
        // Les options des Spaces sont dans du JSON, sans clé étrangère.
        foreach ($this->db->select('SELECT params FROM spaces FOR UPDATE') as $space) {
            $params = json_decode($space->params, true);
            if (!is_array($params)) {
                continue;
            }

            foreach (['registration_groups', 'user_groups'] as $setting) {
                if (is_array($params[$setting] ?? null)
                    && in_array($groupId, $params[$setting], false)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array<int, true> */
    public function getReferencedGroupIds(): array
    {
        $used = [];
        foreach (['SELECT DISTINCT parent_id AS id FROM user_groups WHERE parent_id IS NOT NULL',
            'SELECT DISTINCT group_id AS id FROM user_groups_map',
            'SELECT DISTINCT group_id AS id FROM acl_map'] as $sql) {
            foreach ($this->db->select($sql) as $row) {
                $used[(int) $row->id] = true;
            }
        }

        // Lire les paramètres une seule fois pour tout le répertoire.
        foreach ($this->db->select('SELECT params FROM spaces') as $space) {
            $params = json_decode($space->params, true);
            if (!is_array($params)) {
                continue;
            }
            foreach (['registration_groups', 'user_groups'] as $setting) {
                foreach (is_array($params[$setting] ?? null) ? $params[$setting] : [] as $groupId) {
                    $id = filter_var($groupId, FILTER_VALIDATE_INT);
                    if ($id !== false && $id > 0) {
                        $used[$id] = true;
                    }
                }
            }
        }

        return $used;
    }
}
