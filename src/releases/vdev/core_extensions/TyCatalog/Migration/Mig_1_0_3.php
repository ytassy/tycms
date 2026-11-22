<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\TyCatalog\Migration;

use Override;
use YTassy\Tycms\Extensions\BaseMigrationVersion;

/**
 * Réglages de rétention par catalogue et marque de suppression d'archive.
 *
 * Les métadonnées des versions dont le ZIP est supprimé restent en base ;
 * `archive_removed_at` empêche toute distribution ou réactivation trompeuse.
 */
final class Mig_1_0_3 extends BaseMigrationVersion
{
    #[Override]
    public function run(): array
    {
        $this->db->execute('ALTER TABLE `ty_catalog_catalogs`
            ADD COLUMN `keep_versions` int unsigned NOT NULL DEFAULT 4 AFTER `public_key`,
            ADD COLUMN `keep_archives` int unsigned NOT NULL DEFAULT 4 AFTER `keep_versions`');

        $this->db->execute('ALTER TABLE `ty_catalog_versions`
            ADD COLUMN `archive_removed_at` datetime NULL DEFAULT NULL AFTER `withdrawn_at`');

        return [
            'columns' => [
                'ty_catalog_catalogs.keep_versions',
                'ty_catalog_catalogs.keep_archives',
                'ty_catalog_versions.archive_removed_at',
            ],
        ];
    }
}
