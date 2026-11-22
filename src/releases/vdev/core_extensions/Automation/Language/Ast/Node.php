<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Language\Ast;

use YTassy\Tycms\CoreExts\Automation\Language\SourceSpan;

abstract readonly class Node
{
    public function __construct(public SourceSpan $span)
    {
    }
}
