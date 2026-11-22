<?php

namespace YTassy\Tycms\CoreExts\Core\ExtensionApi\Page;

use YTassy\Tycms\CoreExts\Core\Models\Page;

/** Services de rendu limités à l'opération courante ; jamais sérialisés pour Vue. */
final readonly class PageRenderContext
{
    public int $spaceId;

    public function __construct(
        public Page $page,
        public string $locale,
        public PageRenderMode $mode,
        private \Closure $renderChildren,
        private \Closure $visibility,
        private \Closure $classes,
        private \Closure $projectText,
    ) {
        $this->spaceId = $page->space_id;
    }

    public function renderItems(array $items): string
    {
        return ($this->renderChildren)($items);
    }

    /** Enveloppe prête à l'emploi pour les styles et la visibilité d'un corps. */
    public function renderZone(object $zone): string
    {
        if (!$this->isVisible($zone->props)) return '';
        return '<div class="' . \YTassy\Tycms\Tools\HtmlTools::escape($this->cssClasses($zone->props))
            . '" data-page-node="' . \YTassy\Tycms\Tools\HtmlTools::escape($zone->id) . '">'
            . $this->renderItems($zone->items) . '</div>';
    }

    public function isVisible(object $props): bool
    {
        return ($this->visibility)($props);
    }

    public function cssClasses(object $props): string
    {
        return ($this->classes)($props);
    }

    public function text(mixed $value): string
    {
        return ($this->projectText)($value);
    }
}
