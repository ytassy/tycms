<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Runtime;

use YTassy\Tycms\CoreExts\Automation\Language\Diagnostic;
use YTassy\Tycms\CoreExts\Automation\Language\SourceSpan;

final readonly class ExecutionStep
{
    /** @param array<mixed> $arguments */
    public function __construct(
        public int $sequence,
        public string $kind,
        public string $identifier,
        public SourceSpan $span,
        public array $arguments,
        public mixed $result,
        public ?Diagnostic $error = null,
        public string $status = 'ok',
    ) {
    }
}
