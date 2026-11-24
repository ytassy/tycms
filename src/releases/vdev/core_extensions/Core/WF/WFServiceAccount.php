<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\WF;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\UserGroups;
use YTassy\Tycms\CoreExts\Core\Helper\ServiceAccountTokenHelper;
use YTassy\Tycms\CoreExts\Core\Helper\TraitAllHelpers;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\WF\BaseWF;

/**
 * Workflow métier des comptes de service et de leurs jetons d'API.
 *
 * Un compte de service est une ligne `users` de type `service`, sans mot de
 * passe, porteuse de groupes/rôles/permissions comme un compte ordinaire.
 */
final class WFServiceAccount extends BaseWF
{
    use TraitAllHelpers;

    /** @return array<string, mixed> */
    public function list(): array
    {
        $this->assertRoot();

        $rows = $this->db->select(
            'SELECT u.id, u.space_id, u.first_name, u.last_name, u.email, '
            . '(SELECT COUNT(*) FROM api_tokens t WHERE t.user_id = u.id) AS token_count '
            . 'FROM users u WHERE u.type = % ORDER BY u.id',
            ['service'],
        );

        return array_map(static fn (object $row): array => [
            'id' => (int) $row->id,
            'spaceId' => (int) $row->space_id,
            'name' => (string) $row->first_name,
            'lastName' => (string) $row->last_name,
            'email' => (string) $row->email,
            'type' => 'service',
            'tokensCount' => (int) $row->token_count,
        ], $rows);
    }

    /** @return array<string, mixed> */
    public function create(string $name, string $email, int $spaceId): array
    {
        $this->assertRoot();

        $space = $this->coreSpaceHelper->getAllSpacesByIds()[$spaceId] ?? null;

        if ($space === null) {
            ExceptionResponse::throwWarning('Espace invalide.');
        }

        $name = trim($name);
        $email = trim($email);

        if ($name === '' || $email === '') {
            ExceptionResponse::throwWarning('Le nom et l’adresse e-mail du compte sont requis.');
        }

        if ($this->coreUserAdminHelper->emailExists($email)) {
            ExceptionResponse::throwWarning('Cette adresse e-mail est déjà utilisée.');
        }

        // Un compte de service n'a pas de mot de passe : la colonne reste vide,
        // ce qui empêche toute connexion interactive par identifiant.
        //
        // Volontairement, cet INSERT n'utilise pas UserRegistrationHelper : il
        // n'émet donc pas `core.user.created`. Un compte de service ne doit ni
        // déclencher les scénarios de bienvenue, ni passer par le parcours de
        // rattachement d'un compte utilisateur.
        $this->db->execute(
            'INSERT INTO users (space_id, type, first_name, last_name, email, password, params, register_date) '
            . "VALUES (%, 'service', %, '', %, '', '{}', current_timestamp())",
            [$spaceId, $name, $email],
        );
        $userId = (int) $this->db->getLastInsertId();

        // Le groupe personnel porte les rôles du compte, comme pour un compte
        // ordinaire ; son nom le distingue des groupes « user-{id} ».
        $this->db->execute(
            'INSERT INTO user_groups (space_id, parent_id, `type`, `name`) VALUES (%, %, 1, %)',
            [$spaceId, UserGroups::GROUP_USERS, 'service-' . $userId],
        );
        $groupId = (int) $this->db->getLastInsertId();

        $this->db->execute('INSERT INTO user_groups_map (user_id, group_id) VALUES (%, %)', [$userId, $groupId]);

        return [
            'id' => $userId,
            'spaceId' => $spaceId,
            'name' => $name,
            'email' => $email,
            'type' => 'service',
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function tokens(int $userId): array
    {
        $this->assertRoot();
        $this->assertServiceAccount($userId);

        // Le secret n'est jamais relu : seule sa trace est listeée ici.
        $rows = $this->db->select(
            'SELECT id, name, space_id, expires_at, last_used_at, revoked_at, created '
            . 'FROM api_tokens WHERE user_id = % ORDER BY id DESC',
            [$userId],
        );

        return array_map(static fn (object $row): array => [
            'id' => (int) $row->id,
            'name' => (string) $row->name,
            'spaceId' => $row->space_id === null ? null : (int) $row->space_id,
            'expiresAt' => $row->expires_at === null ? null : (string) $row->expires_at,
            'lastUsedAt' => $row->last_used_at === null ? null : (string) $row->last_used_at,
            'revoked' => $row->revoked_at !== null,
            'created' => (string) $row->created,
        ], $rows);
    }

    /** @return array{id: int, token: string} */
    public function createToken(int $userId, string $name, ?int $spaceId, ?string $expiresAt): array
    {
        $this->assertRoot();
        $this->assertServiceAccount($userId);

        $name = trim($name);

        if ($name === '') {
            ExceptionResponse::throwWarning('Le nom du jeton est requis.');
        }

        $expires = ($expiresAt !== null && $expiresAt !== '') ? new \DateTimeImmutable($expiresAt) : null;

        return $this->tokenHelper()->createToken($userId, $name, $spaceId, $expires);
    }

    public function revokeToken(int $tokenId): bool
    {
        $this->assertRoot();

        return $this->tokenHelper()->revoke($tokenId);
    }

    /**
     * Supprime un compte de service et son groupe personnel.
     *
     * Les jetons d'API suivent la cascade de la clé étrangère. Aucun autre
     * compte ne peut être supprimé par ce parcours.
     */
    public function delete(int $userId): bool
    {
        $this->assertRoot();
        $this->assertServiceAccount($userId, true);

        // Le groupe personnel porte les rôles du compte : le supprimer avec lui.
        $groupId = $this->coreUserAdminHelper->getPersonalGroupId($userId, true);
        $this->coreUserAdminHelper->deleteUser($userId, $groupId);

        return true;
    }

    private function assertRoot(): void
    {
        if (!$this->coreAclHelper->isRoot()) {
            ExceptionResponse::throwDanger('Action réservée au super administrateur.');
        }
    }

    private function assertServiceAccount(int $userId, bool $lock = false): void
    {
        $user = $this->db->selectOneOrNull(
            'SELECT id, type FROM users WHERE id = %' . ($lock ? ' FOR UPDATE' : '') . ' LIMIT 1',
            [$userId],
        );

        if ($user === null || $user->type !== 'service') {
            ExceptionResponse::throwWarning('Compte de service introuvable.');
        }
    }

    private function tokenHelper(): ServiceAccountTokenHelper
    {
        /** @var ServiceAccountTokenHelper $helper */
        $helper = $this->getSharedExtensionsClass(ServiceAccountTokenHelper::class);

        return $helper;
    }
}