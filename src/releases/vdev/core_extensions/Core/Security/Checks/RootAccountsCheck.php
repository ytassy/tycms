<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Security\Checks;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\Security\ISecurityCheck;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Security\SecurityFinding;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Roles;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;

/**
 * Comptes disposant du rôle ROOT.
 *
 * Le nombre de super administrateurs mesure la surface de compromission : trop
 * peu interdit toute reprise en main si un accès est perdu, trop élargit le
 * risque. Le contrôle lit l'attribution réelle, sans valeur supposée.
 *
 * Un compte est ROOT quand l'un de ses groupes porte le rôle système ROOT dans
 * le Space racine : c'est la même chaîne que l'ACL, lue directement pour ne pas
 * dépendre d'une liste d'identifiants fournie par l'appelant.
 */
final class RootAccountsCheck extends BaseHelper implements ISecurityCheck
{
    public function group(): string
    {
        return 'Comptes';
    }

    public function check(): array
    {
        $rows = $this->db->select(<<<SQL
            SELECT DISTINCT membership.user_id
            FROM user_groups_map membership
            INNER JOIN user_groups `group` ON `group`.id = membership.group_id
            INNER JOIN acl_map map ON map.group_id = `group`.id
            WHERE map.role_id = %
                AND map.acl_object_id IS NULL
                AND map.object_id IS NULL
                AND `group`.type = 0
            SQL, [Roles::ROLE_ROOT]);

        $count = count($rows);

        if ($count === 0) {
            return [new SecurityFinding(
                'Super administrateurs',
                SecurityFinding::CRITICAL,
                'Aucun compte ne dispose du rôle ROOT : plus personne ne peut administrer '
                . 'entièrement le site.',
                'Attribuez le rôle ROOT à au moins un compte.',
                'Administration — Utilisateurs',
            )];
        }

        if ($count === 1) {
            return [new SecurityFinding(
                'Super administrateurs',
                SecurityFinding::INFO,
                'Un seul compte dispose du rôle ROOT. La perte de son accès (mot de passe '
                . 'oublié, adresse e-mail perdue) empêcherait toute administration.',
                'Envisagez un second compte ROOT de secours, avec un accès distinct.',
                'Administration — Utilisateurs',
            )];
        }

        return [new SecurityFinding(
            'Super administrateurs',
            SecurityFinding::INFO,
            $count . ' comptes disposent du rôle ROOT. Vérifiez que chacun est nécessaire.',
            null,
            'Administration — Utilisateurs',
        )];
    }
}
