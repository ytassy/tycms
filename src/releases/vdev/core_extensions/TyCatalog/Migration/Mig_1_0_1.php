<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\TyCatalog\Migration;

use Override;
use YTassy\Tycms\Extensions\BaseMigrationVersion;

/**
 * Les références externes contiennent deux URL complètes. Élargir la colonne
 * évite de tronquer une URL signée ou temporaire.
 */
final class Mig_1_0_1 extends BaseMigrationVersion
{
    #[Override]
    public function run(): array
    {
        $this->db->execute('ALTER TABLE `ty_catalog_versions` MODIFY `storage_reference` text NOT NULL');

        return ['altered' => ['ty_catalog_versions.storage_reference' => 'text']];
    }
}
