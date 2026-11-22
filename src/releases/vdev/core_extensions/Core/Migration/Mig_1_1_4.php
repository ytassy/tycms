<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Migration;

use Override;
use YTassy\Tycms\Extensions\BaseMigrationVersion;

/**
 * Sources de catalogues externes du client Core.
 *
 * La licence n'est jamais stockée en clair ; la clé publique est la racine de
 * confiance adoptée explicitement par ROOT.
 */
final class Mig_1_1_4 extends BaseMigrationVersion
{
    #[Override]
    public function run(): array
    {
        $this->db->executeMultiple(<<<SQL
            CREATE TABLE IF NOT EXISTS `extension_catalog_sources` (
                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                `name` varchar(150) NOT NULL,
                `base_url` varchar(500) NOT NULL,
                `key_id` varchar(64) NOT NULL,
                `public_key` varchar(128) NOT NULL,
                `license_encrypted` text NULL DEFAULT NULL,
                `enabled` tinyint(1) NOT NULL DEFAULT 1,
                `created_by` bigint unsigned NULL DEFAULT NULL,
                `created` datetime NOT NULL DEFAULT current_timestamp(),
                `updated` datetime NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_extension_catalog_sources_url` (`base_url`),
                KEY `idx_extension_catalog_sources_enabled` (`enabled`),
                CONSTRAINT `fk_extension_catalog_sources_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL);

        return ['tables' => ['extension_catalog_sources']];
    }
}
