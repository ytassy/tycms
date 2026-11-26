<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Migration;

use Override;
use YTassy\Tycms\Extensions\BaseMigrationVersion;

/**
 * Historique des pages.
 *
 * Une ligne conserve l'état d'une page juste avant son remplacement. La
 * rétention est appliquée à chaque enregistrement par `PageHistoryHelper` :
 * 20 versions pour le jour courant, puis la dernière version de chacun des
 * 29 jours précédents.
 */
final class Mig_1_1_10 extends BaseMigrationVersion
{
    #[Override]
    public function run(): array
    {
        $this->db->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `page_history` (
                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                `page_id` bigint unsigned NOT NULL,
                `user_id` bigint unsigned NULL DEFAULT NULL,
                `name` varchar(100) NOT NULL,
                `description` text NOT NULL DEFAULT '',
                `type` int NOT NULL DEFAULT 2,
                `raw_html` mediumtext NULL DEFAULT NULL,
                `raw_scss` mediumtext NULL DEFAULT NULL,
                `data` mediumtext NULL DEFAULT NULL,
                `access_edit_role_id` bigint unsigned NULL DEFAULT NULL,
                `created` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_page_history_page_created` (`page_id`, `created`, `id`),
                CONSTRAINT `fk_page_history_page` FOREIGN KEY (`page_id`)
                    REFERENCES `pages` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT `fk_page_history_user` FOREIGN KEY (`user_id`)
                    REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE SET NULL,
                CONSTRAINT `fk_page_history_edit_role` FOREIGN KEY (`access_edit_role_id`)
                    REFERENCES `acl_roles` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $this->db->execute(
            'ALTER TABLE `extension_catalog_sources`
             ADD COLUMN `channel` varchar(16) NOT NULL DEFAULT \'release\' AFTER `enabled`',
        );

        return [
            'tables' => ['page_history'],
            'indexes' => ['page_history.idx_page_history_page_created'],
            'columns' => ['extension_catalog_sources.channel']
        ];
    }
}
