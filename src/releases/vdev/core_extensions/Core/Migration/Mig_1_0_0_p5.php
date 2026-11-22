<?php

namespace YTassy\Tycms\CoreExts\Core\Migration;

use Override;
use YTassy\Tycms\Extensions\BaseMigrationVersion;

final class Mig_1_0_0_p5 extends BaseMigrationVersion
{
    #[Override]
    public function run(): array
    {
        $this->db->executeMultiple(<<<SQL
            CREATE TABLE IF NOT EXISTS `ai_providers` (
                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                `space_id` bigint unsigned NOT NULL,
                `name` varchar(150) NOT NULL,
                `type` varchar(30) NOT NULL,
                `base_url` varchar(500) NOT NULL,
                `enabled` tinyint(1) NOT NULL DEFAULT 0,
                `config` mediumtext NOT NULL,
                `secret_data` mediumtext NOT NULL,
                `created` datetime NOT NULL DEFAULT current_timestamp(),
                `updated` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_ai_provider_space` (`space_id`),
                CONSTRAINT `fk_ai_provider_space` FOREIGN KEY (`space_id`) REFERENCES `spaces` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);

        $this->db->execute(<<<SQL
            INSERT INTO acl_permissions (role_id, extension, name)
            SELECT 2, 'plg_core', 'AI_USE'
            WHERE NOT EXISTS (
                SELECT 1 FROM acl_permissions
                WHERE role_id = 2 AND extension = 'plg_core' AND name = 'AI_USE'
            )
        SQL);


                $this->db->execute(<<<SQL
            INSERT INTO acl_permissions (role_id, extension, name)
            SELECT 2, 'plg_core', 'AI_GENERATE_IMAGE'
            WHERE NOT EXISTS (
                SELECT 1 FROM acl_permissions
                WHERE role_id = 2 AND extension = 'plg_core' AND name = 'AI_GENERATE_IMAGE'
            )
        SQL);

        $this->db->execute(<<<SQL
            CREATE TABLE IF NOT EXISTS `space_languages` (
                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                `space_id` bigint unsigned NOT NULL,
                `locale` varchar(35) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
                `is_default` tinyint(1) NOT NULL DEFAULT 0,
                `enabled` tinyint(1) NOT NULL DEFAULT 1,
                `sort_order` int unsigned NOT NULL DEFAULT 0,
                `default_marker` tinyint(1) GENERATED ALWAYS AS (
                    CASE WHEN `is_default` = 1 THEN 1 ELSE NULL END
                ) STORED,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_space_language_locale` (`space_id`, `locale`),
                UNIQUE KEY `uq_space_language_default` (`space_id`, `default_marker`),
                KEY `idx_space_language_active` (`space_id`, `enabled`, `sort_order`),
                CONSTRAINT `fk_space_language_space` FOREIGN KEY (`space_id`)
                    REFERENCES `spaces` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT `chk_space_language_flags` CHECK (
                    `is_default` IN (0, 1) AND `enabled` IN (0, 1)
                    AND (`is_default` = 0 OR `enabled` = 1)
                )
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->db->execute(<<<SQL
            INSERT INTO `space_languages` (`space_id`, `locale`, `is_default`, `enabled`, `sort_order`)
            SELECT `spaces`.`id`, 'fr', 1, 1, 0
            FROM `spaces`
            WHERE NOT EXISTS (
                SELECT 1 FROM `space_languages`
                WHERE `space_languages`.`space_id` = `spaces`.`id`
            )
        SQL);

        // Les clés ajoutées dans l'administration appartiennent à un Space.
        $this->db->execute(<<<SQL
            CREATE TABLE `language_custom_keys` (
                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                `space_id` bigint unsigned NOT NULL,
                `lang_key` varchar(150) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `source_value` text NOT NULL,
                `created_by` bigint unsigned DEFAULT NULL,
                `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_language_custom_key` (`lang_key`),
                CONSTRAINT `fk_language_custom_key_space` FOREIGN KEY (`space_id`)
                    REFERENCES `spaces` (`id`) ON UPDATE CASCADE ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        // Une surcharge est unique pour une clé et une langue dans un Space.
        $this->db->execute(<<<SQL
            CREATE TABLE `language_overrides` (
                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                `space_id` bigint unsigned NOT NULL,
                `locale` varchar(35) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
                `lang_key` varchar(150) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `value` text NOT NULL,
                `updated_by` bigint unsigned DEFAULT NULL,
                `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_language_override` (`space_id`, `locale`, `lang_key`),
                KEY `idx_language_override_lookup` (`locale`, `space_id`),
                CONSTRAINT `fk_language_override_space` FOREIGN KEY (`space_id`)
                    REFERENCES `spaces` (`id`) ON UPDATE CASCADE ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        // Le rôle administrateur principal reçoit le nouveau droit de gestion.
        $this->db->execute(<<<SQL
            INSERT INTO acl_permissions (role_id, extension, name)
            SELECT 2, 'plg_core', 'LANG_OVERRIDE_CRUD'
            WHERE NOT EXISTS (
                SELECT 1 FROM acl_permissions
                WHERE role_id = 2 AND extension = 'plg_core' AND name = 'LANG_OVERRIDE_CRUD'
            )
        SQL);

         // Une ligne ne représente qu'une exception au comportement commun du menu.
        // L'absence de ligne conserve la visibilité de l'élément.
        $this->db->execute(<<<SQL
            CREATE TABLE `menu_item_languages` (
                `menu_item_id` bigint unsigned NOT NULL,
                `locale` varchar(35) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
                `enabled` tinyint(1) NOT NULL DEFAULT 1,
                PRIMARY KEY (`menu_item_id`, `locale`),
                KEY `idx_menu_item_language_locale` (`locale`, `menu_item_id`),
                CONSTRAINT `fk_menu_item_language_item` FOREIGN KEY (`menu_item_id`)
                    REFERENCES `menu_items` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT `chk_menu_item_language_enabled` CHECK (`enabled` IN (0, 1))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        // NULL conserve les entrées générales du Space pour les langues
        // existantes et pour toute nouvelle langue ajoutée par la suite.
        $this->db->execute(<<<SQL
            ALTER TABLE `space_languages`
                ADD COLUMN `menu_item_home_id` bigint unsigned NULL DEFAULT NULL,
                ADD COLUMN `menu_item_user_space_id` bigint unsigned NULL DEFAULT NULL,
                ADD KEY `idx_space_language_home` (`menu_item_home_id`),
                ADD KEY `idx_space_language_user` (`menu_item_user_space_id`),
                ADD CONSTRAINT `fk_space_language_home` FOREIGN KEY (`menu_item_home_id`)
                    REFERENCES `menu_items` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
                ADD CONSTRAINT `fk_space_language_user` FOREIGN KEY (`menu_item_user_space_id`)
                    REFERENCES `menu_items` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT
        SQL);

        // Le rôle Root doit pouvoir utiliser l'administration sensible des comptes.
        $this->db->execute(<<<SQL
            INSERT INTO acl_permissions (role_id, extension, name)
            SELECT 2, 'plg_core', 'USERS_ADMIN'
            WHERE NOT EXISTS (
                SELECT 1 FROM acl_permissions
                WHERE role_id = 2 AND extension = 'plg_core' AND name = 'USERS_ADMIN'
            )
            SQL);


         // Le code définit les modèles ; la base ne porte que les textes personnalisés.
        $this->db->execute(<<<SQL
            CREATE TABLE `email_template_overrides` (
                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                `space_id` bigint unsigned NOT NULL,
                `template_key` varchar(150) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `locale` varchar(35) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
                `subject` varchar(255) NOT NULL,
                `body_html` mediumtext NOT NULL,
                `updated_by` bigint unsigned DEFAULT NULL,
                `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_email_template_override` (`space_id`, `template_key`, `locale`),
                KEY `idx_email_template_lookup` (`template_key`, `locale`, `space_id`),
                CONSTRAINT `fk_email_template_space` FOREIGN KEY (`space_id`)
                    REFERENCES `spaces` (`id`) ON UPDATE CASCADE ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        // Le rôle administrateur principal peut gérer les textes des modèles.
        $this->db->execute(<<<SQL
            INSERT INTO acl_permissions (role_id, extension, name)
            SELECT 2, 'plg_core', 'EMAIL_TEMPLATE_EDIT'
            WHERE NOT EXISTS (
                SELECT 1 FROM acl_permissions
                WHERE role_id = 2 AND extension = 'plg_core' AND name = 'EMAIL_TEMPLATE_EDIT'
            )
        SQL);

        // La bibliothèque est commune : aucun rattachement à une template ou à un Space.
        $this->db->execute(<<<SQL
            CREATE TABLE `font_installations` (
                `font_key` varchar(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `storage_version` char(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `enabled` tinyint unsigned NOT NULL DEFAULT 1,
                `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`font_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        // Le rôle principal reçoit les deux droits ; FONT_ADD reste délégable par Space.
        foreach (['FONT_ADD', 'FONT_MANAGE'] as $permission) {
            $this->db->execute(
                "INSERT INTO acl_permissions (role_id, extension, name) SELECT 2, 'plg_core', % "
                    . "WHERE NOT EXISTS (SELECT 1 FROM acl_permissions WHERE role_id = 2 AND extension = 'plg_core' AND name = %)",
                [$permission, $permission],
            );
        }

        // Conserver la publication des pages existantes et des nouvelles pages.
        $this->db->execute('ALTER TABLE pages ADD COLUMN status TINYINT NOT NULL DEFAULT 1');


        // Modifier uniquement les anciens choix typographiques de Main, dans tous les Spaces.
        // Les familles installées et les autres paramètres restent conservés.
        foreach (['theme_font_family', 'theme_heading_font_family'] as $field) {
            $path = '$.' . $field;
            foreach (['system' => 'arial', 'nunito' => 'arial', 'bona_nova' => 'georgia'] as $old => $new) {
                $this->db->execute(
                    'UPDATE config SET params = JSON_SET(params, %, %) '
                        . 'WHERE extension = % AND JSON_UNQUOTE(JSON_EXTRACT(params, %)) = %',
                    [$path, $new, 'tpl_main', $path, $old],
                );
            }
        }

        
        return [];
    }
}
