<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Language\Ast\Stmt;

use YTassy\Tycms\CoreExts\Automation\Language\Ast\Expr\Expression;
use YTassy\Tycms\CoreExts\Automation\Language\SourceSpan;

final readonly class WhileStatement extends Statement
{
    public function __construct(
        public Expression $condition,
        public Statement $body,
        SourceSpan $span,
    ) {
        parent::__construct($span);
    }
}
