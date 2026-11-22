<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Language\Ast;

use YTassy\Tycms\CoreExts\Automation\Language\SourceSpan;

final readonly class Parameter extends Node
{
    public function __construct(
        public string $name,
        SourceSpan $span,
    ) {
        parent::__construct($span);
    }
}
