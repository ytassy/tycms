<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Language\Ast\Stmt;

use YTassy\Tycms\CoreExts\Automation\Language\SourceSpan;

final readonly class Block extends Statement
{
    /** @param list<Statement> $statements */
    public function __construct(
        public array $statements,
        SourceSpan $span,
    ) {
        parent::__construct($span);
    }
}
