<?php

namespace YTassy\Tycms\CoreExts\Core\Helper\Acl;

use YTassy\Tycms\CoreExts\Core\Models\AclRole;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Roles;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\RootPermissionPolicy;
use YTassy\Tycms\DB\FormatIn;
use YTassy\Tycms\Exceptions\AccessDeniedException;
use InvalidArgumentException;

class RoleAdminHelper extends BaseHelper
{
    public function getRole(int $roleId, bool $lock = false): ?AclRole
    {
        $sql = 'SELECT r.*, s.title AS space_title FROM acl_roles r '
            . 'INNER JOIN spaces s ON s.id = r.space_id WHERE r.id = %';

        return $this->db->selectOrmOneOrNull(AclRole::class, $sql . ($lock ? ' FOR UPDATE' : ''), [$roleId]);
    }

    /** @return list<string> */
    public function getPermissions(int $roleId, bool $lock = false): array
    {
        $rows = $this->db->select(
            'SELECT extension, name FROM acl_permissions WHERE role_id = % ORDER BY extension, name' . ($lock ? ' FOR UPDATE' : ''),
            [$roleId],
        );

        return array_map(static fn (object $row): string => $row->extension . '__' . $row->name, $rows);
    }

    /**
     * Permissions des rôles demandés, en une lecture groupée.
     * @param list<int> $roleIds
     * @return array<int, list<string>>
     */
    public function getPermissionsForRoles(array $roleIds, bool $lock = false): array
    {
        if ($roleIds === []) return [];

        $rows = $this->db->select(
            'SELECT role_id, extension, name FROM acl_permissions WHERE role_id IN (%) '
            . 'ORDER BY role_id, extension, name' . ($lock ? ' FOR UPDATE' : ''),
            [FormatIn::get($roleIds)],
        );

        $permissions = array_fill_keys($roleIds, []);
        foreach ($rows as $row) {
            $permissions[(int) $row->role_id][] = $row->extension . '__' . $row->name;
        }

        return $permissions;
    }

    /**
     * Relire et verrouiller les rôles avant de contrôler leurs permissions.
     * @param list<int> $roleIds
     * @return array<int, AclRole>
     */
    public function getRolesForUpdate(array $roleIds): array
    {
        if ($roleIds === []) return [];

        $roles = $this->db->selectOrm(AclRole::class,
            'SELECT r.*, s.title AS space_title FROM acl_roles r '
            . 'INNER JOIN spaces s ON s.id = r.space_id WHERE r.id IN (%) '
            . 'ORDER BY r.id FOR UPDATE', [FormatIn::get($roleIds)]);

        return array_column($roles, null, 'id');
    }

    public function hasName(int $spaceId, string $name, ?int $exceptRoleId = null): bool
    {
        $sql = 'SELECT id FROM acl_roles WHERE space_id = % AND name = %';
        $values = [$spaceId, $name];

        if ($exceptRoleId !== null) {
            $sql .= ' AND id != %';
            $values[] = $exceptRoleId;
        }

        return $this->db->selectOneOrNull($sql . ' LIMIT 1', $values) !== null;
    }

    public function createRole(int $spaceId, string $name, string $description, int $type): int
    {
        $this->db->execute(
            'INSERT INTO acl_roles (space_id, user_defined, name, description, type) VALUES (%, 1, %, %, %)',
            [$spaceId, $name, $description, $type],
        );

        return $this->db->getLastInsertId();
    }

    public function updateRole(int $roleId, string $name, string $description): void
    {
        $this->db->execute(
            'UPDATE acl_roles SET name = %, description = %, updated = CURRENT_TIMESTAMP WHERE id = %',
            [$name, $description, $roleId],
        );
    }

    /** @param list<string> $permissions */
    public function replacePermissions(int $roleId, array $permissions): void
    {
        // Protéger aussi les appelants internes, avant toute suppression des permissions existantes.
        if ($this->coreAclHelper->getPermissionRequirements()->missingFrom($permissions) !== []) {
            throw new InvalidArgumentException('Les prérequis des permissions doivent appartenir au même rôle.');
        }

        $role = $this->getRole($roleId, true);
        if ($role === null) throw new InvalidArgumentException('Rôle introuvable.');

        // Un appel interne ne peut pas contourner la protection en retirant ROOT d'un rôle personnalisé.
        $wasRoot = in_array(Permissions::ROOT, $this->getPermissions($roleId, true), true);
        if ($wasRoot && !$this->coreAclHelper->isRoot()) throw new AccessDeniedException();

        if (in_array(Permissions::ROOT, $permissions, true) || $roleId === Roles::ROLE_ROOT) {
            if ($roleId === Roles::ROLE_ROOT && !in_array(Permissions::ROOT, $permissions, true)) {
                throw new AccessDeniedException();
            }
            $this->assertRootRoleScope($permissions, $role->space_id, $role->type, $roleId);
        }

        $this->db->execute('DELETE FROM acl_permissions WHERE role_id = %', [$roleId]);

        foreach ($permissions as $permission) {
            [$extension, $name] = explode('__', $permission, 2);
            $this->db->execute(
                'INSERT INTO acl_permissions (role_id, extension, name) VALUES (%, %, %)',
                [$roleId, $extension, $name],
            );
        }
    }

    public function deleteRole(int $roleId): void
    {
        $this->db->execute('DELETE FROM acl_roles WHERE id = %', [$roleId]);
    }

    /** @param list<string> $permissions Le WF a verrouillé le rôle avant de contrôler ses attributions. */
    public function assertRootRoleScope(array $permissions, int $spaceId, int $type, ?int $roleId = null): void
    {
        if (!RootPermissionPolicy::allowsRole($permissions, $spaceId, $type, $roleId)) {
            throw new AccessDeniedException();
        }
        if (!in_array(Permissions::ROOT, $permissions, true)) return;
        if (!$this->coreAclHelper->isRoot()) throw new AccessDeniedException();
        if ($roleId === null) return;

        // Empêcher qu'un rôle déjà attribué hors racine ou sur un objet devienne un rôle ROOT.
        if ($this->db->selectOneOrNull(<<<SQL
            SELECT id FROM acl_map WHERE role_id = % AND (
                space_id IS NULL OR space_id <> 1 OR acl_object_id IS NOT NULL OR object_id IS NOT NULL
                OR group_id IN (1, 2)
            ) LIMIT 1 FOR UPDATE
            SQL, [$roleId]) !== null) {
            throw new AccessDeniedException();
        }
    }

    public function assertRootAssignmentAllowed(int $roleId, int $spaceId, int $groupId,
        bool $automatic = false, bool $onObject = false): void
    {
        // Ce verrou sérialise l'attribution avec une modification des permissions du rôle.
        $role = $this->getRole($roleId, true);
        if ($role === null) throw new InvalidArgumentException('Rôle introuvable.');
        $permissions = $this->getPermissions($roleId, true);
        if (!in_array(Permissions::ROOT, $permissions, true)) return;

        if (!RootPermissionPolicy::allowsRole($permissions, $role->space_id, $role->type, $roleId)
            || !RootPermissionPolicy::allowsAssignment($permissions, $spaceId, $groupId,
                $this->coreAclHelper->isRoot(), $automatic, $onObject)) {
            throw new AccessDeniedException();
        }
    }

    public function hasAssignments(int $roleId): bool
    {
        return $this->db->selectOneOrNull('SELECT id FROM acl_map WHERE role_id = % LIMIT 1', [$roleId]) !== null;
    }

    public function hasReferences(int $roleId): bool
    {
        foreach (['SELECT id FROM menu_items WHERE access_role_id = % LIMIT 1',
            'SELECT id FROM pages WHERE access_edit_role_id = % LIMIT 1'] as $sql) {
            if ($this->db->selectOneOrNull($sql, [$roleId]) !== null) return true;
        }

        // Les listes d'inscription sont stockées dans le JSON des espaces.
        // Leur référence n'est donc pas couverte par une clé étrangère.
        foreach ($this->db->select('SELECT params FROM spaces') as $space) {
            $params = json_decode($space->params, true);
            foreach (['registration_roles', 'user_roles'] as $setting) {
                foreach (($params[$setting] ?? []) as $roles) {
                    if (is_array($roles) && in_array($roleId, $roles, false)) return true;
                }
            }
        }

        // Les composants de page conservent aussi des listes role_ids dans data.
        foreach ($this->db->select('SELECT data FROM pages WHERE data IS NOT NULL') as $page) {
            if ($this->pageDataUsesRole(json_decode($page->data, true), $roleId)) return true;
        }

        return false;
    }

    private function pageDataUsesRole(mixed $node, int $roleId): bool
    {
        if (!is_array($node)) return false;

        foreach ($node as $key => $value) {
            if ($key === 'role_ids' && is_array($value) && in_array($roleId, $value, false)) {
                return true;
            }
            if ($this->pageDataUsesRole($value, $roleId)) return true;
        }

        return false;
    }

    /** @return list<int> */
    public function getAssignmentSpaceIds(int $roleId): array
    {
        $rows = $this->db->select(
            'SELECT DISTINCT space_id FROM acl_map WHERE role_id = % AND space_id IS NOT NULL',
            [$roleId],
        );

        return array_map(static fn (object $row): int => (int) $row->space_id, $rows);
    }
}
