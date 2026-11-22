<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\TyCatalog\Helper;

use Throwable;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\CoreExts\TyCatalog\Models\Catalog;
use YTassy\Tycms\CoreExts\TyCatalog\Models\CatalogExtension;
use YTassy\Tycms\CoreExts\TyCatalog\Models\CatalogLink;

/**
 * Accès SQL de l'administration TyCatalog.
 *
 * Le Space réel est toujours fourni par le WF après contrôle ACL ; aucun
 * identifiant de Space ne provient directement de la soumission.
 */
final class TyCatalogAdminHelper extends BaseHelper
{
    /** @return list<Catalog> */
    public function listForSpace(int $spaceId): array
    {
        return $this->db->selectOrm(
            Catalog::class,
            'SELECT * FROM ty_catalog_catalogs WHERE space_id = % ORDER BY name, id',
            [$spaceId],
        );
    }

    /** Tous les catalogues, pour l'administration inter-Espaces réservée à ROOT. */
    public function listAll(): array
    {
        return $this->db->selectOrm(
            Catalog::class,
            'SELECT c.* FROM ty_catalog_catalogs c
             INNER JOIN spaces s ON s.id = c.space_id
             ORDER BY s.title, c.name, c.id',
        );
    }

    public function get(int $catalogId): ?Catalog
    {
        return $this->db->selectOrmOneOrNull(
            Catalog::class,
            'SELECT * FROM ty_catalog_catalogs WHERE id = %',
            [$catalogId],
        );
    }

    /** Mettre à jour la fiche publique d'une extension sans toucher aux versions. */
    public function saveExtensionFiche(
        int $catalogId,
        string $identifier,
        string $displayName,
        string $description,
        ?string $imageUrl,
    ): ?CatalogExtension {
        $this->db->execute(
            'UPDATE ty_catalog_extensions
             SET display_name = %, description = %, image_url = %, updated = CURRENT_TIMESTAMP
             WHERE catalog_id = % AND extension_identifier = %',
            [$displayName, $description, $imageUrl, $catalogId, $identifier],
        );

        return $this->db->selectOrmOneOrNull(
            CatalogExtension::class,
            'SELECT * FROM ty_catalog_extensions WHERE catalog_id = % AND extension_identifier = %',
            [$catalogId, $identifier],
        );
    }

    /** @return array<int, list<CatalogLink>> Références groupées par catalogue. */
    public function linksForSpace(int $spaceId): array
    {
        $links = $this->db->selectOrm(
            CatalogLink::class,
            'SELECT l.*
             FROM ty_catalog_catalog_links l
             INNER JOIN ty_catalog_catalogs c ON c.id = l.catalog_id
             WHERE c.space_id = %
             ORDER BY l.catalog_id, l.position, l.id',
            [$spaceId],
        );

        return $this->groupLinks($links);
    }

    /** @return array<int, list<CatalogLink>> Toutes les références, pour ROOT. */
    public function linksForAll(): array
    {
        $links = $this->db->selectOrm(
            CatalogLink::class,
            'SELECT l.* FROM ty_catalog_catalog_links l
             ORDER BY l.catalog_id, l.position, l.id',
        );

        return $this->groupLinks($links);
    }

    /**
     * @param list<CatalogLink> $links
     * @return array<int, list<CatalogLink>>
     */
    private function groupLinks(array $links): array
    {
        $grouped = [];
        foreach ($links as $link) {
            $grouped[$link->catalog_id][] = $link;
        }

        return $grouped;
    }

    public function slugExists(string $slug, ?int $exceptCatalogId = null): bool
    {
        $existing = $this->db->selectOneOrNull(
            'SELECT id FROM ty_catalog_catalogs WHERE slug = % LIMIT 1',
            [$slug],
        );

        return $existing !== null && (int) $existing->id !== $exceptCatalogId;
    }

    /**
     * Enregistre le catalogue et remplace ses références dans la même
     * transaction InnoDB : une erreur sur une référence ne laisse pas un
     * catalogue à moitié enregistré.
     *
     * `$sourceSpaceId` est le Space propriétaire attendu avant écriture ;
     * `$targetSpaceId` permet à ROOT de déplacer un catalogue vers un autre
     * Space. Pour une création ou une modification ordinaire, les deux sont
     * identiques.
     *
     * @param array{name:string,slug:string,description:string,enabled:bool,keep_versions:int,keep_archives:int} $values
     * @param list<array{name:string,target_url:string,description:string,position:int}> $links
     * @param array{key_id:?string,public_key:?string}|null $keys null conserve les clés existantes
     */
    public function save(
        int $sourceSpaceId,
        ?int $catalogId,
        array $values,
        array $links,
        ?int $userId,
        ?array $keys = null,
        ?int $targetSpaceId = null,
    ): int {
        $targetSpaceId ??= $sourceSpaceId;
        $this->db->execute('START TRANSACTION');

        try {
            if ($catalogId === null) {
                $this->db->execute(
                    'INSERT INTO ty_catalog_catalogs
                        (space_id, slug, name, description, enabled, keep_versions, keep_archives, key_id, public_key, created_by)
                     VALUES (%, %, %, %, %, %, %, %, %, %)',
                    [
                        $targetSpaceId, $values['slug'], $values['name'], $values['description'],
                        $values['enabled'], $values['keep_versions'], $values['keep_archives'],
                        $keys['key_id'] ?? null, $keys['public_key'] ?? null, $userId,
                    ],
                );
                $catalogId = $this->db->getLastInsertId();
            } elseif ($keys === null) {
                $this->db->execute(
                    'UPDATE ty_catalog_catalogs
                     SET space_id = %, name = %, slug = %, description = %, enabled = %,
                         keep_versions = %, keep_archives = %, updated = CURRENT_TIMESTAMP
                     WHERE id = % AND space_id = %',
                    [
                        $targetSpaceId, $values['name'], $values['slug'], $values['description'], $values['enabled'],
                        $values['keep_versions'], $values['keep_archives'], $catalogId, $sourceSpaceId,
                    ],
                );
            } else {
                $this->db->execute(
                    'UPDATE ty_catalog_catalogs
                     SET space_id = %, name = %, slug = %, description = %, enabled = %,
                         keep_versions = %, keep_archives = %,
                         key_id = %, public_key = %, updated = CURRENT_TIMESTAMP
                     WHERE id = % AND space_id = %',
                    [
                        $targetSpaceId, $values['name'], $values['slug'], $values['description'], $values['enabled'],
                        $values['keep_versions'], $values['keep_archives'],
                        $keys['key_id'], $keys['public_key'], $catalogId, $sourceSpaceId,
                    ],
                );
            }

            // Remplacer les références simplifie l'ordre et évite les doublons locaux.
            $this->db->execute('DELETE FROM ty_catalog_catalog_links WHERE catalog_id = %', [$catalogId]);
            foreach ($links as $link) {
                $this->db->execute(
                    'INSERT INTO ty_catalog_catalog_links
                        (catalog_id, name, target_url, description, position)
                     VALUES (%, %, %, %, %)',
                    [$catalogId, $link['name'], $link['target_url'], $link['description'], $link['position']],
                );
            }

            $this->db->execute('COMMIT');

            return $catalogId;
        } catch (Throwable $exception) {
            $this->db->execute('ROLLBACK');
            throw $exception;
        }
    }
}
