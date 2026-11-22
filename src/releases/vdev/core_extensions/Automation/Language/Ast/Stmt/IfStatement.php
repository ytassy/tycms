<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Language\Ast\Stmt;

use YTassy\Tycms\CoreExts\Automation\Language\SourceSpan;

final readonly class IfStatement extends Statement
{
    /** @param list<IfBranch> $branches */
    public function __construct(
        public array $branches,
        public ?Statement $else,
        SourceSpan $span,
    ) {
        parent::__construct($span);
    }
}
