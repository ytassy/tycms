<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Language\Ast\Expr;

use YTassy\Tycms\CoreExts\Automation\Language\SourceSpan;

final readonly class Access extends Expression
{
    public function __construct(
        public Expression $target,
        public string $field,
        public bool $nullSafe,
        SourceSpan $span,
    ) {
        parent::__construct($span);
    }
}
