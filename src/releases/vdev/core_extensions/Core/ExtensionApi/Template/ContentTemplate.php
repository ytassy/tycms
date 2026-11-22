<?php

namespace YTassy\Tycms\CoreExts\Core\ExtensionApi\Template;

class ContentTemplate
{

	public function __construct(
        private readonly string $html,
		public readonly bool $container = false,
        public readonly bool $containerFluid = false,
        public readonly VueMode $vueMode = VueMode::FULL_BODY
    ) {
    }

    public function getHtml(bool $withContainer = true): string{

        if ($withContainer && $this->container) {
            return '<div class="' . ($this->containerFluid ? 'container-fluid' : 'container') . '">'
             . $this->html . '</div>';
        }

        return $this->html;
    }

    public function getHtmlWithContainer(): string{

        return $this->html;
    }

	
}
