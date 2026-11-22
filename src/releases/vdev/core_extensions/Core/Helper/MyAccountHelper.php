<?php

namespace YTassy\Tycms\CoreExts\Core\Helper;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;

/** Persistance du compte courant ; le WF fixe toujours l'identité autorisée. */
class MyAccountHelper extends BaseHelper
{
    public function getAccount(int $userId, bool $lock = false): ?object
    {
        return $this->db->selectOneOrNull(
            'SELECT id, space_id, first_name, last_name, email, timezone, password, params, blocked '
                . 'FROM users WHERE id = %' . ($lock ? ' FOR UPDATE' : ''), [$userId],
        );
    }

    public function getLinks(int $userId): array
    {
        return $this->db->select(
            'SELECT link.id, link.provider_id, provider.name FROM sso_links link '
                . 'INNER JOIN sso_providers provider ON provider.id = link.provider_id '
                . 'WHERE link.user_id = % ORDER BY provider.name, link.id', [$userId],
        );
    }

    public function updateProfile(int $userId, string $firstName, string $lastName, string $email, string $timezone): void
    {
        // Invalider tout lien de récupération envoyé à l'ancienne adresse.
        $this->db->execute(
            "UPDATE users SET reset_password_token = IF(email <> %, '', reset_password_token), "
                . 'first_name = %, last_name = %, email = %, timezone = %, updated = CURRENT_TIMESTAMP WHERE id = %',
            [$email, $firstName, $lastName, $email, $timezone, $userId],
        );
    }

    public function markLocalPassword(int $userId, bool $enabled): void
    {
        $this->db->execute(
            "UPDATE users SET params = JSON_SET(COALESCE(params, '{}'), '$.local_password_enabled', %) WHERE id = %",
            [$enabled ? 1 : 0, $userId],
        );
    }

    public function findIdentity(int $providerId, string $subject): ?object
    {
        return $this->db->selectOneOrNull(
            'SELECT id, user_id FROM sso_links WHERE provider_id = % AND external_subject = % FOR UPDATE',
            [$providerId, $subject],
        );
    }

    public function addIdentity(int $userId, int $providerId, string $subject, string $encrypted): void
    {
        $this->db->execute(
            'INSERT INTO sso_links (user_id, provider_id, external_subject, data, data_key_version, last_used) '
                . 'VALUES (%, %, %, %, 1, CURRENT_TIMESTAMP)', [$userId, $providerId, $subject, $encrypted],
        );
    }
}
