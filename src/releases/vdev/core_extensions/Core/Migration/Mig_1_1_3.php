<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Migration;

use YTassy\Tycms\DB\FormatIn;
use YTassy\Tycms\Exceptions\TechException;
use YTassy\Tycms\Extensions\BaseMigrationVersion;

final class Mig_1_1_3 extends BaseMigrationVersion
{
    public function run(): array
    {
        // Nettyoage Table Migration
        $this->db->execute('DELETE FROM migrations WHERE extension = % AND version <> % AND id < 12 AND id > 1', ['plg_core', '1.0.0']);

        // Aucune page ni configuration éditoriale n'est créée automatiquement.
        $this->db->execute('ALTER TABLE spaces
            ADD COLUMN menu_item_login_id BIGINT UNSIGNED NULL DEFAULT NULL,
            ADD COLUMN menu_item_register_id BIGINT UNSIGNED NULL DEFAULT NULL,
            ADD CONSTRAINT fk_spaces_login_item FOREIGN KEY (menu_item_login_id) REFERENCES menu_items (id),
            ADD CONSTRAINT fk_spaces_register_item FOREIGN KEY (menu_item_register_id) REFERENCES menu_items (id)');

        $this->deleteLegacyMenuItems();

        $this->db->execute('ALTER TABLE `pages`
            ADD INDEX `idx_pages_sudn` (`space_id`, `user_defined`, `name`)');

        $this->db->execute('ALTER TABLE `pages` DROP INDEX idx_pages_space');



        // La collation de la colonne impose aussi l'unicité sans distinction de casse.
        $this->db->execute('ALTER TABLE spaces ADD UNIQUE KEY uq_spaces_medias_dir (medias_dir)');

        // Matérialiser le comportement historique sans écraser un choix déjà enregistré.
        // JSON_EXTRACT conserve un vrai booléen JSON, plutôt qu’un entier SQL ou une chaîne.
        $this->db->execute(<<<SQL
            UPDATE spaces
            SET params = JSON_INSERT(params, '$.registration_auto_member_role', JSON_EXTRACT('true', '$'))
            SQL);


        // Conserver la capacité historique d'attribuer des groupes sans créer de doublon.
        $this->db->execute(<<<SQL
            INSERT INTO acl_permissions (role_id, extension, name)
            SELECT DISTINCT source.role_id, 'plg_core', 'USER_GROUPS_ASSIGN'
            FROM acl_permissions source
            WHERE source.extension = 'plg_core' AND source.name = 'USER_ROLES_CRUD'
                AND NOT EXISTS (
                    SELECT 1 FROM acl_permissions existing
                    WHERE existing.role_id = source.role_id AND existing.extension = 'plg_core'
                        AND existing.name = 'USER_GROUPS_ASSIGN'
                )
            SQL);
            
        return [];
    }

    private function deleteLegacyMenuItems(): void
    {
        // Inclure les descendants supprimés par la cascade pour libérer aussi leurs références.
        $items = $this->db->select(<<<SQL
            WITH RECURSIVE obsolete_items (id) AS (
                SELECT id FROM menu_items WHERE extension = % AND type IN (%)
                UNION DISTINCT
                SELECT child.id FROM menu_items child
                INNER JOIN obsolete_items parent ON child.parent_id = parent.id
            )
            SELECT id FROM obsolete_items
            SQL, ['plg_core', FormatIn::get(['login', 'register'])]);
        if ($items === []) return;
        $ids = FormatIn::get(array_column($items, 'id'));

        // Les références de navigation sont restrictives : les vider avant la suppression des branches.
        foreach (['spaces', 'space_languages'] as $table) {
            foreach (['menu_item_home_id', 'menu_item_user_space_id'] as $field) {
                $this->db->execute("UPDATE $table SET $field = NULL WHERE $field IN (%)", [$ids]);
            }
        }

        $this->db->execute(
            'DELETE FROM menu_items WHERE extension = % AND type IN (%)',
            ['plg_core', FormatIn::get(['login', 'register'])],
        );
    }
}
