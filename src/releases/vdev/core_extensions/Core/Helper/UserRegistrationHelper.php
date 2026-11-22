<?php

namespace YTassy\Tycms\CoreExts\Core\Helper;

use RuntimeException;
use YTassy\Tycms\DB\FormatIn;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationPrincipalType;
use YTassy\Tycms\CoreExts\Automation\Traits\TraitAutomationHelpers;
use YTassy\Tycms\CoreExts\Core\Automation\CoreAutomationTriggers;
use YTassy\Tycms\CoreExts\Core\Models\Space;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Roles;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\UserGroups;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;

final class UserRegistrationHelper extends BaseHelper
{
    use TraitAutomationHelpers;
    use TraitCoreAdditionalHelpers;

    public function getRegistrationTargetSpace(Space $source): Space
    {
        // La cible porte seulement le compte et son groupe personnel : aucune résolution en chaîne.
        $params = is_object($source->params) ? (array) $source->params : $source->params;
        $targetId = is_array($params) ? ($params['registration_target_space_id'] ?? null) : null;
        if ($targetId === null || $targetId === '' || $targetId === 0) {
            return $source;
        }

        $target = is_int($targetId) && $targetId > 0
            ? ($this->coreSpaceHelper->getAllSpacesByIds()[$targetId] ?? null) : null;
        if ($target === null) {
            throw new RuntimeException('L’espace principal configuré pour l’inscription est indisponible.');
        }

        return $target;
    }

    public function getUserByEmailForUpdate(string $email): ?object
    {
        return $this->db->selectOneOrNull('SELECT * FROM users WHERE email = % FOR UPDATE', [$email]);
    }

    public function getUserForUpdate(int $userId): ?object
    {
        return $this->db->selectOneOrNull('SELECT * FROM users WHERE id = % FOR UPDATE', [$userId]);
    }

    public function createUser(Space $space, string $firstName, string $lastName, string $email, string $passwordHash): int
    {
        // Un seul groupe dédié porte les rôles du compte dans tous ses espaces.
        $this->db->execute(
            'INSERT INTO users (space_id, first_name, last_name, email, password, params, register_date) VALUES (%, %, %, %, %, %, current_timestamp())',
            [$space->id, $firstName, $lastName, $email, $passwordHash, '{}']
        );
        $userId = $this->db->getLastInsertId();

        $this->db->execute(
            'INSERT INTO user_groups (space_id, parent_id, `type`, `name`) VALUES (%, %, 1, %)',
            [$space->id, UserGroups::GROUP_USERS, 'user-' . $userId]
        );
        $groupId = $this->db->getLastInsertId();

        $this->db->execute('INSERT INTO user_groups_map (user_id, group_id) VALUES (%, %)', [$userId, $groupId]);

        // L'outbox est écrite dans la même transaction que le compte. Un
        // problème d'automatisation ne doit jamais empêcher une inscription.
        $this->emitUserCreated($userId, $space, $firstName, $lastName, $email);

        return $userId;
    }

    private function emitUserCreated(
        int $userId,
        Space $space,
        string $firstName,
        string $lastName,
        string $email,
    ): void {
        $principalId = $this->session->getUserId();

        try {
            $this->automationEventBuffer->emit(
                (int) $space->id,
                CoreAutomationTriggers::USER_CREATED,
                [
                    'user' => [
                        'id' => $userId,
                        'spaceId' => (int) $space->id,
                        'firstName' => $firstName,
                        'lastName' => $lastName,
                        'email' => $email,
                    ],
                ],
                $principalId === null ? AutomationPrincipalType::ANONYMOUS : AutomationPrincipalType::USER,
                $principalId,
            );
        } catch (\Throwable $exception) {
            $this->logError(
                'L’événement de création de compte n’a pas pu être préparé.',
                ['space_id' => (int) $space->id],
                'AUTOMATION_EVENT_EMIT_FAILED',
                'automation',
            );
            $this->logException($exception);
        }
    }

    public function enroll(int $userId, Space $space): void
    {
        $group = $this->db->selectOneOrNull(
            'SELECT ug.id FROM user_groups ug INNER JOIN user_groups_map gm ON gm.group_id = ug.id WHERE gm.user_id = % AND ug.type = 1 AND ug.parent_id = % FOR UPDATE',
            [$userId, UserGroups::GROUP_USERS]
        );
        if ($group === null) {
            throw new RuntimeException('Configuration du compte invalide.');
        }

        $params = is_object($space->params) ? (array) $space->params : $space->params;
        $params = is_array($params) ? $params : [];
        $autoMember = $params['registration_auto_member_role'];
        if (!is_bool($autoMember)) {
            throw new RuntimeException('Configuration de l’attribution automatique du rôle Membre invalide.');
        }

        // Les rôles et groupes configurés restent appliqués lorsque l’attribution
        // implicite du rôle Membre est désactivée.
        $assignments = [];
        if ($autoMember) {
            $assignments[$space->id . ':' . Roles::ROLE_MEMBER] = [(int) $space->id, Roles::ROLE_MEMBER];
        }

        foreach (['registration_roles', 'user_roles'] as $setting) {
            $rolesBySpace = $params[$setting] ?? [];
            $rolesBySpace = is_object($rolesBySpace) ? (array) $rolesBySpace : $rolesBySpace;
            if (!is_array($rolesBySpace)) {
                throw new RuntimeException('Configuration des rôles d’inscription invalide.');
            }

            foreach ($rolesBySpace as $target => $roleIds) {
                $targetId = (string) $target === 'this' ? (int) $space->id : filter_var($target, FILTER_VALIDATE_INT);
                if ($targetId === false || $targetId < 1 || !is_array($roleIds)) {
                    throw new RuntimeException('Configuration des rôles d’inscription invalide.');
                }

                foreach ($roleIds as $roleId) {
                    if (!is_int($roleId) || $roleId < 1 || Roles::isImplicit($roleId)) {
                        throw new RuntimeException('Identifiant de rôle d’inscription invalide.');
                    }
                    $assignments[$targetId . ':' . $roleId] = [$targetId, $roleId];
                }
            }
        }

        // Vérifier une seule fois le contrat du rôle système s’il figure parmi les
        // attributions implicites ou configurées, même sur plusieurs espaces.
        if (array_any($assignments, static fn (array $assignment): bool => $assignment[1] === Roles::ROLE_MEMBER)) {
            $memberRole = $this->db->selectOneOrNull(<<<SQL
                SELECT role.id FROM acl_roles role
                INNER JOIN acl_permissions permission ON permission.role_id = role.id
                WHERE role.id = % AND role.space_id = 1 AND role.type IN (0, 2)
                    AND permission.extension = 'plg_core' AND permission.name = 'MEMBER'
                LIMIT 1
                SQL, [Roles::ROLE_MEMBER]);
            if ($memberRole === null) {
                throw new RuntimeException('Le rôle Membre doit conserver la permission MEMBER.');
            }
        }

        foreach ($assignments as [$targetId, $roleId]) {
            // L'inscription ne transmet jamais ROOT, même lorsqu'elle est déclenchée par un ROOT.
            $this->coreRoleAdminHelper->assertRootAssignmentAllowed($roleId, $targetId, (int) $group->id, automatic: true);

            // Conserver le rôle système sur le Space rejoint, y compris sans parent.
            // Les rôles supplémentaires doivent appartenir à leur cible ou à un ancêtre.
            if ($targetId !== (int) $space->id || $roleId !== Roles::ROLE_MEMBER) {
                $ancestors = $this->coreSpaceHelper->getSpaceIdsWithParents($targetId);
                if ($ancestors === []) {
                    throw new RuntimeException('Espace cible des rôles d’inscription introuvable.');
                }
                $valid = $this->db->selectOneOrNull(
                    'SELECT id FROM acl_roles WHERE id = % AND type IN (0, 2) AND space_id IN (%)',
                    [$roleId, FormatIn::get($ancestors)]
                );
                if ($valid === null) {
                    throw new RuntimeException('Rôle d’inscription indisponible pour l’espace cible.');
                }
            }

            $exists = $this->db->selectOneOrNull(
                'SELECT id FROM acl_map WHERE group_id = % AND space_id = % AND role_id = % AND acl_object_id IS NULL LIMIT 1',
                [(int) $group->id, $targetId, $roleId]
            );
            if ($exists === null) {
                $this->db->execute(
                    'INSERT INTO acl_map (role_id, group_id, space_id) VALUES (%, %, %)',
                    [$roleId, (int) $group->id, $targetId]
                );
            }
        }

        // Les deux options de groupes se cumulent pour une première inscription
        // comme pour une adhésion ultérieure, sans doublonner la relation.
        $groupIds = [];
        foreach (['registration_groups', 'user_groups'] as $setting) {
            $configured = $params[$setting] ?? [];
            if (!is_array($configured)) {
                throw new RuntimeException('Configuration des groupes d’inscription invalide.');
            }

            foreach ($configured as $groupId) {
                if (!is_int($groupId) || $groupId <= 3) {
                    throw new RuntimeException('Identifiant de groupe d’inscription invalide.');
                }
                $groupIds[$groupId] = true;
            }
        }

        foreach (array_keys($groupIds) as $groupId) {
            $group = $this->db->selectOneOrNull(
                'SELECT id FROM user_groups WHERE id = % AND space_id = % AND type = 0 FOR UPDATE',
                [$groupId, $space->id]
            );
            if ($group === null) {
                throw new RuntimeException('Groupe d’inscription indisponible pour cet espace.');
            }

            $this->coreUserGroupAdminHelper->assertRootGroupAssignmentAllowed($groupId, automatic: true);

            $exists = $this->db->selectOneOrNull(
                'SELECT id FROM user_groups_map WHERE user_id = % AND group_id = % LIMIT 1',
                [$userId, $groupId]
            );
            if ($exists === null) {
                $this->db->execute(
                    'INSERT INTO user_groups_map (user_id, group_id) VALUES (%, %)',
                    [$userId, $groupId]
                );
            }
        }
    }

}
