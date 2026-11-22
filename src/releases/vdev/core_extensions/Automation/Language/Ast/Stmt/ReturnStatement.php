<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Language\Ast\Stmt;

use YTassy\Tycms\CoreExts\Automation\Language\Ast\Expr\Expression;
use YTassy\Tycms\CoreExts\Automation\Language\SourceSpan;

final readonly class ReturnStatement extends Statement
{
    public function __construct(
        public ?Expression $value,
        SourceSpan $span,
    ) {
        parent::__construct($span);
    }
}
