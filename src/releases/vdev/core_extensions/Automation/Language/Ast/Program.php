<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Language\Ast;

use YTassy\Tycms\CoreExts\Automation\Language\SourceSpan;

final readonly class Program extends Node
{
    /** @param list<FunctionDecl> $functions */
    public function __construct(
        public array $functions,
        SourceSpan $span,
    ) {
        parent::__construct($span);
    }
}
