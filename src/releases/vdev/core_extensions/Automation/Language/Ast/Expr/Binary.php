<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Language\Ast\Expr;

use YTassy\Tycms\CoreExts\Automation\Language\SourceSpan;

final readonly class Binary extends Expression
{
    public function __construct(
        public Expression $left,
        public string $operator,
        public Expression $right,
        SourceSpan $span,
    ) {
        parent::__construct($span);
    }
}
