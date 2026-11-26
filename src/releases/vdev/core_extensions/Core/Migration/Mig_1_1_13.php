<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Migration;

use Override;
use YTassy\Tycms\Extensions\BaseMigrationVersion;

/**
 * Payload de job en `mediumtext`.
 *
 * Le corps purifié d'un e-mail libre peut dépasser la limite d'un `text`
 * (64 Ko). La colonne suit donc la limite de contenu des modèles d'e-mail.
 */
final class Mig_1_1_13 extends BaseMigrationVersion
{
    #[Override]
    public function run(): array
    {
        $this->db->execute('ALTER TABLE `jobs` MODIFY COLUMN `payload` mediumtext NOT NULL');

        return ['columns' => ['jobs.payload']];
    }
}
