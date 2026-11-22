<?php

namespace YTassy\Tycms\CoreExts\Core\PageComponents;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageComponent;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageNormalizationContext;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageRenderContext;
use YTassy\Tycms\CoreExts\Core\PageComponents\Support\PageLinks;

/** Les helpers spécialisés restent partagés, avec les accès typés de Core. */
abstract class CorePageComponent extends PageComponent
{
    use \YTassy\Tycms\CoreExts\Core\Helper\TraitCoreAdditionalHelpers;

    public static function getPropsSchema(): array
    {
        return ['type' => 'object'];
    }

    private ?PageLinks $links = null;

    protected function pageLinks(): PageLinks
    {
        // Partager ce collaborateur entre les nœuds du même type pendant une opération.
        return $this->links ??= new PageLinks($this->coreMenuHelper, $this->coreMenuRouteHelper, $this->coreSpaceLanguageHelper);
    }

    protected function linkHref(PageRenderContext $context, object $props): ?string
    {
        if (($props->link_type ?? 'url') !== 'menu') return PageLinks::safeUrl((string) ($props->href ?? ''));
        return $this->pageLinks()->resolve($props, $context->spaceId, $context->locale);
    }

    protected function validateLink(object $props, PageNormalizationContext $context, bool $image = false): void
    {
        PageLinks::validate($props, $image);
        if ($context->isInput() && (!$image || ($props->click_action ?? 'none') === 'link')
            && ($props->link_type ?? 'url') === 'menu') {
            $this->pageLinks()->validateReference($props, $context->spaceId);
        }
    }

    protected function siteUrl(string $url): ?string
    {
        if ($url === '' || str_contains($url, '\\') || str_starts_with($url, '//') || preg_match('/[\x00-\x20]/', $url)) return null;
        return str_starts_with($url, '/') && !str_contains($url, '..') ? $url : null;
    }
}
