<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Migration;

use Override;
use YTassy\Tycms\Extensions\BaseMigrationVersion;

/**
 * Audit des exécutions de code ad hoc (serveur MCP).
 *
 * Une exécution vient d'un jeton d'API et d'un compte de service. `token_id`
 * reste volontairement sans clé étrangère : un jeton disparaît avec son compte,
 * mais l'audit doit survivre. La source du code n'est pas conservée, seul son
 * hachage SHA-256 l'est.
 */
final class Mig_1_0_2 extends BaseMigrationVersion
{
    #[Override]
    public function run(): array
    {
        $this->db->execute(<<<SQL
            CREATE TABLE IF NOT EXISTS `mcp_executions` (
                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                `token_id` bigint unsigned NULL DEFAULT NULL,
                `user_id` bigint unsigned NOT NULL,
                `space_id` bigint unsigned NOT NULL,
                `source_hash` char(64) NOT NULL,
                `input` mediumtext NOT NULL,
                `output` mediumtext NULL DEFAULT NULL,
                `status` varchar(20) NOT NULL DEFAULT 'running',
                `error_code` varchar(50) NULL DEFAULT NULL,
                `error_message` varchar(1000) NULL DEFAULT NULL,
                `duration_ms` int unsigned NULL DEFAULT NULL,
                `steps_count` int unsigned NOT NULL DEFAULT 0,
                `action_calls` int unsigned NOT NULL DEFAULT 0,
                `created` datetime NOT NULL DEFAULT current_timestamp(),
                `finished_at` datetime NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_mcp_executions_user` (`user_id`, `id`),
                KEY `idx_mcp_executions_space_created` (`space_id`, `created`),
                KEY `idx_mcp_executions_token` (`token_id`),
                CONSTRAINT `fk_mcp_executions_user` FOREIGN KEY (`user_id`)
                    REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT `fk_mcp_executions_space` FOREIGN KEY (`space_id`)
                    REFERENCES `spaces` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        return [
            'tables' => ['mcp_executions'],
        ];
    }
}