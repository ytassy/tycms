<?php

namespace YTassy\Tycms\CoreExts\Core\Helper\Page\Renderer;


final class PageRenderResult
{

    public function __construct(
        private readonly string $html,
        public readonly bool $container,
        public readonly bool $containerFluid,
        public readonly ?string $cssUrl = null,
    ) {
    }

    public function getHtml(bool $withContainer = true): string{
        $link = $this->cssUrl === null ? '' : '<link rel="stylesheet" href="' . htmlspecialchars($this->cssUrl, ENT_QUOTES, 'UTF-8') . '">';
        if ($withContainer && $this->container) {
            return $link . '<div class="' . ($this->containerFluid ? 'container-fluid' : 'container') . '">'
             . $this->html . '</div>';
        }

        return $link . $this->html;
    }

}
