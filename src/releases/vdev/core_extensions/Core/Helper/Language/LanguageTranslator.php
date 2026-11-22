<?php

namespace YTassy\Tycms\CoreExts\Core\Helper\Language;

use YTassy\Tycms\DB\FormatIn;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;

/** Projection explicite des clés ; les données ORM restent brutes. */
final class LanguageTranslator extends BaseHelper
{
    public const RIA_KEY_PREFIX = 'LG_RIA_';

    /** @var array<int,array<string,array<string,mixed>>> */
    private array $entriesCache = [];
    /** @var array<string,array<int,array<string,array{value:string,updatedAt:string,updatedBy:?int}>>> */
    private array $overridesCache = [];

    /** @return array<string,array<string,mixed>> */
    public function entriesForSpace(int $spaceId): array
    {
        if (isset($this->entriesCache[$spaceId])) {
            return $this->entriesCache[$spaceId];
        }

        // Les catalogues sont communs ; les clés personnalisées suivent la branche du Space.
        $entries = $this->coreLanguageCatalogRegistry->all();
        $ancestors = $this->coreSpaceHelper->getSpaceIdsWithParents($spaceId);
        if ($ancestors === []) {
            return [];
        }

        $rows = $this->db->select(
            'SELECT space_id, lang_key, source_value FROM language_custom_keys WHERE space_id IN (%)',
            [FormatIn::get($ancestors)],
        );

        // Grouper les lignes avant de parcourir les ancêtres dans leur ordre de priorité.
        $bySpace = [];
        foreach ($rows as $row) {
            $bySpace[(int) $row->space_id][$row->lang_key] = $row;
        }

        foreach ($ancestors as $ancestorId) {
            foreach ($bySpace[$ancestorId] ?? [] as $key => $row) {
                if (isset($entries[$key])) {
                    continue;
                }

                $entries[$key] = [
                    'owner' => 'space',
                    'source' => $row->source_value,
                    'translations' => ['fr' => $row->source_value],
                    'declared_space_id' => $ancestorId,
                ];
            }
        }

        ksort($entries);
        $this->entriesCache[$spaceId] = $entries;

        return $entries;
    }

    /** @return array<int,array<string,array{value:string,updatedAt:string,updatedBy:?int}>> */
    private function overridesForSpace(int $spaceId, string $locale): array
    {
        $cacheKey = $spaceId . ':' . $locale;
        if (isset($this->overridesCache[$cacheKey])) {
            return $this->overridesCache[$cacheKey];
        }

        $ancestors = $this->coreSpaceHelper->getSpaceIdsWithParents($spaceId);
        if ($ancestors === []) {
            return [];
        }

        // Charger en une requête les surcharges locales et héritables.
        $rows = $this->db->select(
            'SELECT space_id, lang_key, value, updated_at, updated_by FROM language_overrides WHERE locale = % AND space_id IN (%)',
            [$locale, FormatIn::get($ancestors)],
        );

        // Préparer les valeurs par Space et par clé pour résoudre l'héritage.
        $values = [];
        foreach ($rows as $row) {
            $values[(int) $row->space_id][$row->lang_key] = [
                'value' => $row->value,
                'updatedAt' => $row->updated_at,
                'updatedBy' => $row->updated_by === null ? null : (int) $row->updated_by,
            ];
        }

        $this->overridesCache[$cacheKey] = $values;

        return $values;
    }

    /** @return array<string,mixed>|null */
    public function describe(string $key, string $locale, int $spaceId): ?array
    {
        $entry = $this->entriesForSpace($spaceId)[$key] ?? null;
        if ($entry === null) {
            return null;
        }

        // La surcharge du Space courant prime ; sinon chercher le parent le plus proche.
        $values = $this->overridesForSpace($spaceId, $locale);
        $ancestors = $this->coreSpaceHelper->getSpaceIdsWithParents($spaceId);
        $localRow = $values[$spaceId][$key] ?? null;
        $inheritedRow = null;
        $inheritedSpaceId = null;
        foreach (array_slice($ancestors, 1) as $ancestorId) {
            if (array_key_exists($key, $values[$ancestorId] ?? [])) {
                $inheritedRow = $values[$ancestorId][$key];
                $inheritedSpaceId = $ancestorId;
                break;
            }
        }

        // Une chaîne vide reste une vraie surcharge : seul null signifie « absente ».
        $local = $localRow['value'] ?? null;
        $inherited = $inheritedRow['value'] ?? null;
        $catalogue = $entry['translations'][$locale] ?? null;

        // Identifier la provenance de la valeur que recevra l'éditeur.
        if ($local !== null) {
            $origin = 'space';
        } elseif ($inherited !== null) {
            $origin = 'parent';
        } elseif ($catalogue !== null) {
            $origin = 'catalogue';
        } else {
            $origin = 'source';
        }

        $effective = $local ?? $inherited ?? $catalogue ?? $entry['source'];

        return [
            'key' => $key,
            'owner' => $entry['owner'],
            'declaredSpaceId' => $entry['declared_space_id'] ?? null,
            'source' => $entry['source'],
            'recommended' => $entry['recommended'] ?? false,
            'description' => $entry['description'] ?? '',
            'catalogue' => $catalogue,
            'inherited' => $inherited,
            'inheritedSpaceId' => $inheritedSpaceId,
            'inheritedUpdatedAt' => $inheritedRow['updatedAt'] ?? null,
            'inheritedUpdatedBy' => $inheritedRow['updatedBy'] ?? null,
            'local' => $local,
            'localUpdatedAt' => $localRow['updatedAt'] ?? null,
            'localUpdatedBy' => $localRow['updatedBy'] ?? null,
            'effective' => $effective,
            'origin' => $origin,
        ];
    }

    public function resolve(string $value, string $locale, int $spaceId): string
    {
        if (!LanguageCatalogRegistry::isKey($value)) {
            return $value;
        }

        $description = $this->describe($value, $locale, $spaceId);
        if ($description !== null) {
            return $description['effective'];
        }

        return $this->app->getConf()->dev ? '[' . $value . ']' : $value;
    }

    /** @return array<string,string> */
    public function dictionaryForSpace(int $spaceId, string $locale, ?string $prefix = null): array
    {
        $entries = $this->entriesForSpace($spaceId);
        $overrides = $this->overridesForSpace($spaceId, $locale);
        $ancestors = $this->coreSpaceHelper->getSpaceIdsWithParents($spaceId);
        $dictionary = [];

        // Résoudre les clés en lot évite une recherche SQL pour chaque valeur.
        foreach ($entries as $key => $entry) {
            // Le bootstrap Vue ne reçoit que les références destinées à son interface.
            if ($prefix !== null && !str_starts_with($key, $prefix)) {
                continue;
            }

            $effective = null;

            // Le premier Space trouvé est le plus proche du Space courant.
            foreach ($ancestors as $ancestorId) {
                if (array_key_exists($key, $overrides[$ancestorId] ?? [])) {
                    $effective = $overrides[$ancestorId][$key]['value'];
                    break;
                }
            }

            // Une chaîne vide est une vraie surcharge et doit rester vide.
            $dictionary[$key] = $effective
                ?? $entry['translations'][$locale]
                ?? $entry['source'];
        }

        return $dictionary;
    }

    /** @return array<string,string> */
    public function riaDictionaryForSpace(int $spaceId, string $locale): array
    {
        return $this->dictionaryForSpace($spaceId, $locale, self::RIA_KEY_PREFIX);
    }

    public function invalidate(): void
    {
        $this->entriesCache = [];
        $this->overridesCache = [];
    }
}
