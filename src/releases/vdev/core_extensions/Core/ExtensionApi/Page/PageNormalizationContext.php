<?php

namespace YTassy\Tycms\CoreExts\Core\ExtensionApi\Page;

final readonly class PageNormalizationContext
{
    public function __construct(
        public int $spaceId,
        public PageDataMode $mode,
        public ?PageEditContext $editor = null,
    ) {}

    public function isInput(): bool
    {
        return $this->mode === PageDataMode::INPUT;
    }
}
