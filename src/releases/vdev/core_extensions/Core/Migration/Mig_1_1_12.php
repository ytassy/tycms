<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Migration;

use Override;
use YTassy\Tycms\Extensions\BaseMigrationVersion;

/**
 * Modèles d'e-mail personnalisés.
 *
 * `email_templates` porte la définition créée depuis l'administration pour un
 * Space : libellé, description, mise en page, langue de base et variables. Le
 * contenu reste dans `email_template_overrides`, comme pour les modèles du
 * code : l'espace propriétaire y détient le contenu de base et les espaces
 * descendants peuvent l'adapter. La clé `custom__...` est unique dans
 * l'installation, y compris face aux clés déclarées par les extensions.
 */
final class Mig_1_1_12 extends BaseMigrationVersion
{
    #[Override]
    public function run(): array
    {
        $this->db->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `email_templates` (
                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                `space_id` bigint unsigned NOT NULL,
                `template_key` varchar(150) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `label` varchar(150) NOT NULL,
                `description` varchar(500) NOT NULL DEFAULT '',
                `render_mode` varchar(10) NOT NULL DEFAULT 'default',
                `base_locale` varchar(35) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
                `variables` text NOT NULL,
                `created_by` bigint unsigned NULL DEFAULT NULL,
                `updated_by` bigint unsigned NULL DEFAULT NULL,
                `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_email_templates_key` (`template_key`),
                KEY `idx_email_templates_space` (`space_id`),
                CONSTRAINT `fk_email_templates_space` FOREIGN KEY (`space_id`)
                    REFERENCES `spaces` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT `fk_email_templates_created_by` FOREIGN KEY (`created_by`)
                    REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE SET NULL,
                CONSTRAINT `fk_email_templates_updated_by` FOREIGN KEY (`updated_by`)
                    REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        return [
            'tables' => ['email_templates'],
            'indexes' => ['email_templates.uq_email_templates_key', 'email_templates.idx_email_templates_space'],
        ];
    }
}
