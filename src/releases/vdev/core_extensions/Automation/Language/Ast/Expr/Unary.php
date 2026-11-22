<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Language\Ast\Expr;

use YTassy\Tycms\CoreExts\Automation\Language\SourceSpan;

final readonly class Unary extends Expression
{
    public function __construct(
        public string $operator,
        public Expression $operand,
        SourceSpan $span,
    ) {
        parent::__construct($span);
    }
}
