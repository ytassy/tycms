<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Helper\Page;

use YTassy\Tycms\CoreExts\Core\Models\Page;
use YTassy\Tycms\CoreExts\Core\Models\PageHistory;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;

/**
 * Conservation des états remplacés d'une page.
 *
 * La version courante n'est jamais copiée dans l'historique : à chaque
 * sauvegarde, l'état qui va être remplacé est enregistré. Une page nouvellement
 * créée n'a donc pas encore d'historique, et une page existante devient
 * récupérable dès sa première modification, même si elle a été créée avant
 * l'installation de cette fonctionnalité.
 */
class PageHistoryHelper extends BaseHelper
{
    /** Nombre de versions conservées pour le jour courant. */
    public const CURRENT_DAY_VERSIONS = 20;

    /** Nombre de jours glissants conservés, jour courant inclus. */
    public const RETENTION_DAYS = 30;

    /**
     * Enregistrer l'état courant d'une page avant son remplacement.
     *
     * L'appel a lieu dans la transaction de la sauvegarde : un échec de la mise
     * à jour annule aussi cette ligne d'historique.
     */
    public function record(Page $page, ?int $userId): void
    {
        // `data` est déjà normalisé par le WF ; l'encodage reprend celui de
        // `pages.data` pour qu'une version puisse être relue sans conversion.
        $data = $page->data === null ? null : PageSize::encodeDocument($page->data);

        $this->db->execute(
            'INSERT INTO page_history (page_id, user_id, name, description, type, raw_html, raw_scss, data, access_edit_role_id) '
                . 'VALUES (%, %, %, %, %, %, %, %, %)',
            [
                $page->id,
                $userId,
                $page->name,
                $page->description,
                $page->type,
                $page->raw_html,
                $page->raw_scss,
                $data,
                $page->access_edit_role_id,
            ],
        );

        $this->prune($page->id);
    }

    /**
     * Lister les versions d'une page, de la plus récente à la plus ancienne.
     *
     * La jointure évite une requête par version pour afficher l'auteur. Le
     * contenu n'est pas chargé ici : la liste peut contenir des documents
     * volumineux et seule la lecture d'une version les transporte.
     *
     * @return list<object>
     */
    public function listForPage(int $pageId): array
    {
        return $this->db->select(<<<'SQL'
            SELECT page_history.id, page_history.page_id, page_history.user_id,
                page_history.name, page_history.description, page_history.type,
                COALESCE(LENGTH(page_history.data), 0) AS data_size,
                page_history.created, users.first_name, users.last_name, users.type AS user_type
            FROM page_history
            LEFT JOIN users ON users.id = page_history.user_id
            WHERE page_history.page_id = %
            ORDER BY page_history.created DESC, page_history.id DESC
            SQL, [$pageId]);
    }

    /** Lire une version précise, en vérifiant qu'elle appartient bien à la page. */
    public function getVersion(int $pageId, int $versionId): ?PageHistory
    {
        return $this->db->selectOrmOneOrNull(
            PageHistory::class,
            'SELECT * FROM page_history WHERE id = % AND page_id = %',
            [$versionId, $pageId],
        );
    }

    /**
     * Appliquer la rétention de la page.
     *
     * - au-delà de 30 jours glissants (aujourd'hui inclus), tout est supprimé ;
     * - pour chaque jour passé, seule la dernière version est conservée ;
     * - pour aujourd'hui, les 20 dernières versions sont conservées.
     */
    public function prune(int $pageId): void
    {
        // 1. Supprimer les journées sorties de la fenêtre de rétention.
        $this->db->execute(
            'DELETE FROM page_history WHERE page_id = % '
                . 'AND created < (CURRENT_DATE - INTERVAL ' . (self::RETENTION_DAYS - 1) . ' DAY)',
            [$pageId],
        );

        // 2. Ne garder que la dernière version de chaque jour passé. La table
        // dérivée est nécessaire pour supprimer et lire la même table.
        $this->db->execute(<<<'SQL'
            DELETE FROM page_history
            WHERE page_id = %
                AND DATE(created) < CURRENT_DATE
                AND id NOT IN (
                    SELECT keep_id FROM (
                        SELECT MAX(id) AS keep_id
                        FROM page_history
                        WHERE page_id = % AND DATE(created) < CURRENT_DATE
                        GROUP BY DATE(created)
                    ) AS kept
                )
            SQL, [$pageId, $pageId]);

        // 3. Borner le jour courant aux dernières versions. La constante est un
        // entier du code, jamais une entrée utilisateur : elle peut être
        // interpolée dans `LIMIT`.
        $limit = self::CURRENT_DAY_VERSIONS;
        $this->db->execute(<<<SQL
            DELETE FROM page_history
            WHERE page_id = %
                AND DATE(created) = CURRENT_DATE
                AND id NOT IN (
                    SELECT keep_id FROM (
                        SELECT id AS keep_id
                        FROM page_history
                        WHERE page_id = % AND DATE(created) = CURRENT_DATE
                        ORDER BY id DESC
                        LIMIT {$limit}
                    ) AS kept
                )
            SQL, [$pageId, $pageId]);
    }
}
