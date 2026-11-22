<?php

namespace YTassy\Tycms\CoreExts\Core\Helper\Menu;

use YTassy\Tycms\CoreExts\Core\Models\MenuItem;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;

class MenuLanguageHelper extends BaseHelper
{
    /** @var array<string, array<int, bool>> */
    private array $overridesByLocale = [];

    private function override(int $itemId, string $locale): ?bool
    {
        if (!isset($this->overridesByLocale[$locale])) {
            $this->overridesByLocale[$locale] = [];

            // Une seule lecture par langue et par requête évite les requêtes par entrée.
            foreach ($this->db->select(
                'SELECT menu_item_id, enabled FROM menu_item_languages WHERE locale = %',
                [$locale],
            ) as $row) {
                $this->overridesByLocale[$locale][(int) $row->menu_item_id] = (bool) $row->enabled;
            }
        }

        return $this->overridesByLocale[$locale][$itemId] ?? null;
    }

    public function isEnabled(MenuItem $item, string $locale): bool
    {
        return $this->override($item->id, $locale) ?? true;
    }

    /** @param array<int, MenuItem> $itemsById */
    public function isVisible(MenuItem $item, string $locale, array $itemsById): bool
    {
        $visited = [];
        $current = $item;

        // La visibilité d'un enfant dépend de celle de tous ses parents.
        while (true) {
            if (isset($visited[$current->id])) return false;
            $visited[$current->id] = true;

            if ($this->override($current->id, $locale) === false) {
                return false;
            }

            if ($current->parent_id === null || !isset($itemsById[$current->parent_id])) {
                return true;
            }

            $current = $itemsById[$current->parent_id];
        }
    }

    /** @return array<string, bool> */
    public function listForItem(int $itemId): array
    {
        $result = [];
        foreach ($this->db->select(
            'SELECT locale, enabled FROM menu_item_languages WHERE menu_item_id = %',
            [$itemId],
        ) as $row) {
            $result[$row->locale] = (bool) $row->enabled;
        }

        return $result;
    }
}
