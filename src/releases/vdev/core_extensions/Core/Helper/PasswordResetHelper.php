<?php

namespace YTassy\Tycms\CoreExts\Core\Helper;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;

/** Persistance des demandes ; les autorisations restent dans le workflow. */
class PasswordResetHelper extends BaseHelper
{
    public function findByEmailForUpdate(string $email): ?object
    {
        return $this->db->selectOneOrNull(
            'SELECT u.*, EXISTS(SELECT 1 FROM sso_links s WHERE s.user_id = u.id) AS has_sso_link '
                . 'FROM users u WHERE u.email = % FOR UPDATE', [$email],
        );
    }

    public function findByToken(string $hash, bool $forUpdate = false): ?object
    {
        return $this->db->selectOneOrNull(
            'SELECT u.*, EXISTS(SELECT 1 FROM sso_links s WHERE s.user_id = u.id) AS has_sso_link, '
                . '(u.reset_password_request_date > DATE_SUB(CURRENT_TIMESTAMP, INTERVAL % SECOND) '
                . 'AND u.reset_password_request_date <= CURRENT_TIMESTAMP) AS token_valid '
                . 'FROM users u WHERE u.reset_password_token = %' . ($forUpdate ? ' FOR UPDATE' : ''),
            [max(1, (int) ($this->app->getConf()->password_reset_token_lifetime ?? 1800)), $hash],
        );
    }

    public function storeRequest(int $userId, string $hash): bool
    {
        // La condition SQL protège le délai partagé, y compris entre deux navigateurs.
        return $this->db->execute(
            'UPDATE users SET reset_password_token = %, reset_password_request_date = CURRENT_TIMESTAMP '
                . 'WHERE id = % AND (reset_password_request_date IS NULL '
                . 'OR reset_password_request_date <= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL % SECOND))',
            [$hash, $userId, max(0, (int) ($this->app->getConf()->password_reset_request_delay ?? 60))],
        ) === 1;
    }

    public function clearToken(int $userId, string $hash): void
    {
        // Conserver la date pour limiter aussi les tentatives en cas de panne SMTP.
        $this->db->execute(
            'UPDATE users SET reset_password_token = % WHERE id = % AND reset_password_token = %',
            ['', $userId, $hash],
        );
    }

    public function consume(int $userId, string $hash, string $passwordHash): bool
    {
        // L'effacement et le changement de mot de passe sont une seule écriture conditionnelle.
        return $this->db->execute(
            "UPDATE users SET params = JSON_SET(COALESCE(params, '{}'), '$.local_password_enabled', 1), "
                . 'password = %, reset_password_token = %, '
                . 'reset_password_last_reset_date = CURRENT_TIMESTAMP, reset_count = reset_count + 1, '
                . 'updated = CURRENT_TIMESTAMP WHERE id = % AND reset_password_token = % AND blocked = 0 '
                . 'AND reset_password_request_date > DATE_SUB(CURRENT_TIMESTAMP, INTERVAL % SECOND) '
                . 'AND reset_password_request_date <= CURRENT_TIMESTAMP',
            [$passwordHash, '', $userId, $hash,
                max(1, (int) ($this->app->getConf()->password_reset_token_lifetime ?? 1800))],
        ) === 1;
    }
}
