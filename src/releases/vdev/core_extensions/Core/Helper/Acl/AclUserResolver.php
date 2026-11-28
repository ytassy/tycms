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
 * droit ? ». Il ne teste jamais les comptes un par un : les rôles, les
 * affectations de groupe et la fermeture descendante des groupes sont calculés
 * en ensembles, puis les utilisateurs sont lus en une requête finale bornée.
 *
 * Le `limit` est obligatoire et la seule requête tronquée est l’énumération
 * finale des utilisateurs. Les ensembles intermédiaires (rôles, `acl_map`,
 * groupes) restent complets : les limiter oublierait des bénéficiaires. Un
 * appelant qui doit distinguer une liste complète d’une liste bornée passe
 * `limit + 1` et interprète le dépassement.
 *
 * Les ancêtres du Space sont pris en compte, comme `AclHelper` : un rôle ou un
 * droit accordé sur un Space parent vaut pour ses descendants. Les comptes
 * bloqués et de service sont écartés, et les identifiants sont dédupliqués.
 */
final class AclUserResolver extends BaseHelper
{
    /** Nombre maximal d’identifiants par requête `IN`. */
    public const MAX_IDS_PER_QUERY = 1000;

    /** Condition commune de filtrage des comptes destinataires. */
    private const ACTIVE_USER_FILTER = "u.blocked = 0 AND u.type <> 'service'";

    /** @var array<int, list<int>>|null Enfants directs par groupe, chargés une fois. */
    private ?array $groupChildren = null;

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
     * Calcule l’audience d’un ensemble de rôles pour un Space.
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

        $groupIds = $this->beneficiaryGroupIds($roleIds, $spaceIds, $aclObjectId, $objectId);
        if ($groupIds === []) {
            return [];
        }

        // Un rôle assigné à un groupe parent profite aux membres des enfants.
        return $this->usersFromGroups($this->expandGroupsWithDescendants($groupIds), $limit);
    }

    /**
     * Groupes porteurs d’une affectation utile.
     *
     * @param list<int> $roleIds
     * @param list<int> $spaceIds
     * @return list<int>
     */
    private function beneficiaryGroupIds(
        array $roleIds,
        array $spaceIds,
        ?int $aclObjectId,
        ?int $objectId,
    ): array {
        // Un droit global ne retient que les affectations globales. Un droit sur
        // objet réunit aussi celles du type et de l’instance, comme
        // `AclHelper::hasPermissionOn()`.
        $objectCondition = $aclObjectId === null
            ? 'acl_object_id IS NULL AND object_id IS NULL'
            : '(acl_object_id IS NULL AND object_id IS NULL)'
                . ' OR (acl_object_id = % AND (object_id IS NULL OR object_id = %))';

        $groups = [];

        foreach (array_chunk($roleIds, self::MAX_IDS_PER_QUERY) as $roleChunk) {
            foreach (array_chunk($spaceIds, self::MAX_IDS_PER_QUERY) as $spaceChunk) {
                $values = [FormatIn::get($roleChunk), FormatIn::get($spaceChunk)];
                if ($aclObjectId !== null) {
                    $values[] = $aclObjectId;
                    $values[] = $objectId;
                }

                $rows = $this->db->select(
                    'SELECT DISTINCT group_id FROM acl_map
                     WHERE role_id IN (%) AND space_id IN (%) AND (' . $objectCondition . ')',
                    $values,
                );

                foreach ($rows as $row) {
                    $groups[(int) $row->group_id] = true;
                }
            }
        }

        return array_keys($groups);
    }

    /**
     * Fermeture descendante des groupes bénéficiaires.
     *
     * @param list<int> $groupIds
     * @return list<int>
     */
    private function expandGroupsWithDescendants(array $groupIds): array
    {
        $children = $this->groupChildren();
        $result = [];
        $queue = $groupIds;

        while ($queue !== []) {
            $groupId = array_pop($queue);
            if (isset($result[$groupId])) {
                continue;
            }

            $result[$groupId] = true;

            foreach ($children[$groupId] ?? [] as $childId) {
                if (!isset($result[$childId])) {
                    $queue[] = $childId;
                }
            }
        }

        return array_keys($result);
    }

    /**
     * Arbre des groupes chargé une seule fois par requête.
     *
     * @return array<int, list<int>>
     */
    private function groupChildren(): array
    {
        if ($this->groupChildren !== null) {
            return $this->groupChildren;
        }

        $children = [];
        foreach ($this->db->select('SELECT id, parent_id FROM user_groups') as $row) {
            if ($row->parent_id === null) {
                continue;
            }

            $children[(int) $row->parent_id][] = (int) $row->id;
        }

        return $this->groupChildren = $children;
    }

    /**
     * Énumération finale, seule requête bornée.
     *
     * @param list<int> $groupIds
     * @return list<int>
     */
    private function usersFromGroups(array $groupIds, int $limit): array
    {
        $userIds = [];

        foreach (array_chunk($groupIds, self::MAX_IDS_PER_QUERY) as $chunk) {
            $remaining = $limit - count($userIds);
            if ($remaining <= 0) {
                break;
            }

            $rows = $this->db->select(
                'SELECT gm.user_id FROM user_groups_map gm
                 INNER JOIN users u ON u.id = gm.user_id
                 WHERE gm.group_id IN (%) AND ' . self::ACTIVE_USER_FILTER . '
                 ORDER BY gm.user_id
                 LIMIT ' . $remaining,
                [FormatIn::get($chunk)],
            );

            foreach ($rows as $row) {
                $userIds[(int) $row->user_id] = true;
                if (count($userIds) >= $limit) {
                    break 2;
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
