<?php

namespace YTassy\Tycms\CoreExts\Core\Helper\Language;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;

final class LanguageOverrideAdminHelper extends BaseHelper
{
    /** Vérifie l'unicité globale d'une clé personnalisée avant sa création. */
    public function customKeyExists(string $key): bool
    {
        return $this->db->selectOneOrNull(
            'SELECT id FROM language_custom_keys WHERE lang_key = %',
            [$key],
        ) !== null;
    }

    /** @return array<int,list<string>> Langues actives regroupées par Space. */
    public function enabledLocalesBySpace(): array
    {
        $locales = [];
        $rows = $this->db->select(
            'SELECT space_id, locale FROM space_languages WHERE enabled = 1 ORDER BY sort_order, locale',
        );

        foreach ($rows as $row) {
            $locales[(int) $row->space_id][] = $row->locale;
        }

        return $locales;
    }

    public function createCustomKey(int $spaceId, string $key, string $sourceValue, ?int $userId): void
    {
        $this->db->execute(
            'INSERT INTO language_custom_keys (space_id, lang_key, source_value, created_by) VALUES (%, %, %, %)',
            [$spaceId, $key, $sourceValue, $userId],
        );
    }

    public function save(int $spaceId, string $locale, string $key, string $value, ?int $userId): void
    {
        // Une seule ligne existe par combinaison Space, langue et clé.
        $this->db->execute(<<<SQL
            INSERT INTO language_overrides (space_id, locale, lang_key, value, updated_by)
            VALUES (%, %, %, %, %)
            ON DUPLICATE KEY UPDATE value = VALUES(value), updated_by = VALUES(updated_by),
                updated_at = CURRENT_TIMESTAMP
        SQL, [$spaceId, $locale, $key, $value, $userId]);
    }

    public function delete(int $spaceId, string $locale, string $key): void
    {
        $this->db->execute(
            'DELETE FROM language_overrides WHERE space_id = % AND locale = % AND lang_key = %',
            [$spaceId, $locale, $key],
        );
    }
}
