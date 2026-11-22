<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Language;

final readonly class Diagnostic
{
    public function __construct(
        public string $code,
        public string $message,
        public SourceSpan $span,
        public Severity $severity = Severity::ERROR,
    ) {
    }

    public function __toString(): string
    {
        return sprintf(
            '%d:%d [%s] %s',
            $this->span->start->line,
            $this->span->start->column,
            $this->code,
            $this->message,
        );
    }
}
