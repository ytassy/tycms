<?php

namespace YTassy\Tycms\CoreExts\Core\WF;

use YTassy\Tycms\CoreExts\Core\Helper\TraitAllHelpers;
use YTassy\Tycms\CoreExts\Core\Security\LocalPasswordPolicy;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\UserGroups;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Roles;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\WF\BaseWF;

class WFAdminUser extends BaseWF
{
    use TraitAllHelpers;
    use TraitUserEnrollment;

    /** @return array<string, mixed> */
    public function getDirectory(): array
    {
        $spaces = [];
        foreach ($this->coreSpaceHelper->getAllSpacesByIds() as $space) {
            $spaces[] = [
                'id' => $space->id,
                'title' => $space->title,
                'parent_id' => $space->parent_id,
                'can_read_users' => $this->canReadUser($space->id),
                'can_create' => $this->can(Permissions::USERS_CRUD, $space->id),
                'can_assign' => $this->can(Permissions::USER_ROLES_CRUD, $space->id)
                    && $this->can(Permissions::USER_ROLES_READ, $space->id),
                'can_assign_groups' => $this->can(Permissions::USER_GROUPS_ASSIGN, $space->id)
                    && $this->can(Permissions::USER_GROUPS_READ, $space->id),
            ];
        }

        if (!array_filter($spaces, static fn (array $space): bool => $space['can_read_users'])) {
            ExceptionResponse::throwDanger('Vous ne pouvez pas consulter les utilisateurs.');
        }

        // Les catalogues restent limités aux groupes ordinaires et aux rôles globaux.
        $catalog = $this->coreUserAdminHelper->getCatalog();
        $roles = [];
        foreach ($catalog['roles'] as $role) {
            if (!$this->can(Permissions::USER_ROLES_CRUD, (int) $role->space_id)
                || !$this->can(Permissions::USER_ROLES_READ, (int) $role->space_id)) continue;
            $roles[] = ['id' => (int) $role->id, 'name' => $role->name,
                'space_id' => (int) $role->space_id, 'type' => (int) $role->type];
        }
        $groups = [];
        foreach ($catalog['groups'] as $group) {
            if (!$this->can(Permissions::USER_GROUPS_ASSIGN, (int) $group->space_id)
                || !$this->can(Permissions::USER_GROUPS_READ, (int) $group->space_id)) continue;
            $groups[] = ['id' => (int) $group->id, 'name' => $group->name,
                'space_id' => (int) $group->space_id];
        }

        // Ne proposer que les attributions que l'appelant peut réellement déléguer.
        return ['spaces' => $spaces, 'roles' => $this->filterDelegableRoles($roles),
            'groups' => $this->filterDelegableGroups($groups)];
    }

    /** @return array<string, mixed> */
    public function listUsers(int $page, int $perPage, string $search, ?string $type = 'user'): array
    {
        $this->validateUserPagination($page, $perPage, $search);

        // « null » affiche tous les types ; sinon seul le type demandé est listé.
        if ($type !== null && !in_array($type, ['user', 'service'], true)) {
            ExceptionResponse::throwWarning('Type de compte invalide.');
        }

        $spaceIds = [];
        foreach ($this->coreSpaceHelper->getAllSpacesByIds() as $space) {
            if ($this->canReadUser($space->id)) $spaceIds[] = $space->id;
        }
        if ($spaceIds === []) {
            ExceptionResponse::throwDanger('Vous ne pouvez pas consulter les utilisateurs.');
        }

        $result = $this->coreUserAdminHelper->listUsers($spaceIds, $page, $perPage, trim($search), null, $type);
        $ids = array_map(static fn (object $row): int => (int) $row->id, $result['rows']);
        $relations = $this->coreUserAdminHelper->getRelations($ids);
        $protected = $this->protectedRootUsers($ids);

        return [
            'users' => array_map(fn (object $row): array => $this->presentUser(
                $row, $relations[(int) $row->id] ?? ['spaces' => [], 'inherited_spaces' => [], 'groups' => [], 'roles' => []],
                isset($protected[(int) $row->id])
            ), $result['rows']),
            'total' => $result['total'], 'page' => $page, 'per_page' => $perPage,
            'type' => $type,
        ];
    }

    /** @return array{spaces:list<array>} */
    public function getAssignmentsDirectory(): array
    {
        // Filtrer avec les permissions de l'administrateur, jamais celles des comptes affichés.
        $readable = $this->coreAclHelper->getSpacesHavingGlobalPermission(Permissions::USERS_READ);
        if ($readable === []) {
            ExceptionResponse::throwDanger('Vous ne pouvez pas consulter les utilisateurs.');
        }

        $spaces = $this->coreSpaceHelper->getAllSpacesByIds();
        return ['spaces' => array_map(function (int $id) use ($spaces): array {
            return ['id' => $id, 'title' => $spaces[$id]->title,
                'can_assign_roles' => $this->canViewAssignmentColumns($id, 'role'),
                'can_assign_groups' => $this->canViewAssignmentColumns($id, 'group')];
        }, $readable)];
    }

    /** @return array<string, mixed> */
    public function listAssignments(int $spaceId, int $page, int $perPage, string $search): array
    {
        $this->validateUserPagination($page, $perPage, $search);
        $this->assertAssignmentSpace($spaceId);
        $readable = $this->coreAclHelper->getSpacesHavingGlobalPermission(Permissions::USERS_READ);
        $withRoles = $this->canViewAssignmentColumns($spaceId, 'role');
        $withGroups = $this->canViewAssignmentColumns($spaceId, 'group');

        // Le Space sélectionné filtre les colonnes, pas les comptes selon leur appartenance.
        $result = $this->coreUserAdminHelper->listUsers($readable, $page, $perPage, trim($search), null);
        $ids = array_map(static fn (object $row): int => (int) $row->id, $result['rows']);
        $assignments = $this->coreUserAdminHelper->getDirectAssignments($ids, $spaceId, $withRoles, $withGroups);
        $catalog = $this->coreUserAdminHelper->getAssignmentCatalog($spaceId, $withRoles, $withGroups);

        // Le tableau ne propose que les attributions délégables avec les droits actuels.
        $catalog['roles'] = $this->filterDelegableRoles($catalog['roles']);
        $catalog['groups'] = $this->filterDelegableGroups($catalog['groups']);
        $protected = $this->protectedRootUsers($ids);

        $users = [];
        foreach ($result['rows'] as $row) {
            $id = (int) $row->id;
            $originSpaceId = (int) $row->space_id;
            $users[] = [
                'id' => $id, 'space_id' => $originSpaceId,
                'first_name' => $row->first_name, 'last_name' => $row->last_name, 'email' => $row->email,
                'can_assign_roles' => !isset($protected[$id]) && $withRoles && $this->can(Permissions::USER_ROLES_CRUD, $originSpaceId),
                'can_assign_groups' => !isset($protected[$id]) && $withGroups && $this->can(Permissions::USER_GROUPS_ASSIGN, $originSpaceId),
                // L'auto-retrait du groupe Super Admin reste interdit, même via cette API.
                'protected_group_ids' => $id === $this->session->getUserId() ? [UserGroups::GROUP_ROOT] : [],
                ...$assignments[$id],
            ];
        }

        return [...$catalog, 'users' => $users, 'total' => $result['total'],
            'page' => $page, 'per_page' => $perPage, 'space_id' => $spaceId];
    }

    /** @return array<string, mixed> */
    public function saveAssignmentCell(object $data): array
    {
        // Garder ces validations au WF pour les appels qui ne passent pas par le contrôleur HTTP.
        foreach (['user_id', 'space_id', 'item_id'] as $key) {
            if (!isset($data->$key) || !is_int($data->$key) || $data->$key < 1) {
                ExceptionResponse::throwWarning('Attribution invalide.');
            }
        }
        if (!in_array($data->kind ?? null, ['role', 'group'], true)
            || !is_bool($data->before ?? null) || !is_bool($data->assigned ?? null)
            || $data->before === $data->assigned) {
            ExceptionResponse::throwWarning('Modification d’attribution invalide.');
        }

        $this->assertAssignmentSpace($data->space_id);
        $user = $this->getUserRecord($data->user_id, true);
        $this->assertCan(Permissions::USERS_READ, (int) $user->space_id);
        if (!$this->canViewAssignmentColumns($data->space_id, $data->kind)) {
            ExceptionResponse::throwDanger('Vous ne pouvez pas gérer ces attributions sur cet espace.');
        }

        // Relire le propriétaire réel : aucun rôle d'un ancêtre ou groupe d'un autre Space.
        $item = $data->kind === 'role' ? $this->coreUserAdminHelper->getRole($data->item_id)
            : $this->coreUserAdminHelper->getGroup($data->item_id);
        if ($item === null || (int) $item->space_id !== $data->space_id
            || ($data->kind === 'role' ? !in_array((int) $item->type, [0, 2], true) : (int) $item->type !== 0)) {
            ExceptionResponse::throwWarning('Rôle ou groupe invalide pour cet espace.');
        }

        $permission = $data->kind === 'role' ? Permissions::USER_ROLES_CRUD : Permissions::USER_GROUPS_ASSIGN;
        $this->assertCanManageAssignment((int) $user->space_id, $data->space_id, (int) $item->space_id, $permission);

        // Le verrou du compte sérialise aussi les écritures des anciennes modales.
        // Une lecture verrouillée voit l’état courant même sous REPEATABLE READ.
        $state = $this->coreUserAdminHelper->getDirectAssignments(
            [$data->user_id], $data->space_id, $data->kind === 'role', $data->kind === 'group', true
        )[$data->user_id];
        $ids = $state[$data->kind === 'role' ? 'role_ids' : 'group_ids'];
        if (in_array($data->item_id, $ids, true) !== $data->before) {
            ExceptionResponse::throwWarning('Cette attribution a changé. Actualisez le tableau avant de réessayer.');
        }

        if ($data->kind === 'role') {
            $this->applyRole($data->user_id, $data->item_id, $data->space_id, $data->assigned);
        } else {
            $this->applyGroup($data->user_id, $data->item_id, $data->assigned);
        }

        // Ne pas reconstruire de fiche ni de permissions effectives après un clic.
        return ['user_id' => $data->user_id, 'space_id' => $data->space_id,
            'kind' => $data->kind, 'item_id' => $data->item_id, 'assigned' => $data->assigned];
    }

    private function assertAssignmentSpace(int $spaceId): void
    {
        if (!isset($this->coreSpaceHelper->getAllSpacesByIds()[$spaceId])) {
            ExceptionResponse::throwWarning('Espace invalide.');
        }
        $this->assertCan(Permissions::USERS_READ, $spaceId);
    }

    private function canViewAssignmentColumns(int $spaceId, string $kind): bool
    {
        return $kind === 'role'
            ? $this->can(Permissions::ROLE_READ, $spaceId) && $this->can(Permissions::USER_ROLES_CRUD, $spaceId)
            : $this->can(Permissions::USER_GROUPS_READ, $spaceId) && $this->can(Permissions::USER_GROUPS_ASSIGN, $spaceId);
    }

    private function validateUserPagination(int $page, int $perPage, string $search): void
    {
        if ($page < 1 || $page > 1000000 || !in_array($perPage, [10, 20, 50, 100, 500], true)
            || mb_strlen($search) > 100) {
            ExceptionResponse::throwWarning('Paramètres de pagination invalides.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getUser(int $userId): array
    {
        $user = $this->getUserRecord($userId, false);
        $relations = $this->coreUserAdminHelper->getRelations([$userId]);
        $presented = $this->presentUser($user, $relations[$userId], isset($this->protectedRootUsers([$userId])[$userId]));

        if ($presented['can_admin']) {
            $presented['sso_links'] = array_map(static fn (object $link): array => [
                'id' => (int) $link->id, 'name' => $link->name,
                'space_id' => (int) $link->space_id,
            ], $this->coreUserAdminHelper->getSsoLinks($userId));
        }

        return $presented;
    }

    /** @return array<string, mixed> */
    public function createUser(object $data): array
    {
        $spaceId = $data->space_id ?? null;
        $space = is_int($spaceId) ? ($this->coreSpaceHelper->getAllSpacesByIds()[$spaceId] ?? null) : null;
        if ($space === null) ExceptionResponse::throwWarning('Espace invalide.');
        $this->assertCan(Permissions::USERS_CRUD, $spaceId);

        [$firstName, $lastName, $email, $timezone, $blocked, $sendEmail] = $this->validateData($data);
        $this->validatePassword($data->password ?? null);
        if ($this->coreUserAdminHelper->emailExists($email)) {
            ExceptionResponse::throwWarning('Cette adresse e-mail est déjà utilisée.');
        }

        $userId = $this->coreUserRegistrationHelper->createUser(
            $space, $firstName, $lastName, $email, password_hash($data->password, PASSWORD_DEFAULT)
        );
        $this->enrollUserInSpace($userId, $space);
        $this->coreUserAdminHelper->updateUser(
            $userId, $firstName, $lastName, $email, $timezone, $blocked, $sendEmail
        );

        $result = $this->getUser($userId);
        $sendWelcome = $data->send_welcome_email ?? false;
        $result['welcome_email_sent'] = null;

        // La case de la modale est indépendante du réglage général send_email du compte.
        if ($sendWelcome) {
            // Volontairement synchrone : ce message porte le mot de passe
            // initial, qui n'a rien à faire dans une ligne de file lisible en
            // base. Voir le contrat des handlers de la file.
            $result['welcome_email_sent'] = $this->coreUserWelcomeMailHelper->sendAdminCreatedAccount(
                $space, $email, $firstName, $data->password,
            );
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function updateUser(int $userId, object $data): array
    {
        $user = $this->getUserRecord($userId, true);

        // Un compte de service n'a pas de profil interactif : seuls ses groupes
        // et ses rôles se modifient depuis la liste des utilisateurs.
        if (($user->type ?? 'user') === 'service') {
            ExceptionResponse::throwWarning('Un compte de service n’a pas de profil modifiable.');
        }

        $this->assertCan(Permissions::USERS_CRUD, (int) $user->space_id);
        [$firstName, $lastName, $email, $timezone, $blocked, $sendEmail] = $this->validateData($data);

        if ($blocked && $userId === $this->session->getUserId()) {
            ExceptionResponse::throwWarning('Vous ne pouvez pas bloquer votre propre compte.');
        }

        if ($this->coreUserAdminHelper->emailExists($email, $userId)) {
            ExceptionResponse::throwWarning('Cette adresse e-mail est déjà utilisée.');
        }

        $this->coreUserAdminHelper->updateUser(
            $userId, $firstName, $lastName, $email, $timezone, $blocked, $sendEmail
        );

        return $this->getUser($userId);
    }

    public function updatePassword(int $userId, string $password): bool
    {
        $user = $this->getUserRecord($userId, true);

        // Un compte de service ne doit jamais recevoir de mot de passe : sa
        // seule authentification possible est le jeton d'API.
        if (($user->type ?? 'user') === 'service') {
            ExceptionResponse::throwWarning('Un compte de service ne possède pas de mot de passe.');
        }

        $this->assertCan(Permissions::USERS_ADMIN, (int) $user->space_id);
        $this->validatePassword($password);

        $this->coreUserHelper->updatePassword($userId, $password);

        return true;
    }

    /** @return array<string, mixed> */
    public function setRole(int $userId, int $roleId, int $spaceId, bool $assigned): array
    {
        $this->applyRole($userId, $roleId, $spaceId, $assigned);

        return $this->getUser($userId);
    }

    private function applyRole(int $userId, int $roleId, int $spaceId, bool $assigned): void
    {
        // Les rôles implicites ne sont jamais attribués manuellement, même par ROOT.
        if ($assigned && Roles::isImplicit($roleId)) {
            ExceptionResponse::throwWarning('Les rôles Invité et Utilisateur sont réservés aux attributions système.');
        }

        $user = $this->getUserRecord($userId, true);
        $role = $this->coreRoleAdminHelper->getRole($roleId, true);
        if ($role === null || !in_array((int) $role->type, [0, 2], true)
            || !in_array((int) $role->space_id, $this->coreSpaceHelper->getSpaceIdsWithParents($spaceId), true)) {
            ExceptionResponse::throwWarning('Rôle ou espace invalide.');
        }

        // Relire les permissions du rôle verrouillé : le navigateur n'en fournit aucune.
        $permissions = $this->coreRoleAdminHelper->getPermissions($roleId, true);
        if (!$this->coreAclHelper->canDelegateGlobalPermissions(
            Permissions::USER_ROLES_CRUD, $permissions, $spaceId
        )) {
            ExceptionResponse::throwDanger('Vous ne pouvez pas attribuer ce rôle avec vos droits actuels.');
        }

        $this->assertCanManageAssignment((int) $user->space_id, $spaceId, (int) $role->space_id);

        // Les droits d'attribution portent sur les espaces concernés, sans exiger MEMBER.
        $groupId = $this->getPersonalGroup($userId);
        if ($assigned) $this->coreUserAdminHelper->assignRole($groupId, $roleId, $spaceId);
        else $this->coreUserAdminHelper->removeRole($groupId, $roleId, $spaceId);

        $this->coreAclHelper->invalidateUser($userId);
    }

    /** @return array<string, mixed> */
    public function setGroup(int $userId, int $groupId, bool $assigned): array
    {
        $this->applyGroup($userId, $groupId, $assigned);

        return $this->getUser($userId);
    }

    private function applyGroup(int $userId, int $groupId, bool $assigned): void
    {
        if ($assigned && UserGroups::isImplicit($groupId)) {
            ExceptionResponse::throwWarning('Les groupes Invité et Utilisateurs sont gérés automatiquement par le système.');
        }

        $user = $this->getUserRecord($userId, true);
        $group = $this->coreUserGroupAdminHelper->getGroup($groupId, true);
        if ($group === null || (int) $group->type !== 0) {
            ExceptionResponse::throwWarning('Groupe invalide.');
        }

        if (!$assigned && $userId === $this->session->getUserId()
            && $groupId === UserGroups::GROUP_ROOT) {
            ExceptionResponse::throwWarning('Impossible de vous retirer du groupe Super Admin.');
        }

        // Vérifier les permissions réelles du groupe et de ses parents, par Space.
        $permissions = $this->coreUserGroupAdminHelper->getGroupPermissionsBySpace([$groupId], true)[$groupId] ?? [];
        foreach ($permissions as $permissionSpaceId => $spacePermissions) {
            if (!$this->coreAclHelper->canDelegateGlobalPermissions(
                Permissions::USER_GROUPS_ASSIGN, $spacePermissions, $permissionSpaceId
            )) {
                ExceptionResponse::throwDanger('Vous ne pouvez pas attribuer ce groupe avec vos droits actuels.');
            }
        }

        $this->assertCanManageAssignment((int) $user->space_id, (int) $group->space_id,
            (int) $group->space_id, Permissions::USER_GROUPS_ASSIGN);

        // L'appartenance au groupe peut elle-même fournir les accès à cet espace.
        if ($assigned) $this->coreUserAdminHelper->assignGroup($userId, $groupId);
        else $this->coreUserAdminHelper->removeGroup($userId, $groupId);

        $this->coreAclHelper->invalidateUser($userId);
    }

    /** @param list<int> $before @param list<int> $after */
    public function replaceGroups(int $userId, array $before, array $after): array
    {
        $this->getUserRecord($userId, true);
        $before = $this->normalizeIds($before);
        $after = $this->normalizeIds($after);
        $currentRelations = $this->coreUserAdminHelper->getRelations([$userId])[$userId];
        $current = $this->normalizeIds(array_column($currentRelations['groups'], 'id'));
        $this->assertSnapshot($before, $current);

        // Ajouter avant de retirer permet de remplacer un groupe porteur de MEMBER.
        foreach (array_diff($after, $current) as $groupId) {
            $this->setGroup($userId, $groupId, true);
        }
        foreach (array_diff($current, $after) as $groupId) {
            $this->setGroup($userId, $groupId, false);
        }

        $result = $this->getUser($userId);
        if ($this->normalizeIds(array_column($result['groups'], 'id')) !== $after) {
            ExceptionResponse::throwWarning('Les groupes demandés ne peuvent pas être appliqués.');
        }

        return $result;
    }

    /** @param list<object> $before @param list<object> $after */
    public function replaceRoles(int $userId, array $before, array $after): array
    {
        $this->getUserRecord($userId, true);
        $before = $this->normalizeRoleAssignments($before);
        $after = $this->normalizeRoleAssignments($after);
        $relations = $this->coreUserAdminHelper->getRelations([$userId])[$userId];
        $currentRows = [];
        foreach ($relations['roles'] as $role) {
            $currentRows[] = (object) [
                'role_id' => $role['id'], 'space_id' => $role['target_space_id'],
            ];
        }
        $current = $this->normalizeRoleAssignments($currentRows);
        $this->assertSnapshot(array_keys($before), array_keys($current));

        foreach (array_diff_key($after, $current) as $assignment) {
            $this->setRole($userId, $assignment['role_id'], $assignment['space_id'], true);
        }
        foreach (array_diff_key($current, $after) as $assignment) {
            $this->setRole($userId, $assignment['role_id'], $assignment['space_id'], false);
        }

        $result = $this->getUser($userId);
        $saved = [];
        foreach ($result['roles'] as $role) {
            $saved[] = (object) [
                'role_id' => $role['id'], 'space_id' => $role['target_space_id'],
            ];
        }
        if (array_keys($this->normalizeRoleAssignments($saved)) !== array_keys($after)) {
            ExceptionResponse::throwWarning('Les rôles demandés ne peuvent pas être appliqués.');
        }

        return $result;
    }

    /** @return list<int> */
    private function normalizeIds(array $ids): array
    {
        if (!array_is_list($ids) || count($ids) > 1000) {
            ExceptionResponse::throwWarning('Liste d’attributions invalide.');
        }

        $unique = [];
        foreach ($ids as $id) {
            if (!is_int($id) || $id < 1 || isset($unique[$id])) {
                ExceptionResponse::throwWarning('Liste d’attributions invalide.');
            }
            $unique[$id] = true;
        }

        $ids = array_keys($unique);
        sort($ids);

        return $ids;
    }

    /** @return array<string, array{role_id:int, space_id:int}> */
    private function normalizeRoleAssignments(array $assignments): array
    {
        if (!array_is_list($assignments) || count($assignments) > 1000) {
            ExceptionResponse::throwWarning('Liste de rôles invalide.');
        }

        $normalized = [];
        foreach ($assignments as $assignment) {
            $roleId = $assignment->role_id ?? null;
            $spaceId = $assignment->space_id ?? null;
            if (!is_int($roleId) || $roleId < 1
                || !is_int($spaceId) || $spaceId < 1) {
                ExceptionResponse::throwWarning('Liste de rôles invalide.');
            }

            $key = $spaceId . ':' . $roleId;
            if (isset($normalized[$key])) ExceptionResponse::throwWarning('Rôle attribué plusieurs fois.');
            $normalized[$key] = ['role_id' => $roleId, 'space_id' => $spaceId];
        }

        ksort($normalized);

        return $normalized;
    }

    private function assertSnapshot(array $before, array $current): void
    {
        if ($before !== $current) {
            ExceptionResponse::throwWarning('Les attributions ont changé. Fermez puis rouvrez cette édition.');
        }
    }

    /** @return array<string, mixed> */
    public function unlinkSso(int $userId, int $linkId): array
    {
        $user = $this->getUserRecord($userId, true);
        $this->assertCan(Permissions::USERS_ADMIN, (int) $user->space_id);
        if (!$this->coreUserAdminHelper->removeSsoLink($userId, $linkId)) {
            ExceptionResponse::throwWarning('Connexion SSO introuvable.');
        }

        return $this->getUser($userId);
    }

    public function deleteUser(int $userId): bool
    {
        $user = $this->getUserRecord($userId, true);
        $originSpaceId = (int) $user->space_id;
        $this->assertCan(Permissions::USERS_CRUD, $originSpaceId);
        $this->assertCan(Permissions::USER_ROLES_CRUD, $originSpaceId);
        $this->assertCan(Permissions::USER_GROUPS_ASSIGN, $originSpaceId);

        // La suppression touche tous les espaces où MEMBER est effectif.
        $relations = $this->coreUserAdminHelper->getRelations([$userId])[$userId];
        foreach ($relations['groups'] as $group) {
            $this->assertCan(Permissions::USER_GROUPS_ASSIGN, $group['space_id']);
        }
        foreach ($relations['spaces'] as $spaceId) {
            $this->assertCan(Permissions::USER_ROLES_CRUD, $spaceId);
        }

        if ($userId === $this->session->getUserId()) {
            ExceptionResponse::throwWarning('Vous ne pouvez pas supprimer votre propre compte.');
        }

        // Les identifiants de la plage réservée portent des comptes système :
        // leur suppression n'est jamais permise, même à ROOT. Leurs droits
        // restent modifiables.
        if ($this->coreUserAdminHelper->isReservedId($userId)) {
            ExceptionResponse::throwWarning('Les comptes d’identifiant réservé ne peuvent pas être supprimés.');
        }

        $groupId = $this->coreUserAdminHelper->getPersonalGroupId($userId, true);
        $this->coreUserAdminHelper->deleteUser($userId, $groupId);

        return true;
    }

    private function getUserRecord(int $userId, bool $lock): object
    {
        $user = $this->coreUserAdminHelper->getUser($userId, $lock);
        if ($user === null) ExceptionResponse::throwWarning('Utilisateur introuvable.');
        if (!$this->canReadUser((int) $user->space_id)) {
            ExceptionResponse::throwDanger('Vous ne pouvez pas consulter cet utilisateur.');
        }

        // Toute écriture administrative d'un compte ROOT exige un appelant ROOT.
        if ($lock && $this->protectedRootUsers([$userId], true) !== []) {
            ExceptionResponse::throwDanger('Seul un super administrateur peut modifier un compte ROOT.');
        }

        return $user;
    }

    /** @param list<int> $userIds @return array<int, true> */
    private function protectedRootUsers(array $userIds, bool $lock = false): array
    {
        if ($this->coreAclHelper->isRoot()) return [];

        return array_fill_keys($this->coreUserAdminHelper->getRootUserIds($userIds, $lock), true);
    }

    private function getPersonalGroup(int $userId): int
    {
        $groupId = $this->coreUserAdminHelper->getPersonalGroupId($userId, true);
        if ($groupId === null) ExceptionResponse::throwWarning('La configuration du compte est incomplète.');

        return $groupId;
    }

    private function can(string $permission, int $spaceId): bool
    {
        return $this->coreAclHelper->hasGlobalPermission($permission, $spaceId);
    }

    private function canReadUser(int $spaceId): bool
    {
        // Les autres droits de comptes ont USERS_READ pour prérequis explicite.
        return $this->can(Permissions::USERS_READ, $spaceId);
    }

    private function assertCan(string $permission, int $spaceId): void
    {
        if (!$this->can($permission, $spaceId)) {
            ExceptionResponse::throwDanger('Droit insuffisant sur l’espace demandé.');
        }
    }

    private function assertCanManageAssignment(int $userSpaceId, int $targetSpaceId, int $ownerSpaceId,
        string $permission = Permissions::USER_ROLES_CRUD): void
    {
        foreach (array_unique([$userSpaceId, $targetSpaceId, $ownerSpaceId]) as $spaceId) {
            $this->assertCan($permission, $spaceId);
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
                Permissions::USER_ROLES_CRUD, $permissions[$role['id']] ?? [], $role['space_id'])));
    }

    /**
     * Filtrer un catalogue de groupes par les permissions qu'il donne réellement,
     * parents compris et Space par Space.
     * @param list<array{id:int,name:string,space_id:int}> $groups
     * @return list<array{id:int,name:string,space_id:int}>
     */
    private function filterDelegableGroups(array $groups): array
    {
        $groupIds = array_map(static fn (array $group): int => $group['id'], $groups);
        $permissions = $this->coreUserGroupAdminHelper->getGroupPermissionsBySpace($groupIds);

        return array_values(array_filter($groups, function (array $group) use ($permissions): bool {
            foreach ($permissions[$group['id']] ?? [] as $spaceId => $spacePermissions) {
                if (!$this->coreAclHelper->canDelegateGlobalPermissions(
                    Permissions::USER_GROUPS_ASSIGN, $spacePermissions, $spaceId
                )) return false;
            }

            return true;
        }));
    }

    private function validateData(object $data): array
    {
        $firstName = trim((string) ($data->first_name ?? ''));
        $lastName = trim((string) ($data->last_name ?? ''));
        $email = mb_strtolower(trim((string) ($data->email ?? '')));
        $timezone = trim((string) ($data->timezone ?? ''));
        $blocked = $data->blocked ?? null;
        $sendEmail = $data->send_email ?? null;

        if ($firstName === '' || mb_strlen($firstName) > 100 || $lastName === ''
            || mb_strlen($lastName) > 100 || strlen($email) > 100
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || $timezone === '' || strlen($timezone) > 50
            || !in_array($timezone, \DateTimeZone::listIdentifiers(), true)
            || !is_bool($blocked) || !is_bool($sendEmail)) {
            ExceptionResponse::throwWarning('Informations du compte invalides.');
        }

        return [$firstName, $lastName, $email, $timezone, $blocked, $sendEmail];
    }

    private function validatePassword(mixed $password): void
    {
        if (!LocalPasswordPolicy::acceptsNewPassword($password)) {
            ExceptionResponse::throwWarning(LocalPasswordPolicy::MESSAGE);
        }
    }

    private function presentUser(object $user, array $relations, bool $rootProtected = false): array
    {
        // Les capacités accompagnent les données pour guider Vue ; chaque écriture
        // refait néanmoins les mêmes vérifications sur la ressource en base.
        $spaceId = (int) $user->space_id;
        $memberSpaces = array_values(array_unique($relations['spaces']));
        sort($memberSpaces);

        // Un compte de service n'a ni profil ni mot de passe interactif : les
        // capacités d'édition et de sécurité restent fermées côté serveur, même
        // si l'interface oubliait de masquer ses boutons.
        $accountType = (string) ($user->type ?? 'user');

        // Les attributions ne sont transmises qu'avec le droit de lecture correspondant.
        $visibleGroups = array_values(array_filter($relations['groups'],
            fn (array $group): bool => $this->can(Permissions::USER_GROUPS_READ, $group['space_id'])));
        $visibleRoles = array_values(array_filter($relations['roles'],
            fn (array $role): bool => $this->can(Permissions::USER_ROLES_READ, $role['target_space_id'])));
        $visibleSpaces = array_values(array_filter($memberSpaces,
            fn (int $id): bool => $this->can(Permissions::USER_ROLES_READ, $id)));
        $visibleInheritedSpaces = array_values(array_filter($relations['inherited_spaces'],
            fn (int $id): bool => $this->can(Permissions::USER_ROLES_READ, $id)));

        return [
            'id' => (int) $user->id,
            'space_id' => $spaceId,
            'type' => (string) ($user->type ?? 'user'),
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'timezone' => $user->timezone,
            'blocked' => (bool) $user->blocked,
            'send_email' => (bool) $user->send_email,
            'register_date' => $user->register_date,
            'spaces' => $visibleSpaces,
            'inherited_spaces' => $visibleInheritedSpaces,
            'groups' => $visibleGroups,
            'roles' => $visibleRoles,
            'can_edit' => !$rootProtected && $this->can(Permissions::USERS_CRUD, $spaceId)
                && $accountType !== 'service',
            'can_admin' => !$rootProtected && $this->can(Permissions::USERS_ADMIN, $spaceId)
                && $accountType !== 'service',
            'can_assign' => !$rootProtected && $this->can(Permissions::USER_ROLES_CRUD, $spaceId)
                && $this->can(Permissions::USER_ROLES_READ, $spaceId),
            'can_assign_groups' => !$rootProtected && $this->can(Permissions::USER_GROUPS_ASSIGN, $spaceId)
                && $this->can(Permissions::USER_GROUPS_READ, $spaceId),
            'can_delete' => !$rootProtected && $this->can(Permissions::USERS_CRUD, $spaceId)
                && $this->can(Permissions::USER_GROUPS_ASSIGN, $spaceId)
                && array_reduce($relations['groups'], fn (bool $allowed, array $group): bool => $allowed
                    && $this->can(Permissions::USER_GROUPS_ASSIGN, $group['space_id']), true)
                && $this->can(Permissions::USER_ROLES_CRUD, $spaceId)
                && $user->id != $this->session->getUserId()
                && array_reduce($memberSpaces, fn (bool $allowed, int $memberSpaceId): bool => $allowed
                    && $this->can(Permissions::USER_ROLES_CRUD, $memberSpaceId), true),
        ];
    }
}
