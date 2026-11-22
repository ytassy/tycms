<?php

namespace YTassy\Tycms\Extensions;

final readonly class EmailRenderContext
{
    public function __construct(
        public string $siteUrl,
        public string $spaceTitle,
        public string $locale = 'fr',
    ) {}
}
