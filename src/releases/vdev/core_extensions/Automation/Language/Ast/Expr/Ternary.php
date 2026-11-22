<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Language\Ast\Expr;

use YTassy\Tycms\CoreExts\Automation\Language\SourceSpan;

final readonly class Ternary extends Expression
{
    public function __construct(
        public Expression $condition,
        public Expression $ifTrue,
        public Expression $ifFalse,
        SourceSpan $span,
    ) {
        parent::__construct($span);
    }
}
