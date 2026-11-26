<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Migration;

use Override;
use YTassy\Tycms\Extensions\BaseMigrationVersion;

/**
 * File de jobs différés.
 *
 * `jobs` porte les lignes de travail ; `job_worker_state` conserve le dernier
 * passage du worker, seul moyen de constater qu'une installation n'a pas
 * configuré le CRON de la file.
 *
 * Les instructions sont idempotentes : rejouer cette migration n'altère aucune
 * table existante.
 */
final class Mig_1_1_11 extends BaseMigrationVersion
{
    #[Override]
    public function run(): array
    {
        // La sélection des candidats suit l'index (status, available_at) ;
        // l'unicité (type, dedup_key) rend l'enfilement idempotent sans lecture
        // préalable. Les clés étrangères bornent la durée de vie des jobs à
        // celle de leur Space et détachent l'auteur supprimé.
        $this->db->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `jobs` (
                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                `space_id` bigint unsigned NULL DEFAULT NULL,
                `type` varchar(100) NOT NULL,
                `producer` varchar(40) NOT NULL,
                `payload` text NOT NULL,
                `status` varchar(16) NOT NULL DEFAULT 'pending',
                `priority` tinyint unsigned NOT NULL DEFAULT 50,
                `attempts` smallint unsigned NOT NULL DEFAULT 0,
                `max_attempts` smallint unsigned NOT NULL DEFAULT 3,
                `principal` varchar(10) NOT NULL DEFAULT 'none',
                `exec_user_id` bigint unsigned NULL DEFAULT NULL,
                `created_by` bigint unsigned NULL DEFAULT NULL,
                `dedup_key` varchar(120) NULL DEFAULT NULL,
                `available_at` datetime NOT NULL DEFAULT current_timestamp(),
                `reserved_until` datetime NULL DEFAULT NULL,
                `claim` char(32) NULL DEFAULT NULL,
                `cancel_requested` tinyint(1) NOT NULL DEFAULT 0,
                `last_error` varchar(500) NULL DEFAULT NULL,
                `created` datetime NOT NULL DEFAULT current_timestamp(),
                `started_at` datetime NULL DEFAULT NULL,
                `finished_at` datetime NULL DEFAULT NULL,
                `duration_ms` int unsigned NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `idx_jobs_dedup` (`type`, `dedup_key`),
                KEY `idx_jobs_selection` (`status`, `available_at`, `priority`, `id`),
                KEY `idx_jobs_recovery` (`status`, `reserved_until`),
                KEY `idx_jobs_space_created` (`space_id`, `created`),
                CONSTRAINT `fk_jobs_space` FOREIGN KEY (`space_id`)
                    REFERENCES `spaces` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT `fk_jobs_exec_user` FOREIGN KEY (`exec_user_id`)
                    REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE SET NULL,
                CONSTRAINT `fk_jobs_created_by` FOREIGN KEY (`created_by`)
                    REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        // Une seule ligne, lue et mise à jour par chaque tick : le battement
        // tient en une écriture et n'a pas besoin d'historique.
        $this->db->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `job_worker_state` (
                `id` tinyint unsigned NOT NULL DEFAULT 1,
                `last_run_at` datetime NULL DEFAULT NULL,
                `last_source` varchar(16) NOT NULL DEFAULT '',
                `last_ran` int unsigned NOT NULL DEFAULT 0,
                `last_budget_ms` int unsigned NOT NULL DEFAULT 0,
                `last_purge_at` datetime NULL DEFAULT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $this->db->execute('INSERT IGNORE INTO `job_worker_state` (`id`) VALUES (1)');

        // Compte de service système de la file. L'identifiant 2 est réservé :
        // l'installation fixe `users.AUTO_INCREMENT = 1000`, donc les comptes
        // créés par l'application ne peuvent pas le prendre. Il rejoint le
        // groupe Super Admin, qui porte le rôle ROOT déjà mappé sur le Space 1.
        $this->db->execute(<<<'SQL'
            INSERT IGNORE INTO `users`
                (`id`, `space_id`, `type`, `first_name`, `last_name`, `email`, `password`, `params`, `register_date`)
            VALUES (2, 1, 'service', 'Système (file de jobs)', '', 'system@jobs.invalid', '', '{}', current_timestamp())
            SQL);

        // Le rattachement n'a lieu que si la ligne réservée est bien le compte
        // de service : sur une base ancienne où l'ID 2 appartiendrait à une
        // personne, on ne promeut jamais un compte réel en Super Admin.
        $this->db->execute(<<<'SQL'
            INSERT IGNORE INTO `user_groups_map` (`user_id`, `group_id`)
            SELECT 2, 3 FROM `users` WHERE `id` = 2 AND `type` = 'service'
            SQL);

        return [
            'tables' => ['jobs', 'job_worker_state'],
            'indexes' => [
                'jobs.idx_jobs_dedup',
                'jobs.idx_jobs_selection',
                'jobs.idx_jobs_recovery',
                'jobs.idx_jobs_space_created',
            ],
            'rows' => ['users.2', 'user_groups_map.2'],
        ];
    }
}
