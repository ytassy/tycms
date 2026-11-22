<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Language\Ast\Stmt;

use YTassy\Tycms\CoreExts\Automation\Language\SourceSpan;

final readonly class ContinueStatement extends Statement
{
    public function __construct(SourceSpan $span)
    {
        parent::__construct($span);
    }
}
