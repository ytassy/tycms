<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Language\Ast;

use YTassy\Tycms\CoreExts\Automation\Language\Ast\Stmt\Block;
use YTassy\Tycms\CoreExts\Automation\Language\SourceSpan;

final readonly class FunctionDecl extends Node
{
    /** @param list<Parameter> $parameters */
    public function __construct(
        public string $name,
        public array $parameters,
        public Block $body,
        SourceSpan $span,
    ) {
        parent::__construct($span);
    }
}
