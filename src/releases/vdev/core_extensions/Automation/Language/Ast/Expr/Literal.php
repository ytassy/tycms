<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Language\Ast\Expr;

use YTassy\Tycms\CoreExts\Automation\Language\SourceSpan;

final readonly class Literal extends Expression
{
    public function __construct(
        public mixed $value,
        SourceSpan $span,
    ) {
        parent::__construct($span);
    }
}
