<?php

namespace YTassy\Tycms\CoreExts\Core\Helper\Space;

use YTassy\Tycms\Exceptions\TechException;
use YTassy\Tycms\CoreExts\Core\Models\SpaceLanguage;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;

class SpaceLanguageHelper extends BaseHelper
{
    /** @return list<SpaceLanguage> */
    public function listForSpace(int $spaceId): array
    {
        return $this->db->selectOrm(SpaceLanguage::class,
            'SELECT * FROM space_languages WHERE space_id = % ORDER BY sort_order, locale', [$spaceId]);
    }

    /** @return list<SpaceLanguage> */
    public function listActiveForSpace(int $spaceId): array
    {
        return $this->db->selectOrm(SpaceLanguage::class,
            'SELECT * FROM space_languages WHERE space_id = % AND enabled = 1 ORDER BY sort_order, locale', [$spaceId]);
    }

    public function getDefaultForSpace(int $spaceId): SpaceLanguage
    {
        $language = $this->db->selectOrmOneOrNull(SpaceLanguage::class,
            'SELECT * FROM space_languages WHERE space_id = % AND is_default = 1', [$spaceId]);

        if ($language === null || !$language->enabled) {
            throw new TechException("Le Space {$spaceId} n'a pas de langue par défaut active.");
        }

        return $language;
    }

    public function getDefaultLocale(int $spaceId): string
    {
        return $this->getDefaultForSpace($spaceId)->locale;
    }

    public function getHomeMenuItemId(int $spaceId, string $locale): ?int
    {
        // L'accueil propre à la langue gagne. Sans surcharge, on conserve
        // l'accueil général historique du Space.
        $row = $this->db->selectOneOrNull(<<<SQL
            SELECT COALESCE(space_languages.menu_item_home_id, spaces.menu_item_home_id) AS home_id
            FROM space_languages
            INNER JOIN spaces ON spaces.id = space_languages.space_id
            WHERE space_languages.space_id = % AND space_languages.locale = %
                AND space_languages.enabled = 1
            SQL, [$spaceId, $locale]);

        return $row?->home_id === null ? null : (int) $row->home_id;
    }

    public function getUserSpaceMenuItemId(int $spaceId, string $locale): ?int
    {
        // Une entrée propre à la langue prime sur l'espace utilisateur général.
        $row = $this->db->selectOneOrNull(<<<SQL
            SELECT COALESCE(space_languages.menu_item_user_space_id, spaces.menu_item_user_space_id) AS item_id
            FROM space_languages
            INNER JOIN spaces ON spaces.id = space_languages.space_id
            WHERE space_languages.space_id = % AND space_languages.locale = %
                AND space_languages.enabled = 1
            SQL, [$spaceId, $locale]);

        return $row?->item_id === null ? null : (int) $row->item_id;
    }
}
