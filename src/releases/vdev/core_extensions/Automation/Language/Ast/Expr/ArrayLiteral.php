<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Language\Ast\Expr;

use YTassy\Tycms\CoreExts\Automation\Language\SourceSpan;

final readonly class ArrayLiteral extends Expression
{
    /** @param list<ArrayItem> $items */
    public function __construct(
        public array $items,
        public bool $isMap,
        SourceSpan $span,
    ) {
        parent::__construct($span);
    }
}
