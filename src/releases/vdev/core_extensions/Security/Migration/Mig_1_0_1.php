<?php

namespace YTassy\Tycms\CoreExts\Security\Migration;

use YTassy\Tycms\Extensions\BaseMigrationVersion;

/** Matérialiser les valeurs historiques, sans désactiver les protections existantes. */
final class Mig_1_0_1 extends BaseMigrationVersion
{
    public function run(): array
    {
        // JSON_INSERT conserve les choix déjà présents ; enabled n’a jamais piloté les en-têtes.
        $this->db->execute(<<<SQL
            UPDATE config
            SET params = JSON_REMOVE(JSON_INSERT(params,
                '$.iframe_mode', 'all_https', '$.iframe_origins', '', '$.image_origins', '',
                '$.media_origins', '', '$.style_origins', '', '$.font_origins', ''), '$.enabled')
            WHERE extension = 'plg_security'
            SQL);
        return [];
    }
}
