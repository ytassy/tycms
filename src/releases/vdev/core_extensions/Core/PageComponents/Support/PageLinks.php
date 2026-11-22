<?php

namespace YTassy\Tycms\CoreExts\Core\PageComponents\Support;

use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\CoreExts\Core\Helper\Menu\MenuHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Menu\MenuRouteHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Space\SpaceLanguageHelper;

/** Politique de liens locale aux composants, avec dépendances explicites. */
final class PageLinks
{
    public function __construct(
        private readonly MenuHelper $menus,
        private readonly MenuRouteHelper $routes,
        private readonly SpaceLanguageHelper $languages,
    ) {}

    /** @var array<int,list<string>> */
    private array $localesBySpace = [];

    private function locales(int $spaceId): array
    {
        // Une seule lecture par Space, même pour une page contenant de nombreux liens.
        return $this->localesBySpace[$spaceId] ??= array_column(
            $this->languages->listActiveForSpace($spaceId), 'locale',
        );
    }

    /** Une seule politique d’URL pour Image, Bouton et les destinations résolues. */
    public static function safeUrl(string $url): ?string
    {
        if ($url === '' || strlen($url) > 2048 || str_contains($url, '\\')
            || preg_match('/[\x00-\x20\x7f]/', $url) || str_starts_with($url, '//')) return null;
        // Rejeter aussi les séparateurs et caractères de contrôle encodés.
        $decoded = rawurldecode($url);
        if (str_contains($decoded, '\\') || preg_match('/[\x00-\x1f\x7f]/', $decoded)
            || str_starts_with($decoded, '//')) return null;
        if (str_starts_with($url, '/')) return str_contains($decoded, '..') ? null : $url;
        return filter_var($url, FILTER_VALIDATE_URL) && parse_url($url, PHP_URL_SCHEME) === 'https' ? $url : null;
    }

    public static function validate(object $props, bool $image = false): void
    {
        if (property_exists($props, 'link_type') && !in_array($props->link_type, ['url', 'menu'], true)) {
            ExceptionResponse::throwWarning('Type de lien invalide.');
        }
        if (property_exists($props, 'link_new_tab') && !is_bool($props->link_new_tab)) {
            ExceptionResponse::throwWarning('L’option de nouvel onglet doit être un booléen.');
        }
        if (property_exists($props, 'href') && (!is_string($props->href)
            || ($props->href !== '' && self::safeUrl($props->href) === null))) {
            ExceptionResponse::throwWarning('URL de lien invalide : utilisez un chemin local ou une URL HTTPS.');
        }
        if (property_exists($props, 'menu_item_id') && (!is_int($props->menu_item_id) || $props->menu_item_id < 0)) {
            ExceptionResponse::throwWarning('Destination de menu invalide.');
        }
        if ($image && property_exists($props, 'click_action') && !in_array($props->click_action, ['none', 'enlarge', 'link'], true)) {
            ExceptionResponse::throwWarning('Action au clic de l’image invalide.');
        }
    }

    public static function targetAttributes(object $props): string
    {
        return ($props->link_new_tab ?? false) === true ? ' target="_blank" rel="noopener noreferrer"' : '';
    }

    public function resolve(object $props, int $spaceId, string $locale): ?string
    {
        if (($props->link_type ?? 'url') !== 'menu') return self::safeUrl($props->href ?? '');
        $id = $props->menu_item_id ?? 0;
        if (!is_int($id) || $id < 1) return null;
        $url = $this->routes->getUrlForContext($id, $spaceId, $locale);
        return $url === null ? null : self::safeUrl($url);
    }

    /** Métadonnées éditoriales seulement : aucune URL ni paramètre de contrôleur exposé. */
    public function menuOptions(int $spaceId): array
    {
        $locales = $this->locales($spaceId);
        $options = [];
        foreach ($this->menus->getMenusAnItemsOfSpace($spaceId) as $menu) {
            $byId = [];
            foreach ($menu->items as $item) $byId[$item->id] = $item;
            foreach ($menu->items as $item) {
                $available = [];
                foreach ($locales as $locale) {
                    $url = $this->routes->getUrlForContext($item->id, $spaceId, $locale, false);
                    if ($url !== null && self::safeUrl($url) !== null) $available[] = $locale;
                }
                if ($available === []) continue;
                $titles = [$item->title];
                $parentId = $item->parent_id;
                while ($parentId !== null && isset($byId[$parentId])) {
                    $parent = $byId[$parentId];
                    array_unshift($titles, $parent->title);
                    $parentId = $parent->parent_id;
                }
                $options[] = ['id' => $item->id, 'menu_id' => $menu->id, 'menu' => $menu->name,
                    'label' => implode(' / ', $titles), 'locales' => $available];
            }
        }
        usort($options, static fn ($a, $b) => strnatcasecmp($a['menu'] . '/' . $a['label'], $b['menu'] . '/' . $b['label']));
        return $options;
    }

    /** Vérifier la référence dans le Space cible, notamment après un collage. */
    public function validateReference(object $props, int $spaceId): void
    {
        $id = $props->menu_item_id ?? 0;
        if ($id === 0) return; // Un composant à compléter reste enregistrable.
        foreach ($this->locales($spaceId) as $locale) {
            $url = $this->routes->getUrlForContext($id, $spaceId, $locale, false);
            if ($url !== null && self::safeUrl($url) !== null) return;
        }
        ExceptionResponse::throwWarning('La destination du lien doit être une entrée publiée et navigable du Space de la page.');
    }
}
