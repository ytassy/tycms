<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\TyCatalog\Migration;

use Override;
use YTassy\Tycms\Extensions\BaseMigrationVersion;

/**
 * Jetons de publication distante.
 *
 * Seul le SHA-256 du jeton est conservé ; le secret n'est affiché qu'à la
 * création. Un jeton est limité à un catalogue et à des droits explicites.
 */
final class Mig_1_0_2 extends BaseMigrationVersion
{
    #[Override]
    public function run(): array
    {
        $this->db->executeMultiple(<<<SQL
            CREATE TABLE IF NOT EXISTS `ty_catalog_tokens` (
                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                `catalog_id` bigint unsigned NOT NULL,
                `label` varchar(150) NOT NULL,
                `token_hash` char(64) NOT NULL,
                `abilities` varchar(100) NOT NULL DEFAULT 'deposit',
                `enabled` tinyint(1) NOT NULL DEFAULT 1,
                `expires_at` datetime NULL DEFAULT NULL,
                `last_used_at` datetime NULL DEFAULT NULL,
                `created_by` bigint unsigned NULL DEFAULT NULL,
                `created` datetime NOT NULL DEFAULT current_timestamp(),
                `revoked_at` datetime NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_ty_catalog_tokens_hash` (`token_hash`),
                KEY `idx_ty_catalog_tokens_catalog` (`catalog_id`, `enabled`),
                CONSTRAINT `fk_ty_catalog_tokens_catalog` FOREIGN KEY (`catalog_id`) REFERENCES `ty_catalog_catalogs` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT `fk_ty_catalog_tokens_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL);

        return ['tables' => ['ty_catalog_tokens']];
    }
}
