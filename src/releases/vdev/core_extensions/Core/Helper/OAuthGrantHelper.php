<?php

namespace YTassy\Tycms\CoreExts\Core\Helper;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;

/** SQL writes keep OAuth credentials separate from SSO identity links. */
final class OAuthGrantHelper extends BaseHelper
{
    public function getGrant(int $userId, int $providerId): ?object
    {
        return $this->db->selectOneOrNull(
            'SELECT token_data, token_key_version, expires_at FROM oauth_grants WHERE user_id = % AND provider_id = %',
            [$userId, $providerId]
        );
    }

    /** @return list<object> */
    public function getGrants(int $userId): array
    {
        return $this->db->select('SELECT provider_id, expires_at FROM oauth_grants WHERE user_id = %', [$userId]);
    }

    public function saveGrant(int $userId, int $providerId, string $encrypted, ?int $expires): void
    {
        $this->db->execute(<<<SQL
            INSERT INTO oauth_grants (user_id, provider_id, token_data, token_key_version, expires_at)
            VALUES (%, %, %, 1, %)
            ON DUPLICATE KEY UPDATE token_data = VALUES(token_data), token_key_version = 1,
                expires_at = VALUES(expires_at), updated = current_timestamp()
            SQL, [$userId, $providerId, $encrypted,
                $expires === null ? null : gmdate('Y-m-d H:i:s', $expires)]);
    }

    public function deleteGrant(int $userId, int $providerId): void
    {
        $this->db->execute('DELETE FROM oauth_grants WHERE user_id = % AND provider_id = %', [$userId, $providerId]);
    }

    public function replaceGrantIfUnchanged(int $userId, int $providerId, string $oldEncrypted, string $newEncrypted, ?int $expires): bool
    {
        return $this->db->execute(<<<SQL
            UPDATE oauth_grants SET token_data = %, token_key_version = 1, expires_at = %,
                updated = current_timestamp()
            WHERE user_id = % AND provider_id = % AND token_data = %
            SQL, [$newEncrypted, $expires === null ? null : gmdate('Y-m-d H:i:s', $expires),
                $userId, $providerId, $oldEncrypted]) === 1;
    }
}
