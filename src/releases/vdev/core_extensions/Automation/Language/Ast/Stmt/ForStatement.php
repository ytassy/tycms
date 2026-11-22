<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Language\Ast\Stmt;

use YTassy\Tycms\CoreExts\Automation\Language\Ast\Expr\Expression;
use YTassy\Tycms\CoreExts\Automation\Language\SourceSpan;

final readonly class ForStatement extends Statement
{
    public function __construct(
        public ?AssignmentStatement $init,
        public ?Expression $condition,
        public ?AssignmentStatement $update,
        public Statement $body,
        SourceSpan $span,
    ) {
        parent::__construct($span);
    }
}
