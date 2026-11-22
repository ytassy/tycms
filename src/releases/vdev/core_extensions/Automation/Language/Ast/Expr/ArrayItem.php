<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Language\Ast\Expr;

use YTassy\Tycms\CoreExts\Automation\Language\Ast\Node;
use YTassy\Tycms\CoreExts\Automation\Language\SourceSpan;

final readonly class ArrayItem extends Node
{
    public function __construct(
        public ?Expression $key,
        public Expression $value,
        SourceSpan $span,
    ) {
        parent::__construct($span);
    }
}
