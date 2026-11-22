<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\TyCatalog\Migration;

use Override;
use YTassy\Tycms\Extensions\BaseMigrationVersion;

/**
 * Schéma serveur de TyCatalog, figé au lot 1.
 *
 * Les versions restent rattachées à une extension, elle-même rattachée à un
 * catalogue. Le Space réel se déduit donc par version → extension → catalogue ;
 * aucun space_id fourni par le navigateur ne participe à l'autorisation.
 */
final class Mig_1_0_0 extends BaseMigrationVersion
{
    #[Override]
    public function run(): array
    {
        $this->db->executeMultiple(<<<SQL
            CREATE TABLE IF NOT EXISTS `ty_catalog_catalogs` (
                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                `space_id` bigint unsigned NOT NULL,
                `slug` varchar(80) NOT NULL,
                `name` varchar(150) NOT NULL,
                `description` text NOT NULL DEFAULT '',
                `enabled` tinyint(1) NOT NULL DEFAULT 1,
                `key_id` varchar(64) NULL DEFAULT NULL,
                `public_key` varchar(128) NULL DEFAULT NULL,
                `created_by` bigint unsigned NULL DEFAULT NULL,
                `created` datetime NOT NULL DEFAULT current_timestamp(),
                `updated` datetime NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_ty_catalog_catalogs_slug` (`slug`),
                KEY `idx_ty_catalog_catalogs_space_enabled` (`space_id`, `enabled`),
                CONSTRAINT `fk_ty_catalog_catalogs_space` FOREIGN KEY (`space_id`) REFERENCES `spaces` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
                CONSTRAINT `fk_ty_catalog_catalogs_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            CREATE TABLE IF NOT EXISTS `ty_catalog_catalog_links` (
                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                `catalog_id` bigint unsigned NOT NULL,
                `name` varchar(150) NOT NULL,
                `target_url` varchar(500) NOT NULL,
                `description` text NOT NULL DEFAULT '',
                `position` int unsigned NOT NULL DEFAULT 0,
                `created` datetime NOT NULL DEFAULT current_timestamp(),
                `updated` datetime NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_ty_catalog_catalog_links_target` (`catalog_id`, `target_url`),
                KEY `idx_ty_catalog_catalog_links_position` (`catalog_id`, `position`),
                CONSTRAINT `fk_ty_catalog_catalog_links_catalog` FOREIGN KEY (`catalog_id`) REFERENCES `ty_catalog_catalogs` (`id`) ON UPDATE CASCADE ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            CREATE TABLE IF NOT EXISTS `ty_catalog_extensions` (
                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                `catalog_id` bigint unsigned NOT NULL,
                `extension_identifier` varchar(80) NOT NULL,
                `type` varchar(20) NOT NULL,
                `folder` varchar(80) NOT NULL,
                `display_name` varchar(150) NOT NULL,
                `description` text NOT NULL DEFAULT '',
                `created` datetime NOT NULL DEFAULT current_timestamp(),
                `updated` datetime NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_ty_catalog_extensions_identifier` (`catalog_id`, `extension_identifier`),
                KEY `idx_ty_catalog_extensions_type` (`catalog_id`, `type`),
                CONSTRAINT `fk_ty_catalog_extensions_catalog` FOREIGN KEY (`catalog_id`) REFERENCES `ty_catalog_catalogs` (`id`) ON UPDATE CASCADE ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            CREATE TABLE IF NOT EXISTS `ty_catalog_versions` (
                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                `extension_id` bigint unsigned NOT NULL,
                `version` varchar(32) NOT NULL,
                `status` varchar(20) NOT NULL DEFAULT 'draft',
                `signature_envelope` longtext NOT NULL,
                `metadata` mediumtext NOT NULL,
                `release_notes` mediumtext NOT NULL,
                `archive_bytes` bigint unsigned NOT NULL,
                `archive_sha256` char(64) NOT NULL,
                `storage_type` varchar(20) NOT NULL DEFAULT 'local',
                `storage_reference` varchar(512) NOT NULL,
                `created_by` bigint unsigned NULL DEFAULT NULL,
                `created` datetime NOT NULL DEFAULT current_timestamp(),
                `published_by` bigint unsigned NULL DEFAULT NULL,
                `published_at` datetime NULL DEFAULT NULL,
                `withdrawn_by` bigint unsigned NULL DEFAULT NULL,
                `withdrawn_at` datetime NULL DEFAULT NULL,
                `updated` datetime NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_ty_catalog_versions_extension_version` (`extension_id`, `version`),
                KEY `idx_ty_catalog_versions_status` (`extension_id`, `status`),
                KEY `idx_ty_catalog_versions_published` (`status`, `published_at`),
                CONSTRAINT `fk_ty_catalog_versions_extension` FOREIGN KEY (`extension_id`) REFERENCES `ty_catalog_extensions` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
                CONSTRAINT `fk_ty_catalog_versions_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE SET NULL,
                CONSTRAINT `fk_ty_catalog_versions_published_by` FOREIGN KEY (`published_by`) REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE SET NULL,
                CONSTRAINT `fk_ty_catalog_versions_withdrawn_by` FOREIGN KEY (`withdrawn_by`) REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL);

        // Le rôle 2 est le rôle système ROOT des installations TyCMS. Les autres
        // rôles ne reçoivent ces permissions que par une action explicite de ROOT.
        foreach (['VIEW', 'EDIT', 'PUBLISH', 'ADMIN'] as $name) {
            $this->db->execute(
                'INSERT INTO acl_permissions (role_id, extension, name)
                 SELECT 2, %, %
                 WHERE NOT EXISTS (
                     SELECT 1 FROM acl_permissions
                     WHERE role_id = 2 AND extension = % AND name = %
                 )',
                ['plg_ty_catalog', $name, 'plg_ty_catalog', $name],
            );
        }

        return [
            'tables' => [
                'ty_catalog_catalogs',
                'ty_catalog_catalog_links',
                'ty_catalog_extensions',
                'ty_catalog_versions',
            ],
            'root_permissions' => ['VIEW', 'EDIT', 'PUBLISH', 'ADMIN'],
        ];
    }
}
