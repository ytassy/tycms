<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Migration;

use Override;
use YTassy\Tycms\Extensions\BaseMigrationVersion;

/**
 * Journal des e-mails sortants.
 *
 * Une ligne est créée en `processing` avant l'ouverture de la connexion SMTP,
 * puis passée en `done` ou `failed`. Les corps sont conservés pour le
 * diagnostic, après remplacement des secrets par [SECRET]. La rétention est
 * appliquée par `MailLogHelper` et par la purge horaire du CRON.
 */
final class Mig_1_1_14 extends BaseMigrationVersion
{
    #[Override]
    public function run(): array
    {
        $this->db->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `mail_logs` (
                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                `space_id` bigint unsigned NULL DEFAULT NULL,
                `status` varchar(16) NOT NULL DEFAULT 'processing',
                `kind` varchar(16) NOT NULL DEFAULT 'html',
                `template_key` varchar(150) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
                `locale` varchar(35) CHARACTER SET ascii COLLATE ascii_general_ci NULL DEFAULT NULL,
                `to_json` json NOT NULL,
                `cc_json` json NOT NULL,
                `bcc_json` json NOT NULL,
                `subject` varchar(255) NOT NULL,
                `body_html` mediumtext NOT NULL,
                `body_text` mediumtext NULL DEFAULT NULL,
                `attachments` json NULL DEFAULT NULL,
                `from_email` varchar(255) NULL DEFAULT NULL,
                `from_name` varchar(255) NULL DEFAULT NULL,
                `message_id` varchar(255) NULL DEFAULT NULL,
                `error_code` varchar(50) NULL DEFAULT NULL,
                `error_class` varchar(150) NULL DEFAULT NULL,
                `error_message` varchar(1000) NULL DEFAULT NULL,
                `job_id` bigint unsigned NULL DEFAULT NULL,
                `user_id` bigint unsigned NULL DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `finished_at` datetime NULL DEFAULT NULL,
                `duration_ms` int unsigned NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_mail_logs_status_created` (`status`, `created_at`),
                KEY `idx_mail_logs_space_created` (`space_id`, `created_at`),
                KEY `idx_mail_logs_template_created` (`template_key`, `created_at`),
                KEY `idx_mail_logs_created` (`created_at`),
                CONSTRAINT `fk_mail_logs_space` FOREIGN KEY (`space_id`)
                    REFERENCES `spaces` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT `fk_mail_logs_job` FOREIGN KEY (`job_id`)
                    REFERENCES `jobs` (`id`) ON UPDATE CASCADE ON DELETE SET NULL,
                CONSTRAINT `fk_mail_logs_user` FOREIGN KEY (`user_id`)
                    REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        return [
            'tables' => ['mail_logs'],
            'indexes' => [
                'mail_logs.idx_mail_logs_status_created',
                'mail_logs.idx_mail_logs_space_created',
                'mail_logs.idx_mail_logs_template_created',
                'mail_logs.idx_mail_logs_created',
            ],
        ];
    }
}
