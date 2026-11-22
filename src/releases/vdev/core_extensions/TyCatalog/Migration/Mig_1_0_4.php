<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\TyCatalog\Migration;

use Override;
use YTassy\Tycms\Extensions\BaseMigrationVersion;

/** Image de présentation optionnelle pour la fiche publique d'une extension. */
final class Mig_1_0_4 extends BaseMigrationVersion
{
    #[Override]
    public function run(): array
    {
        $this->db->execute(
            'ALTER TABLE `ty_catalog_extensions`
             ADD COLUMN `image_url` varchar(2000) NULL DEFAULT NULL AFTER `description`',
        );

        return ['columns' => ['ty_catalog_extensions.image_url']];
    }
}
