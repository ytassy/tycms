<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Migration;

use Override;
use YTassy\Tycms\Extensions\BaseMigrationVersion;

/**
 * Journal métier des événements.
 *
 * Une ligne décrit une occurrence émise par un workflow : Space réel, type,
 * acteur, objet concerné, charge utile JSON et date UTC. La ligne est écrite
 * dans la transaction métier par `EventJournalListener` ; elle sert de source
 * aux notifications et de trace durable pour l’administration.
 *
 * Aucune clé d’occurrence n’est unique : un double appel de workflow produit
 * deux événements, ce qui est accepté. La purge applique la rétention par Space
 * de la configuration de `plg_core`.
 */
final class Mig_1_1_15 extends BaseMigrationVersion
{
    #[Override]
    public function run(): array
    {
        $this->db->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `event_log` (
                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                `space_id` bigint unsigned NOT NULL,
                `event_type` varchar(100) NOT NULL,
                `category` varchar(16) NOT NULL DEFAULT 'user',
                `severity` varchar(16) NOT NULL DEFAULT 'info',
                `actor_id` bigint unsigned NULL DEFAULT NULL,
                `actor_type` varchar(16) NOT NULL DEFAULT 'user',
                `subject_type` varchar(100) NULL DEFAULT NULL,
                `subject_id` bigint unsigned NULL DEFAULT NULL,
                `data` json NOT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_event_log_space_created` (`space_id`, `created_at`),
                KEY `idx_event_log_space_type_created` (`space_id`, `event_type`, `created_at`),
                KEY `idx_event_log_actor_created` (`actor_id`, `created_at`),
                KEY `idx_event_log_created` (`created_at`),
                CONSTRAINT `fk_event_log_space` FOREIGN KEY (`space_id`)
                    REFERENCES `spaces` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT `fk_event_log_actor` FOREIGN KEY (`actor_id`)
                    REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        return [
            'tables' => ['event_log'],
            'indexes' => [
                'event_log.idx_event_log_space_created',
                'event_log.idx_event_log_space_type_created',
                'event_log.idx_event_log_actor_created',
                'event_log.idx_event_log_created',
            ],
        ];
    }
}
