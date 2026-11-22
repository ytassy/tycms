<?php

namespace YTassy\Tycms\CoreExts\Core\Helper\Page\Renderer;

use YTassy\Tycms\DB\ORM\Annotation\FieldType;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageButtonStyles;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageDocument;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageLocalizedText;
use YTassy\Tycms\CoreExts\Core\Models\Page;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\Tools\HtmlTools;
use YTassy\Tycms\Lib\HtmlSanitizer\HtmlSanitizer;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageDataMode;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageRenderMode;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageRenderContext;

/** Le moteur ne connaît que le contrat PageComponent et les options communes. */
class PageRenderer extends BaseHelper
{
    private ?string $renderLocale = null;
    private PageRenderMode $renderMode = PageRenderMode::PUBLIC;
    private array $components = [];

    public function render(Page $page, ?string $locale = null, PageRenderMode $mode = PageRenderMode::PUBLIC): PageRenderResult
    {
        // Le statut ne masque que le rendu public, jamais l'aperçu éditorial.
        if ($mode === PageRenderMode::PUBLIC && $page->status !== 1) {
            return new PageRenderResult('', false, false);
        }

        $this->renderLocale = $locale;
        $this->renderMode = $mode;
        $this->components = [];
        $cssUrl = ($page->raw_css ?? '') !== '' ? '/pages/' . $page->id . '/css' : null;
        if ($page->type === 1) {
            $html = HtmlSanitizer::sanitize($page->raw_html);
            $html = str_contains($html, '[[') ? $this->coreShortCodeHelper->applyShortCodes($html, $page->space_id) : $html;
            return new PageRenderResult('<div id="page-' . $page->id . '">' . $html . '</div>', true, false, $cssUrl);
        }
        if ($page->type !== 2) throw new \UnexpectedValueException('Invalid page type');

        // Les anciennes données seront supprimées au reset prévu pour la v3.
        // Un ancien footer ne doit pas provoquer une redirection de toutes les pages.
        if (($page->data->version ?? null) !== PageDocument::VERSION) {
            return new PageRenderResult('<div id="page-' . $page->id . '"></div>', true, false, null);
        }

        $document = $this->corePageComponentRegistry->normalizeDocument($page->data, $page->space_id, PageDataMode::RENDER, $this->components);
        $html = '<div id="page-' . $page->id . '">' . $this->renderItems($page, $document->items) . '</div>';
        return new PageRenderResult($html, true, $document->container->fluid, $cssUrl);
    }

    /** Le fragment suit aussi la visibilité de ses ancêtres et la validation du brouillon complet. */
    public function renderCanvas(Page $page, string $nodeId, string $locale): string
    {
        $this->renderLocale = $locale;
        $this->renderMode = PageRenderMode::CANVAS;
        $this->components = [];
        $find = function (array $items) use (&$find, $page, $nodeId): ?object {
            foreach ($items as $item) {
                if (!$this->isVisible($page, $item->props)) continue;
                if ($item->id === $nodeId) return $item;
                foreach (PageDocument::childZones($item) as $zone) {
                    if (!$this->isVisible($page, $zone->props)) continue;
                    $found = $find($zone->items);
                    if ($found !== null) return $found;
                }
            }
            return null;
        };
        $node = $find($page->data->items);
        return $node === null ? '' : '<div id="page-' . $page->id . '">' . $this->renderItems($page, [$node]) . '</div>';
    }

    private function renderItems(Page $page, array $items): string
    {
        $html = '';
        foreach ($items as $item) {
            if (!$this->isVisible($page, $item->props)) continue;
            $class = $this->corePageComponentRegistry->get($item->type);
            if ($class === null) continue;
            $component = $this->components[$item->type] ??= $this->corePageComponentRegistry->create($item->type);
            $node = clone $item;
            $specific = clone $item->props;
            unset($specific->common);
            $specific = $this->corePageComponentRegistry->projectPropsForLocale($class, $specific, $this->localeFor($page), $page->space_id);
            if ($class::getPropsSchema() !== null && !$this->sharedObjectValidator->validate($specific, $class::getPropsSchema())->isValid()) continue;
            $node->props = $specific;

            // Retirer les corps interdits avant de remettre le nœud au composant.
            $node->zones = new \stdClass();
            foreach (get_object_vars($item->zones) as $name => $bodies) {
                $node->zones->{$name} = array_values(array_filter($bodies, fn ($zone) => $this->isVisible($page, $zone->props)));
            }
            $context = new PageRenderContext(
                $page, $this->localeFor($page), $this->renderMode,
                fn (array $children): string => $this->renderItems($page, $children),
                fn (object $props): bool => $this->isVisible($page, $props),
                fn (object $props): string => $this->cssClasses($props),
                fn (mixed $value): string => $this->localizedText($page, $value),
            );
            $inner = $component->render($node, $context);
            if ($inner === '') continue;
            $publicVueComponent = $class::getPublicVueComponentNameFor($node);
            if ($this->renderMode === PageRenderMode::PUBLIC && $publicVueComponent !== null) {
                $json = json_encode($component->getPublicProps($node, $context), JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
                $inner = '<div data-page-vue="' . HtmlTools::escape($publicVueComponent) . '" data-page-props="'
                    . HtmlTools::escape($json) . '">' . $inner . '</div>';
            }
            $html .= '<div class="' . HtmlTools::escape($this->cssClasses($item->props)) . '" data-page-node="'
                . HtmlTools::escape($item->id) . '">' . $inner . '</div>';
        }
        return $html;
    }

    private function localizedText(Page $page, mixed $value): string
    {
        // Les chaînes ordinaires n'ont pas besoin d'accéder au moteur de langue.
        if (is_string($value) && !str_starts_with($value, 'LG_')) {
            return $value;
        }

        $locale = $this->localeFor($page);
        $selected = PageLocalizedText::select($value, $locale);

        return str_starts_with($selected, 'LG_')
            ? $this->coreLanguageProjectionHelper->projectFieldValue($selected, FieldType::TEXT, $locale, $page->space_id)
            : $selected;
    }

    private function isVisible(Page $page, object $props): bool
    {
        // Une condition posée sur une ligne ou une colonne masque également
        // tout son contenu, car leurs enfants ne seront pas parcourus.
        $common = $props->common ?? null;
        if (is_object($common) && property_exists($common, 'visible') && $common->visible !== true) return false;
        $languages = is_object($common) && property_exists($common, 'languages')
            ? $common->languages
            : [];

        if (!is_array($languages) || ($languages !== []
            && !in_array($this->localeFor($page), $languages, true))) {
            return false;
        }

        $roles = $props->common->role_ids ?? [];
        // Le canevas d’édition doit toujours montrer le composant pour pouvoir
        // le sélectionner et le modifier, même si l’éditeur n’a pas le rôle choisi.
        // L’aperçu fidèle et le site public conservent ce filtre.
        if ($this->renderMode === PageRenderMode::CANVAS || !is_array($roles) || $roles === []) return true;
        foreach ($roles as $roleId) {
            if (is_int($roleId) && $roleId > 0 && $this->coreAclHelper->hasGlobalRole($roleId, $page->space_id)) return true;
        }
        return false;
    }

    private function localeFor(Page $page): string
    {
        return $this->renderLocale ??= $this->corePublicLocaleHelper->locale()
            ?? $this->coreSpaceLanguageHelper->getDefaultLocale($page->space_id);
    }

    private function cssClasses(object $props): string
    {
        $classes = ['page-component'];
        $custom = trim((string) ($props->common->class ?? ''));
        if ($custom !== '' && preg_match('/^[a-zA-Z0-9_ -]{1,200}$/', $custom)) $classes[] = $custom;
        $hidden = $props->common->hidden_at ?? [];
        if (is_array($hidden)) {
            foreach ($hidden as $size) {
                if (in_array($size, ['sm', 'md', 'lg', 'xl', 'xxl'], true)) $classes[] = 'd-' . $size . '-none';
            }
        }
        return implode(' ', $classes);
    }

}
