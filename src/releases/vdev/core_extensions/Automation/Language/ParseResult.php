<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Language;

use YTassy\Tycms\CoreExts\Automation\Language\Ast\Program;

final readonly class ParseResult
{
    /** @param list<Diagnostic> $diagnostics */
    public function __construct(
        public ?Program $program,
        public array $diagnostics,
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->program !== null && $this->diagnostics === [];
    }
}
