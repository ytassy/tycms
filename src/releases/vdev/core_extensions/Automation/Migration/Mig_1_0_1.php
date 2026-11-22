<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Migration;

use Override;
use YTassy\Tycms\Extensions\BaseMigrationVersion;

/** Cible un scénario précis pour les déclencheurs émis par un producteur. */
final class Mig_1_0_1 extends BaseMigrationVersion
{
    #[Override]
    public function run(): array
    {
        $exists = $this->db->selectOneOrNull(
            "SELECT 1 AS present FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'automation_events'
               AND column_name = 'target_scenario_key'
             LIMIT 1",
        );

        if ($exists === null) {
            $this->db->execute(
                'ALTER TABLE `automation_events`
                 ADD COLUMN `target_scenario_key` varchar(64) NULL DEFAULT NULL AFTER `trigger_id`',
            );
        }

        // ROOT reçoit la nouvelle permission de configuration sensible. Les
        // autres rôles ne l'obtiennent que par une action explicite de ROOT.
        $this->db->execute(
            'INSERT INTO acl_permissions (role_id, extension, name)
             SELECT 2, %, %
             WHERE NOT EXISTS (
                 SELECT 1 FROM acl_permissions
                 WHERE role_id = 2 AND extension = % AND name = %
             )',
            ['plg_automation', 'ADMIN', 'plg_automation', 'ADMIN'],
        );

        return [];
    }
}
