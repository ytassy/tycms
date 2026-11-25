<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Security\Checks;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Security\ISecurityCheck;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Security\SecurityFinding;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;

/**
 * Cloisonnement du Space racine.
 *
 * Le Space racine porte l'administration : un compte ROOT y est rattaché, et la
 * segmentation par Space sert de seconde barrière pour les accès d'administration.
 * Cette barrière perd sa valeur si le Space racine héberge aussi des comptes
 * ordinaires, ou si un compte ROOT est rattaché à un sous-Space : un compte
 * d'administration exposé dans un Space de contenu rapproche la compromission du
 * contenu de la compromission de l'administration.
 *
 * Le contrôle interroge directement `users`, `spaces`, `user_groups_map`,
 * `user_groups` et `acl_map`, sans passer par les helpers d'ACL : une analyse de
 * sécurité doit rester fiable même si un helper porte un défaut.
 */
final class RootSpaceSegregationCheck extends BaseHelper implements ISecurityCheck
{
    public function group(): string
    {
        return 'Cloisonnement';
    }

    public function check(): array
    {
        $rootUserIds = $this->rootUserIds();

        return array_merge(
            $this->nonRootUsersInRootSpace($rootUserIds),
            $this->rootUsersInChildSpaces($rootUserIds),
        );
    }

    /**
     * Identifiants des comptes détenant la permission ROOT.
     *
     * La définition retenue est la permission `plg_core__ROOT`, pas le rôle
     * système qui la porte : un rôle personnalisé qui recevrait cette
     * permission rendrait son titulaire ROOT au sens de l'ACL.
     *
     * La chaîne suit celle de l'ACL : groupes du compte, groupes parents
     * compris, attributions globales, puis permissions du rôle.
     * `acl_object_id` et `object_id` restent nuls : une attribution sur un
     * objet ne donne pas ROOT.
     *
     * @return list<int>
     */
    private function rootUserIds(): array
    {
        $rows = $this->db->select(<<<SQL
            WITH RECURSIVE groups_for_users AS (
                SELECT membership.user_id, `group`.id, `group`.parent_id
                FROM user_groups_map membership
                INNER JOIN user_groups `group` ON `group`.id = membership.group_id
                UNION DISTINCT
                SELECT child.user_id, parent.id, parent.parent_id
                FROM user_groups parent
                INNER JOIN groups_for_users child ON child.parent_id = parent.id
            )
            SELECT DISTINCT groups_for_users.user_id
            FROM groups_for_users
            INNER JOIN acl_map map ON map.group_id = groups_for_users.id
            INNER JOIN acl_permissions permission ON permission.role_id = map.role_id
            WHERE permission.extension = %
                AND permission.name = %
                AND map.acl_object_id IS NULL
                AND map.object_id IS NULL
            SQL, explode('__', Permissions::ROOT, 2));

        return array_map(static fn (object $row): int => (int) $row->user_id, $rows);
    }

    /**
     * Comptes ordinaires rattachés au Space racine.
     *
     * @param list<int> $rootUserIds
     * @return list<SecurityFinding>
     */
    private function nonRootUsersInRootSpace(array $rootUserIds): array
    {
        $rootSpaceId = $this->rootSpaceId();

        $sql = 'SELECT id, first_name, last_name, email FROM users WHERE space_id = %';
        $params = [$rootSpaceId];

        // Les comptes ROOT sont légitimes dans le Space racine.
        if ($rootUserIds !== []) {
            $placeholders = implode(', ', array_fill(0, count($rootUserIds), '%'));
            $sql .= ' AND id NOT IN (' . $placeholders . ')';
            $params = array_merge($params, $rootUserIds);
        }

        $users = $this->db->select($sql, $params);

        if ($users === []) {
            return [new SecurityFinding(
                'Space racine réservé à l’administration',
                SecurityFinding::OK,
                'Le Space racine ne contient que des comptes disposant du rôle ROOT.',
            )];
        }

        return [new SecurityFinding(
            'Comptes ordinaires dans le Space racine',
            SecurityFinding::DANGER,
            count($users) . ' compte(s) sans rôle ROOT sont rattachés au Space racine'
            . ($this->describe($users)) . '. La segmentation par Space sert de seconde barrière '
            . 'pour les accès d’administration : elle perd sa valeur si des comptes ordinaires '
            . 'partagent le Space de l’administration.',
            'Rattachez ces comptes à un sous-Space de contenu.',
            'Administration — Utilisateurs',
        )];
    }

    /**
     * Comptes ROOT rattachés à un sous-Space.
     *
     * @param list<int> $rootUserIds
     * @return list<SecurityFinding>
     */
    private function rootUsersInChildSpaces(array $rootUserIds): array
    {
        if ($rootUserIds === []) {
            // Sans compte ROOT, le constat de comptes est déjà critique ; on
            // n'ajoute pas un second message sur un cas qui n'existe pas.
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($rootUserIds), '%'));

        $users = $this->db->select(
            'SELECT u.id, u.first_name, u.last_name, u.email, s.title AS space_title '
            . 'FROM users u INNER JOIN spaces s ON s.id = u.space_id '
            . 'WHERE u.id IN (' . $placeholders . ') AND s.parent_id IS NOT NULL',
            $rootUserIds,
        );

        if ($users === []) {
            return [new SecurityFinding(
                'Rattachement des comptes ROOT',
                SecurityFinding::OK,
                'Les comptes ROOT sont rattachés au Space racine, pas à un Space de contenu.',
            )];
        }

        return [new SecurityFinding(
            'Comptes ROOT dans un sous-Space',
            SecurityFinding::DANGER,
            count($users) . ' compte(s) ROOT sont rattachés à un Space de contenu'
            . ($this->describe($users, true)) . '. Un compte d’administration exposé dans un '
            . 'Space de contenu rapproche la compromission du contenu de celle de l’administration.',
            'Rattachez les comptes ROOT au Space racine.',
            'Administration — Utilisateurs',
        )];
    }

    /** Space racine : celui qui n'a pas de parent. */
    private function rootSpaceId(): int
    {
        $row = $this->db->selectOneOrNull('SELECT id FROM spaces WHERE parent_id IS NULL ORDER BY id ASC LIMIT 1');

        return (int) ($row->id ?? 1);
    }

    /**
     * Résumé lisible des comptes concernés, sans exposer de secret.
     *
     * @param list<object> $users
     */
    private function describe(array $users, bool $withSpace = false): string
    {
        $labels = [];

        foreach (array_slice($users, 0, 5) as $user) {
            $name = trim((string) $user->first_name . ' ' . (string) $user->last_name);
            $label = $name !== '' ? $name : (string) $user->email;

            if ($withSpace) {
                $label .= ' (' . (string) ($user->space_title ?? '') . ')';
            }

            $labels[] = $label;
        }

        $suffix = count($users) > 5 ? ' et ' . (count($users) - 5) . ' autre(s)' : '';

        return ' : ' . implode(', ', $labels) . $suffix;
    }
}
