<?php

namespace YTassy\Tycms\CoreExts\Core\Helper;

use YTassy\Tycms\DB\FormatIn;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\UserGroups;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Roles;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;

final class UserAdminHelper extends BaseHelper
{
    use TraitCoreAdditionalHelpers;

    /** @param list<int> $userIds @return list<int> */
    public function getRootUserIds(array $userIds, bool $lock = false): array
    {
        if ($userIds === []) return [];

        // Lire uniquement ROOT en une requête pour la page entière, parents de groupe compris.
        // La variante verrouillée contrôle les écritures sans réutiliser un instantané ACL.
        $rows = $this->db->select(<<<SQL
            WITH RECURSIVE groups_for_users AS (
                SELECT membership.user_id, user_group.id, user_group.parent_id
                FROM user_groups_map membership
                INNER JOIN user_groups user_group ON user_group.id = membership.group_id
                WHERE membership.user_id IN (%)
                UNION DISTINCT
                SELECT child.user_id, parent.id, parent.parent_id FROM user_groups parent
                INNER JOIN groups_for_users child ON child.parent_id = parent.id
            )
            SELECT DISTINCT user_group.user_id FROM groups_for_users user_group
            INNER JOIN acl_map assignment ON assignment.group_id = user_group.id
            INNER JOIN acl_permissions permission ON permission.role_id = assignment.role_id
            WHERE assignment.space_id = 1 AND assignment.acl_object_id IS NULL AND assignment.object_id IS NULL
                AND permission.extension = 'plg_core' AND permission.name = 'ROOT'
            SQL . ($lock ? ' FOR UPDATE' : ''), [FormatIn::get($userIds)]);

        return array_map(static fn (object $row): int => (int) $row->user_id, $rows);
    }

    /** @param list<int> $spaceIds */
    public function listUsers(array $spaceIds, int $page, int $perPage, string $search, ?int $spaceId): array
    {
        $where = ' WHERE u.space_id IN (%)';
        $values = [FormatIn::get($spaceIds)];

        if ($spaceId !== null) {
            $where .= ' AND u.space_id = %';
            $values[] = $spaceId;
        }

        if ($search !== '') {
            $where .= " AND (u.email LIKE % ESCAPE '!' OR u.first_name LIKE % ESCAPE '!'"
                . " OR u.last_name LIKE % ESCAPE '!')";
            $term = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';
            array_push($values, $term, $term, $term);
        }

        // Le total et les lignes sont filtrés avant de charger les relations de la seule page.
        $count = $this->db->selectOneOrNull('SELECT COUNT(*) AS total FROM users u' . $where, $values);
        $rows = $this->db->select(
            'SELECT u.id, u.space_id, u.first_name, u.last_name, u.email, u.timezone, '
            . 'u.blocked, u.send_email, u.register_date FROM users u' . $where
            . ' ORDER BY u.last_name, u.first_name, u.id LIMIT % OFFSET %',
            [...$values, $perPage, ($page - 1) * $perPage]
        );

        return ['total' => (int) $count->total, 'rows' => $rows];
    }

    public function getUser(int $userId, bool $lock = false): ?object
    {
        return $this->db->selectOneOrNull(
            'SELECT id, space_id, first_name, last_name, email, timezone, blocked, send_email, '
            . 'register_date FROM users WHERE id = %' . ($lock ? ' FOR UPDATE' : ''),
            [$userId]
        );
    }

    public function emailExists(string $email, ?int $exceptId = null): bool
    {
        return $this->db->selectOneOrNull(
            'SELECT id FROM users WHERE email = % AND (% IS NULL OR id != %) LIMIT 1',
            [$email, $exceptId, $exceptId]
        ) !== null;
    }

    /** @param list<int> $userIds */
    public function getRelations(array $userIds): array
    {
        if ($userIds === []) return [];

        $relations = [];
        foreach ($userIds as $id) {
            $relations[$id] = ['spaces' => [], 'inherited_spaces' => [], 'groups' => [], 'roles' => []];
        }

        // Chercher la permission MEMBER dans tous les rôles des groupes et de leurs parents.
        // Ces espaces sources ne comprennent pas encore l’héritage entre Spaces.
        $memberRows = $this->db->select(<<<SQL
            WITH RECURSIVE user_groups_tree AS (
                SELECT gm.user_id, ug.id, ug.parent_id
                FROM user_groups_map gm
                INNER JOIN user_groups ug ON ug.id = gm.group_id
                WHERE gm.user_id IN (%)
                UNION ALL
                SELECT child.user_id, parent.id, parent.parent_id
                FROM user_groups_tree child
                INNER JOIN user_groups parent ON parent.id = child.parent_id
            )
            SELECT DISTINCT tree.user_id, map.space_id
            FROM user_groups_tree tree
            INNER JOIN acl_map map ON map.group_id = tree.id
            INNER JOIN acl_permissions permission ON permission.role_id = map.role_id
            WHERE permission.extension = % AND permission.name = % AND map.space_id IS NOT NULL
                AND map.acl_object_id IS NULL AND map.object_id IS NULL
            SQL, [FormatIn::get($userIds), 'plg_core', 'MEMBER']);
        foreach ($memberRows as $row) {
            $relations[(int) $row->user_id]['spaces'][] = (int) $row->space_id;
        }

        // Calculer les appartenances effectives côté serveur, sans requête par utilisateur.
        $ancestorsBySpace = [];
        foreach ($this->coreSpaceHelper->getAllSpaceIds() as $spaceId) {
            $ancestorsBySpace[$spaceId] = $this->coreSpaceHelper->getSpaceIdsWithParents($spaceId);
        }
        foreach ($relations as &$relation) {
            $sources = $relation['spaces'];
            $relation['spaces'] = [];
            foreach ($ancestorsBySpace as $spaceId => $ancestors) {
                if (array_intersect($ancestors, $sources) === []) continue;
                $relation['spaces'][] = $spaceId;
                if (!in_array($spaceId, $sources, true)) {
                    $relation['inherited_spaces'][] = $spaceId;
                }
            }
            sort($relation['spaces']);
            sort($relation['inherited_spaces']);
        }
        unset($relation);

        $groups = $this->db->select(<<<SQL
            SELECT gm.user_id, ug.id, ug.name, ug.space_id
            FROM user_groups_map gm
            INNER JOIN user_groups ug ON ug.id = gm.group_id
            WHERE gm.user_id IN (%) AND ug.type = 0
            ORDER BY ug.name, ug.id
            SQL, [FormatIn::get($userIds)]);
        foreach ($groups as $group) {
            $relations[(int) $group->user_id]['groups'][] = [
                'id' => (int) $group->id, 'name' => $group->name,
                'space_id' => (int) $group->space_id,
            ];
        }

        $roles = $this->db->select(<<<SQL
            SELECT gm.user_id, map.id AS assignment_id, map.space_id AS target_space_id,
                role.id, role.name, role.space_id, role.type
            FROM user_groups_map gm
            INNER JOIN user_groups ug ON ug.id = gm.group_id AND ug.type = 1
            INNER JOIN acl_map map ON map.group_id = ug.id
            INNER JOIN acl_roles role ON role.id = map.role_id
            WHERE gm.user_id IN (%) AND map.space_id IS NOT NULL
                AND map.acl_object_id IS NULL AND map.object_id IS NULL
            ORDER BY role.name, role.id
            SQL, [FormatIn::get($userIds)]);
        foreach ($roles as $role) {
            $relations[(int) $role->user_id]['roles'][] = [
                'id' => (int) $role->id, 'name' => $role->name,
                'space_id' => (int) $role->space_id,
                'target_space_id' => (int) $role->target_space_id,
                'type' => (int) $role->type,
            ];
        }

        return $relations;
    }

    public function getPersonalGroupId(int $userId, bool $lock = false): ?int
    {
        $row = $this->db->selectOneOrNull(<<<SQL
            SELECT ug.id FROM user_groups_map gm
            INNER JOIN user_groups ug ON ug.id = gm.group_id
            WHERE gm.user_id = % AND ug.type = 1 AND ug.parent_id = %
            LIMIT 1
            SQL . ($lock ? ' FOR UPDATE' : ''), [$userId, UserGroups::GROUP_USERS]);

        return $row === null ? null : (int) $row->id;
    }

    public function getRole(int $roleId): ?object
    {
        return $this->db->selectOneOrNull(
            'SELECT id, name, space_id, type FROM acl_roles WHERE id = %', [$roleId]
        );
    }

    public function getGroup(int $groupId): ?object
    {
        return $this->db->selectOneOrNull(
            'SELECT id, name, space_id, type FROM user_groups WHERE id = %', [$groupId]
        );
    }

    public function getCatalog(): array
    {
        return [
            'roles' => $this->db->select('SELECT id, name, space_id, type FROM acl_roles '
                . 'WHERE type IN (0, 2) AND id NOT IN (%) ORDER BY name, id',
                [FormatIn::get(Roles::getImplicitIds())]),
            'groups' => $this->db->select('SELECT id, name, space_id FROM user_groups '
                . 'WHERE type = 0 AND id NOT IN (%) ORDER BY name, id',
                [FormatIn::get(UserGroups::getImplicitIds())]),
        ];
    }

    /** @return array{roles:list<array>, groups:list<array>} */
    public function getAssignmentCatalog(int $spaceId, bool $withRoles, bool $withGroups): array
    {
        // Les colonnes décrivent seulement les ressources créées dans le Space choisi.
        $roles = $withRoles ? $this->db->select(
            'SELECT id, name FROM acl_roles WHERE space_id = % AND type IN (0, 2) AND id NOT IN (%) ORDER BY name, id',
            [$spaceId, FormatIn::get(Roles::getImplicitIds())]
        ) : [];
        $groups = $withGroups ? $this->db->select(
            'SELECT id, name FROM user_groups WHERE space_id = % AND type = 0 AND id NOT IN (%) ORDER BY name, id',
            [$spaceId, FormatIn::get(UserGroups::getImplicitIds())]
        ) : [];
        $present = static fn (object $row): array => ['id' => (int) $row->id, 'name' => $row->name,
            'space_id' => $spaceId];

        return ['roles' => array_map($present, $roles), 'groups' => array_map($present, $groups)];
    }

    /** @param list<int> $userIds @return array<int, array{role_ids:list<int>, group_ids:list<int>}> */
    public function getDirectAssignments(array $userIds, int $spaceId, bool $withRoles, bool $withGroups, bool $lock = false): array
    {
        if ($userIds === []) return [];

        $result = [];
        foreach ($userIds as $id) $result[$id] = ['role_ids' => [], 'group_ids' => []];

        // Aucune permission effective ni aucun héritage n'est calculé pour les comptes listés.
        if ($withRoles) {
            $rows = $this->db->select(<<<SQL
                SELECT DISTINCT gm.user_id, map.role_id
                FROM user_groups_map gm
                INNER JOIN user_groups personal ON personal.id = gm.group_id AND personal.type = 1
                INNER JOIN acl_map map ON map.group_id = personal.id
                INNER JOIN acl_roles role ON role.id = map.role_id
                WHERE gm.user_id IN (%) AND map.space_id = % AND role.space_id = %
                    AND role.type IN (0, 2) AND map.acl_object_id IS NULL AND map.object_id IS NULL
                ORDER BY gm.user_id, map.role_id
                SQL . ($lock ? ' FOR UPDATE' : ''), [FormatIn::get($userIds), $spaceId, $spaceId]);
            foreach ($rows as $row) $result[(int) $row->user_id]['role_ids'][] = (int) $row->role_id;
        }

        if ($withGroups) {
            $rows = $this->db->select(<<<SQL
                SELECT DISTINCT gm.user_id, ug.id
                FROM user_groups_map gm
                INNER JOIN user_groups ug ON ug.id = gm.group_id
                WHERE gm.user_id IN (%) AND ug.space_id = % AND ug.type = 0
                ORDER BY gm.user_id, ug.id
                SQL . ($lock ? ' FOR UPDATE' : ''), [FormatIn::get($userIds), $spaceId]);
            foreach ($rows as $row) $result[(int) $row->user_id]['group_ids'][] = (int) $row->id;
        }

        return $result;
    }

    public function updateUser(int $id, string $firstName, string $lastName, string $email,
        string $timezone, bool $blocked, bool $sendEmail): void
    {
        $this->db->execute(<<<SQL
            UPDATE users SET reset_password_token = IF(email <> % OR % = 1, '', reset_password_token),
                first_name = %, last_name = %, email = %, timezone = %,
                blocked = %, send_email = %, updated = CURRENT_TIMESTAMP WHERE id = %
            SQL, [$email, $blocked, $firstName, $lastName, $email, $timezone, $blocked, $sendEmail, $id]);

        if ($blocked) {
            // Révoquer immédiatement les sessions déjà ouvertes du compte bloqué.
            $this->sessionManager->deleteUserSessions($id);
        }
    }

    public function assignRole(int $groupId, int $roleId, int $spaceId): void
    {
        $this->coreRoleAdminHelper->assertRootAssignmentAllowed($roleId, $spaceId, $groupId);

        $exists = $this->db->selectOneOrNull(<<<SQL
            SELECT id FROM acl_map WHERE group_id = % AND role_id = % AND space_id = %
                AND acl_object_id IS NULL AND object_id IS NULL LIMIT 1 FOR UPDATE
            SQL, [$groupId, $roleId, $spaceId]);
        if ($exists === null) {
            $this->db->execute('INSERT INTO acl_map (group_id, role_id, space_id) VALUES (%, %, %)',
                [$groupId, $roleId, $spaceId]);
        }
    }

    public function removeRole(int $groupId, int $roleId, int $spaceId): void
    {
        $this->db->execute(<<<SQL
            DELETE FROM acl_map WHERE group_id = % AND role_id = % AND space_id = %
                AND acl_object_id IS NULL AND object_id IS NULL
            SQL, [$groupId, $roleId, $spaceId]);
    }

    public function assignGroup(int $userId, int $groupId): void
    {
        $this->coreUserGroupAdminHelper->assertRootGroupAssignmentAllowed($groupId);

        $exists = $this->db->selectOneOrNull(
            'SELECT id FROM user_groups_map WHERE user_id = % AND group_id = % LIMIT 1 FOR UPDATE',
            [$userId, $groupId]);
        if ($exists === null) {
            $this->db->execute('INSERT INTO user_groups_map (user_id, group_id) VALUES (%, %)',
                [$userId, $groupId]);
        }
    }

    public function removeGroup(int $userId, int $groupId): void
    {
        $this->db->execute('DELETE FROM user_groups_map WHERE user_id = % AND group_id = %',
            [$userId, $groupId]);
    }

    public function getSsoLinks(int $userId): array
    {
        return $this->db->select(<<<SQL
            SELECT link.id, provider.name, provider.space_id
            FROM sso_links link
            INNER JOIN sso_providers provider ON provider.id = link.provider_id
            WHERE link.user_id = % ORDER BY provider.name, link.id
            SQL, [$userId]);
    }

    public function removeSsoLink(int $userId, int $linkId): bool
    {
        $link = $this->db->selectOneOrNull(
            'SELECT provider_id FROM sso_links WHERE id = % AND user_id = % FOR UPDATE',
            [$linkId, $userId]);
        if ($link === null) return false;

        $this->db->execute('DELETE FROM sso_links WHERE id = % AND user_id = %', [$linkId, $userId]);

        // Un jeton d'intégration peut rester utile si un autre lien du même fournisseur subsiste.
        $remaining = $this->db->selectOneOrNull(
            'SELECT id FROM sso_links WHERE user_id = % AND provider_id = % LIMIT 1',
            [$userId, (int) $link->provider_id]);
        if ($remaining === null) {
            $this->db->execute('DELETE FROM oauth_grants WHERE user_id = % AND provider_id = %',
                [$userId, (int) $link->provider_id]);
        }

        return true;
    }

    public function deleteUser(int $userId, ?int $personalGroupId): void
    {
        // Les autres tables référencent users avec suppression en cascade.
        $this->db->execute('DELETE FROM users WHERE id = %', [$userId]);
        if ($personalGroupId === null) return;

        $otherOwner = $this->db->selectOneOrNull(
            'SELECT id FROM user_groups_map WHERE group_id = % LIMIT 1', [$personalGroupId]);
        if ($otherOwner === null) {
            $this->db->execute('DELETE FROM user_groups WHERE id = % AND type = 1', [$personalGroupId]);
        }
    }
}
