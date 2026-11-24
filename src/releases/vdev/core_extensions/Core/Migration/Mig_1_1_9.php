<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Migration;

use Override;
use YTassy\Tycms\Extensions\BaseMigrationVersion;

/**
 * Comptes de service et jetons d'API.
 *
 * Un compte de service est une ligne `users` de type `service`, sans mot de
 * passe ni connexion interactive. Ses jetons d'API sont hachés (SHA-256) et
 * jamais stockés en clair.
 */
final class Mig_1_1_9 extends BaseMigrationVersion
{
    #[Override]
    public function run(): array
    {
        // Conserver les comptes existants inchangés : un utilisateur classique
        // reçoit « user », les comptes de service seront créés avec « service ».
        $this->db->execute(
            "ALTER TABLE `users` ADD COLUMN `type` varchar(16) NOT NULL DEFAULT 'user'",
        );

        $this->db->execute(<<<SQL
            CREATE TABLE IF NOT EXISTS `api_tokens` (
                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                `user_id` bigint unsigned NOT NULL,
                `name` varchar(120) NOT NULL,
                `token_hash` char(64) NOT NULL,
                `space_id` bigint unsigned NULL DEFAULT NULL,
                `expires_at` datetime NULL DEFAULT NULL,
                `last_used_at` datetime NULL DEFAULT NULL,
                `revoked_at` datetime NULL DEFAULT NULL,
                `created` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_api_token_hash` (`token_hash`),
                KEY `idx_api_token_user` (`user_id`),
                CONSTRAINT `fk_api_token_user` FOREIGN KEY (`user_id`)
                    REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT `fk_api_token_space` FOREIGN KEY (`space_id`)
                    REFERENCES `spaces` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        return [
            'tables' => ['api_tokens'],
        ];
    }
}