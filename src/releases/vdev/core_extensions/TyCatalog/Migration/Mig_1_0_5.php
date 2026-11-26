<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\TyCatalog\Migration;

use Override;
use YTassy\Tycms\Extensions\BaseMigrationVersion;

/**
 * Canal de diffusion des versions d'extension.
 *
 * La bêta permet d'essayer une version sur une instance de confiance avant de
 * la servir à tous. Elle réutilise les états existants : le canal ne s'applique
 * qu'à une version publiée, et le retour release → bêta est refusé par le code.
 *
 * La valeur par défaut est `release` : les versions déjà publiées restent
 * servies comme avant, sans intervention.
 */
final class Mig_1_0_5 extends BaseMigrationVersion
{
    #[Override]
    public function run(): array
    {
        $this->db->execute(
            'ALTER TABLE `ty_catalog_versions`
             ADD COLUMN `channel` varchar(16) NOT NULL DEFAULT \'release\' AFTER `status`',
        );

        // Retrouver les versions par canal sans parcourir la table entière.
        $this->db->execute(
            'ALTER TABLE `ty_catalog_versions`
             ADD KEY `idx_ty_catalog_versions_channel` (`extension_id`, `channel`)',
        );

        // Même forme de retour que les migrations précédentes : les colonnes ajoutées.
        return ['columns' => ['ty_catalog_versions.channel']];
    }
}
