<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Language;

final readonly class Token
{
    public function __construct(
        public TokenType $type,
        public string $lexeme,
        public mixed $literal,
        public SourceSpan $span,
    ) {
    }
}
