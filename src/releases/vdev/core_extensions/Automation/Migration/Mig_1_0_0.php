<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Migration;

use Override;
use YTassy\Tycms\Extensions\BaseMigrationVersion;

final class Mig_1_0_0 extends BaseMigrationVersion
{
    #[Override]
    public function run(): array
    {
        $this->db->executeMultiple(<<<SQL
            CREATE TABLE IF NOT EXISTS `automation_scenarios` (
                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                `space_id` bigint unsigned NOT NULL,
                `key` varchar(64) NOT NULL,
                `name` varchar(150) NOT NULL,
                `description` text NOT NULL DEFAULT '',
                `status` varchar(20) NOT NULL DEFAULT 'active',
                `principal_mode` varchar(20) NOT NULL DEFAULT 'trigger',
                `execution_user_id` bigint unsigned NULL DEFAULT NULL,
                `published_version_id` bigint unsigned NULL DEFAULT NULL,
                `draft_version_id` bigint unsigned NULL DEFAULT NULL,
                `created_by` bigint unsigned NULL DEFAULT NULL,
                `created` datetime NOT NULL DEFAULT current_timestamp(),
                `updated` datetime NULL DEFAULT NULL,
                `archived_at` datetime NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `idx_automation_scenarios_key` (`space_id`, `key`),
                KEY `idx_automation_scenarios_space_status` (`space_id`, `status`),
                KEY `idx_automation_scenarios_published_version` (`published_version_id`),
                KEY `idx_automation_scenarios_draft_version` (`draft_version_id`),
                KEY `idx_automation_scenarios_execution_user` (`execution_user_id`),
                CONSTRAINT `fk_automation_scenarios_space` FOREIGN KEY (`space_id`) REFERENCES `spaces` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
                CONSTRAINT `fk_automation_scenarios_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE SET NULL,
                CONSTRAINT `fk_automation_scenarios_execution_user` FOREIGN KEY (`execution_user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            CREATE TABLE IF NOT EXISTS `automation_triggers` (
                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                `scenario_id` bigint unsigned NOT NULL,
                `space_id` bigint unsigned NOT NULL,
                `trigger_id` varchar(100) NOT NULL,
                `entry_function` varchar(100) NOT NULL DEFAULT 'main',
                `config` mediumtext NOT NULL,
                `secret_hash` varchar(255) NULL DEFAULT NULL,
                `enabled` tinyint(1) NOT NULL DEFAULT 1,
                `created` datetime NOT NULL DEFAULT current_timestamp(),
                `updated` datetime NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `idx_automation_triggers_scenario` (`scenario_id`),
                KEY `idx_automation_triggers_lookup` (`trigger_id`, `enabled`),
                KEY `idx_automation_triggers_space` (`space_id`, `enabled`),
                CONSTRAINT `fk_automation_triggers_scenario` FOREIGN KEY (`scenario_id`) REFERENCES `automation_scenarios` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT `fk_automation_triggers_space` FOREIGN KEY (`space_id`) REFERENCES `spaces` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            CREATE TABLE IF NOT EXISTS `automation_versions` (
                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                `scenario_id` bigint unsigned NOT NULL,
                `version_number` int unsigned NOT NULL,
                `status` varchar(20) NOT NULL DEFAULT 'draft',
                `compile_status` varchar(20) NOT NULL DEFAULT 'ok',
                `source` mediumtext NOT NULL,
                `source_hash` char(64) NOT NULL,
                `ast` mediumtext NULL DEFAULT NULL,
                `schema_version` int unsigned NOT NULL DEFAULT 1,
                `grammar_version` int unsigned NOT NULL DEFAULT 1,
                `diagnostics` mediumtext NULL DEFAULT NULL,
                `created_by` bigint unsigned NULL DEFAULT NULL,
                `created` datetime NOT NULL DEFAULT current_timestamp(),
                `published_by` bigint unsigned NULL DEFAULT NULL,
                `published_at` datetime NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `idx_automation_versions_number` (`scenario_id`, `version_number`),
                KEY `idx_automation_versions_scenario_created` (`scenario_id`, `created`),
                CONSTRAINT `fk_automation_versions_scenario` FOREIGN KEY (`scenario_id`) REFERENCES `automation_scenarios` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
                CONSTRAINT `fk_automation_versions_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE SET NULL,
                CONSTRAINT `fk_automation_versions_published_by` FOREIGN KEY (`published_by`) REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            CREATE TABLE IF NOT EXISTS `automation_events` (
                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                `event_id` char(36) NOT NULL,
                `space_id` bigint unsigned NOT NULL,
                `trigger_id` varchar(100) NOT NULL,
                `payload` mediumtext NOT NULL,
                `principal_type` varchar(20) NOT NULL,
                `principal_id` bigint unsigned NULL DEFAULT NULL,
                `correlation_id` char(36) NULL DEFAULT NULL,
                `depth` smallint unsigned NOT NULL DEFAULT 0,
                `causation_id` char(36) NULL DEFAULT NULL,
                `occurred_at` datetime NOT NULL DEFAULT current_timestamp(),
                `status` varchar(20) NOT NULL DEFAULT 'pending',
                `attempts` smallint unsigned NOT NULL DEFAULT 0,
                `last_error` varchar(1000) NULL DEFAULT NULL,
                `processed_at` datetime NULL DEFAULT NULL,
                `created` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                UNIQUE KEY `idx_automation_events_event_id` (`event_id`),
                KEY `idx_automation_events_status` (`status`, `id`),
                KEY `idx_automation_events_lookup` (`space_id`, `trigger_id`, `occurred_at`),
                CONSTRAINT `fk_automation_events_space` FOREIGN KEY (`space_id`) REFERENCES `spaces` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            CREATE TABLE IF NOT EXISTS `automation_runs` (
                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                `space_id` bigint unsigned NOT NULL,
                `scenario_id` bigint unsigned NOT NULL,
                `version_id` bigint unsigned NOT NULL,
                `trigger_id` varchar(100) NULL DEFAULT NULL,
                `event_id` bigint unsigned NULL DEFAULT NULL,
                `dedup_key` varchar(100) NULL DEFAULT NULL,
                `principal_type` varchar(20) NOT NULL,
                `principal_id` bigint unsigned NULL DEFAULT NULL,
                `principal_label` varchar(150) NULL DEFAULT NULL,
                `input` mediumtext NOT NULL,
                `status` varchar(20) NOT NULL DEFAULT 'queued',
                `depth` smallint unsigned NOT NULL DEFAULT 0,
                `parent_run_id` bigint unsigned NULL DEFAULT NULL,
                `queued_at` datetime NOT NULL DEFAULT current_timestamp(),
                `started_at` datetime NULL DEFAULT NULL,
                `finished_at` datetime NULL DEFAULT NULL,
                `duration_ms` int unsigned NULL DEFAULT NULL,
                `steps_count` int unsigned NOT NULL DEFAULT 0,
                `loop_iterations` int unsigned NOT NULL DEFAULT 0,
                `action_calls` int unsigned NOT NULL DEFAULT 0,
                `output` mediumtext NULL DEFAULT NULL,
                `message` varchar(1000) NULL DEFAULT NULL,
                `error_code` varchar(50) NULL DEFAULT NULL,
                `error_message` varchar(1000) NULL DEFAULT NULL,
                `created` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                UNIQUE KEY `idx_automation_runs_dedup` (`scenario_id`, `dedup_key`),
                KEY `idx_automation_runs_space_created` (`space_id`, `created`),
                KEY `idx_automation_runs_scenario_created` (`scenario_id`, `created`),
                KEY `idx_automation_runs_status_queued` (`status`, `queued_at`),
                KEY `idx_automation_runs_parent` (`parent_run_id`),
                CONSTRAINT `fk_automation_runs_space` FOREIGN KEY (`space_id`) REFERENCES `spaces` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
                CONSTRAINT `fk_automation_runs_scenario` FOREIGN KEY (`scenario_id`) REFERENCES `automation_scenarios` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
                CONSTRAINT `fk_automation_runs_version` FOREIGN KEY (`version_id`) REFERENCES `automation_versions` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
                CONSTRAINT `fk_automation_runs_event` FOREIGN KEY (`event_id`) REFERENCES `automation_events` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            CREATE TABLE IF NOT EXISTS `automation_run_steps` (
                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                `run_id` bigint unsigned NOT NULL,
                `sequence` int unsigned NOT NULL,
                `kind` varchar(20) NOT NULL,
                `identifier` varchar(150) NOT NULL DEFAULT '',
                `status` varchar(20) NOT NULL DEFAULT 'ok',
                `started_at` datetime NULL DEFAULT NULL,
                `duration_ms` int unsigned NULL DEFAULT NULL,
                `input` mediumtext NULL DEFAULT NULL,
                `output` mediumtext NULL DEFAULT NULL,
                `error_code` varchar(50) NULL DEFAULT NULL,
                `error_message` varchar(1000) NULL DEFAULT NULL,
                `span_start_line` int unsigned NULL DEFAULT NULL,
                `span_start_column` int unsigned NULL DEFAULT NULL,
                `span_end_line` int unsigned NULL DEFAULT NULL,
                `span_end_column` int unsigned NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `idx_automation_run_steps_sequence` (`run_id`, `sequence`),
                CONSTRAINT `fk_automation_run_steps_run` FOREIGN KEY (`run_id`) REFERENCES `automation_runs` (`id`) ON UPDATE CASCADE ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);

        // Le rôle 2 est le rôle système ROOT des installations TyCMS.
        foreach (['VIEW', 'EDIT', 'PUBLISH', 'RUN', 'RUN_MANAGE', 'RUN_AS_AUTHOR'] as $name) {
            $this->db->execute(<<<SQL
                INSERT INTO acl_permissions (role_id, extension, name)
                SELECT 2, %, %
                WHERE NOT EXISTS (
                    SELECT 1 FROM acl_permissions
                    WHERE role_id = 2 AND extension = % AND name = %
                )
            SQL, ['plg_automation', $name, 'plg_automation', $name]);
        }

        return [];
    }
}
