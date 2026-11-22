<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Migration;

use Override;
use YTassy\Tycms\CoreExts\Core\Helper\Extension\OfficialCatalog;
use YTassy\Tycms\Extensions\BaseMigrationVersion;

/**
 * Provenance des extensions installées depuis un catalogue externe.
 *
 * Une ligne est créée avant le téléchargement avec le statut « installing »,
 * puis confirmée en « installed ». Les échecs restent en « failed » afin de
 * conserver l'historique et de permettre une nouvelle tentative.
 */
final class Mig_1_1_5 extends BaseMigrationVersion
{
    #[Override]
    public function run(): array
    {
        $this->db->executeMultiple(<<<SQL
            CREATE TABLE IF NOT EXISTS `extension_installations` (
                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                `extension_id` varchar(64) NOT NULL,
                `type` varchar(16) NOT NULL,
                `folder` varchar(64) NOT NULL,
                `source_id` bigint unsigned NULL DEFAULT NULL,
                `source_name` varchar(150) NOT NULL,
                `source_url` varchar(500) NOT NULL,
                `version` varchar(48) NOT NULL,
                `key_id` varchar(64) NOT NULL,
                `archive_sha256` char(64) NOT NULL,
                `status` varchar(16) NOT NULL DEFAULT 'installing',
                `created_by` bigint unsigned NULL DEFAULT NULL,
                `created` datetime NOT NULL DEFAULT current_timestamp(),
                `updated` datetime NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_extension_installations_extension` (`extension_id`),
                KEY `idx_extension_installations_status` (`status`),
                KEY `idx_extension_installations_source` (`source_id`),
                CONSTRAINT `fk_extension_installations_source` FOREIGN KEY (`source_id`)
                    REFERENCES `extension_catalog_sources` (`id`) ON UPDATE CASCADE ON DELETE SET NULL,
                CONSTRAINT `fk_extension_installations_created_by` FOREIGN KEY (`created_by`)
                    REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL);

        // Le catalogue officiel est proposé dès l'installation. La clause ne
        // réécrit pas une source déjà présente : une licence, une désactivation
        // ou une autre clé adoptée par ROOT restent intactes.
        $this->db->execute(
            'INSERT INTO `extension_catalog_sources`
                (`name`, `base_url`, `key_id`, `public_key`, `license_encrypted`, `enabled`, `created_by`)
             VALUES (%, %, %, %, NULL, 1, NULL)
             ON DUPLICATE KEY UPDATE `id` = `id`',
            [
                OfficialCatalog::NAME,
                OfficialCatalog::URL,
                OfficialCatalog::KEY_ID,
                OfficialCatalog::PUBLIC_KEY,
            ],
        );

        return [
            'tables' => ['extension_installations'],
            'official_source' => OfficialCatalog::URL,
        ];
    }
}
