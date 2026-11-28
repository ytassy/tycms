<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Helper\Acl;

use InvalidArgumentException;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\DB\FormatIn;

/**
 * Énumération des comptes qui possèdent un droit effectif.
 *
 * `AclHelper` répond « ce compte a-t-il ce droit ? ». Ce resolver répond à la
 * question inverse pour une audience de notification : « quels comptes ont ce
 * droit ? ». Il ne teste jamais les comptes un par un.
 *
 * Les groupes et les utilisateurs sont résolus par **une seule requête** :
 * une CTE récursive part des affectations utiles et descend l’arbre des
 * groupes, puis joint `user_groups_map` et `users`. Le nombre de groupes
 * personnels (un par compte, type 1) n’a donc aucun coût par groupe : le
 * `LIMIT` porte sur l’énumération finale des comptes, pas sur les groupes.
 * `STRAIGHT_JOIN` impose l’ordre affectations → groupes → comptes : un scan de
 * `users` ferait perdre l’intérêt du `LIMIT`.
 *
 * Le `limit` est obligatoire. Les ensembles intermédiaires ne sont pas
 * tronqués : seule la liste des utilisateurs l’est, pour qu’une audience au
 * plafond puisse être distinguée d’une audience trop grande. L’appelant qui a
 * besoin de cette distinction passe `limit + 1`.
 *
 * Les ancêtres du Space sont pris en compte, comme `AclHelper` : un rôle ou un
 * droit accordé sur un Space parent vaut pour ses descendants. Les comptes
 * bloqués et de service sont écartés, et les identifiants sont dédupliqués.
 */
class AclUserResolver extends BaseHelper
{
    /** Nombre maximal d’identifiants par requête `IN`. */
    public const MAX_IDS_PER_QUERY = 1000;

    /**
     * Comptes ayant la permission globale sur le Space ou un de ses ancêtres.
     *
     * @return list<int>
     */
    public function usersWithGlobalPermission(string $permission, int $spaceId, int $limit): array
    {
        $this->assertLimit($limit);

        if ($spaceId <= 0) {
            return [];
        }

        $roleIds = $this->roleIdsForPermission($permission);
        if ($roleIds === []) {
            return [];
        }

        return $this->resolve($roleIds, $spaceId, null, null, $limit);
    }

    /**
     * Comptes ayant la permission sur l’objet, au titre d’un droit global, du
     * type d’objet ou de cette instance.
     *
     * @return list<int>
     */
    public function usersWithPermissionOnObject(
        string $permission,
        string $extension,
        string $object,
        int $objectId,
        int $spaceId,
        int $limit,
    ): array {
        $this->assertLimit($limit);

        if ($spaceId <= 0 || $objectId <= 0) {
            return [];
        }

        $aclObjectId = $this->coreAclHelper->getObjectTypeId($extension, $object);
        if ($aclObjectId === null) {
            return [];
        }

        $roleIds = $this->roleIdsForPermission($permission);
        if ($roleIds === []) {
            return [];
        }

        return $this->resolve($roleIds, $spaceId, $aclObjectId, $objectId, $limit);
    }

    /**
     * Comptes ayant le rôle global sur le Space ou un de ses ancêtres.
     *
     * @return list<int>
     */
    public function usersWithGlobalRole(int $roleId, int $spaceId, int $limit): array
    {
        $this->assertLimit($limit);

        if ($roleId <= 0 || $spaceId <= 0) {
            return [];
        }

        return $this->resolve([$roleId], $spaceId, null, null, $limit);
    }

    /**
     * Comptes ayant le rôle sur l’objet, au titre d’un rôle global, d’un rôle
     * de type ou d’un rôle d’instance.
     *
     * @return list<int>
     */
    public function usersWithRoleOnObject(
        int $roleId,
        string $extension,
        string $object,
        int $objectId,
        int $spaceId,
        int $limit,
    ): array {
        $this->assertLimit($limit);

        if ($roleId <= 0 || $spaceId <= 0 || $objectId <= 0) {
            return [];
        }

        $aclObjectId = $this->coreAclHelper->getObjectTypeId($extension, $object);
        if ($aclObjectId === null) {
            return [];
        }

        return $this->resolve([$roleId], $spaceId, $aclObjectId, $objectId, $limit);
    }

    /**
     * Identifiants de rôles porteurs d’une permission complète.
     *
     * @return list<int>
     */
    private function roleIdsForPermission(string $permission): array
    {
        $this->assertPermissionName($permission);

        [$extension, $name] = explode('__', $permission, 2);

        $rows = $this->db->select(
            'SELECT DISTINCT role_id FROM acl_permissions WHERE extension = % AND name = %',
            [$extension, $name],
        );

        return array_map(static fn (object $row): int => (int) $row->role_id, $rows);
    }

    /**
     * Énumère l’audience d’un ensemble de rôles pour un Space.
     *
     * Une seule requête suffit quel que soit le nombre de groupes concernés :
     * la CTE récursive calcule la fermeture descendante, puis la jointure finale
     * porte le `LIMIT`. Les rôles et les Spaces sont découpés par sécurité, mais
     * il n’y a jamais une requête par groupe.
     *
     * @param list<int> $roleIds
     * @return list<int>
     */
    private function resolve(
        array $roleIds,
        int $spaceId,
        ?int $aclObjectId,
        ?int $objectId,
        int $limit,
    ): array {
        if ($spaceId <= 0) {
            return [];
        }

        // Le Space et ses ancêtres portent les affectations héritées.
        $spaceIds = $this->coreSpaceHelper->getSpaceIdsWithParents($spaceId);
        if ($spaceIds === []) {
            return [];
        }

        // Un droit global ne retient que les affectations globales. Un droit sur
        // objet réunit aussi celles du type et de l’instance, comme
        // `AclHelper::hasPermissionOn()`.
        $objectCondition = $aclObjectId === null
            ? 'acl_object_id IS NULL AND object_id IS NULL'
            : '(acl_object_id IS NULL AND object_id IS NULL)'
                . ' OR (acl_object_id = % AND (object_id IS NULL OR object_id = %))';

        $userIds = [];

        foreach (array_chunk($roleIds, self::MAX_IDS_PER_QUERY) as $roleChunk) {
            foreach (array_chunk($spaceIds, self::MAX_IDS_PER_QUERY) as $spaceChunk) {
                $remaining = $limit - count($userIds);
                if ($remaining <= 0) {
                    break 2;
                }

                $values = [FormatIn::get($roleChunk), FormatIn::get($spaceChunk)];
                if ($aclObjectId !== null) {
                    $values[] = $aclObjectId;
                    $values[] = $objectId;
                }

                // L'ordre des jointures est fixé : partir des affectations, puis
                // des groupes, puis des comptes. Sans cela, l'optimiseur peut
                // commencer par un scan de `users` et perdre le bénéfice du
                // LIMIT. La descente force l'index du parent de groupe.
                $rows = $this->db->select(
                    'WITH RECURSIVE beneficiaries AS (
                        SELECT DISTINCT group_id FROM acl_map
                        WHERE role_id IN (%) AND space_id IN (%) AND (' . $objectCondition . ')
                        UNION DISTINCT
                        SELECT child.id FROM user_groups child FORCE INDEX (idx_user_groups_parent1)
                        INNER JOIN beneficiaries parent ON child.parent_id = parent.group_id
                    )
                    SELECT STRAIGHT_JOIN DISTINCT gm.user_id
                    FROM beneficiaries
                    INNER JOIN user_groups_map gm ON gm.group_id = beneficiaries.group_id
                    INNER JOIN users u ON u.id = gm.user_id
                    WHERE u.blocked = 0 AND u.type <> \'service\'
                    ORDER BY gm.user_id
                    LIMIT ' . $remaining,
                    $values,
                );

                foreach ($rows as $row) {
                    $userIds[(int) $row->user_id] = true;
                }
            }
        }

        return array_keys($userIds);
    }

    private function assertLimit(int $limit): void
    {
        // Un plafond absent ne doit pas produire une liste illimitée.
        if ($limit < 1) {
            throw new InvalidArgumentException('La résolution d’audience exige une limite positive.');
        }
    }

    private function assertPermissionName(string $permission): void
    {
        if (preg_match('/\A[a-z][a-z0-9_]*__[A-Z][A-Z0-9_]*\z/', $permission) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Permission complète invalide pour la résolution d’audience : "%s".',
                $permission,
            ));
        }
    }
}
