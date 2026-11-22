<?php

namespace YTassy\Tycms\CoreExts\Core\Migration;

use Override;
use YTassy\Tycms\Extensions\BaseMigrationVersion;

class Mig_1_0_0_p4 extends BaseMigrationVersion
{
    #[Override]
    public function run(): array
    {
        $this->db->executeMultiple(<<<SQL
            CREATE TABLE IF NOT EXISTS `sso_providers` (
                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                `space_id` bigint unsigned NOT NULL,
                `name` varchar(150) NOT NULL,
                `enabled` tinyint(1) NOT NULL DEFAULT 0,
                `protocol` varchar(20) NOT NULL,
                `login_enabled` tinyint(1) NOT NULL DEFAULT 0,
                `integration_enabled` tinyint(1) NOT NULL DEFAULT 0,
                `auto_link_account` tinyint(1) NOT NULL DEFAULT 0,
                `config` mediumtext NOT NULL,
                `secret_data` mediumblob DEFAULT NULL,
                `secret_key_version` smallint unsigned NOT NULL DEFAULT 1,
                `mapping` mediumtext NOT NULL,
                `login_button_text` varchar(150) NOT NULL,
                `register_button_text` varchar(150) NOT NULL,
                `created` datetime NOT NULL DEFAULT current_timestamp(),
                `updated` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_sso_provider_space` (`space_id`),
                CONSTRAINT `fk_sso_provider_space` FOREIGN KEY (`space_id`) REFERENCES `spaces` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            CREATE TABLE IF NOT EXISTS `sso_links` (
                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                `user_id` bigint unsigned NOT NULL,
                `provider_id` bigint unsigned NOT NULL,
                `external_subject` varbinary(255) NOT NULL,
                `data` mediumblob NOT NULL,
                `data_key_version` smallint unsigned NOT NULL DEFAULT 1,
                `created` datetime NOT NULL DEFAULT current_timestamp(),
                `last_used` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_sso_link_subject` (`provider_id`, `external_subject`),
                KEY `idx_sso_link_user_provider` (`user_id`, `provider_id`),
                CONSTRAINT `fk_sso_link_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT `fk_sso_link_provider` FOREIGN KEY (`provider_id`) REFERENCES `sso_providers` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            CREATE TABLE IF NOT EXISTS `oauth_grants` (
                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                `user_id` bigint unsigned NOT NULL,
                `provider_id` bigint unsigned NOT NULL,
                `token_data` mediumblob NOT NULL,
                `token_key_version` smallint unsigned NOT NULL DEFAULT 1,
                `expires_at` datetime DEFAULT NULL,
                `created` datetime NOT NULL DEFAULT current_timestamp(),
                `updated` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_oauth_grant_user_provider` (`user_id`, `provider_id`),
                CONSTRAINT `fk_oauth_grant_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT `fk_oauth_grant_provider` FOREIGN KEY (`provider_id`) REFERENCES `sso_providers` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            ALTER TABLE `users` MODIFY `password` varchar(255) NOT NULL DEFAULT '';
        SQL);

        $this->db->execute(<<<SQL
            INSERT INTO acl_permissions (role_id, extension, name)
            SELECT 2, 'plg_core', 'SSO_CRUD'
            WHERE NOT EXISTS (
                SELECT 1 FROM acl_permissions
                WHERE role_id = 2 AND extension = 'plg_core' AND name = 'SSO_CRUD'
            )
            SQL);

        return [];
    }
}
