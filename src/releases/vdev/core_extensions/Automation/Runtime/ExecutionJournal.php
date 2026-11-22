<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Runtime;

use YTassy\Tycms\CoreExts\Automation\Language\Diagnostic;
use YTassy\Tycms\CoreExts\Automation\Language\SourceSpan;

/** Journal en mémoire d'un run, borné pour éviter une croissance illimitée. */
final class ExecutionJournal
{
    /** @var list<ExecutionStep> */
    private array $steps = [];

    private bool $truncated = false;

    public function __construct(private readonly int $maxEntries = 1000)
    {
    }

    /** @param array<mixed> $arguments */
    public function add(
        string $kind,
        string $identifier,
        SourceSpan $span,
        array $arguments,
        mixed $result,
        ?Diagnostic $error = null,
        string $status = 'ok',
    ): void {
        $sequence = count($this->steps) + 1;

        if ($sequence > $this->maxEntries) {
            $this->truncated = true;

            return;
        }

        $this->steps[] = new ExecutionStep(
            $sequence,
            $kind,
            $identifier,
            $span,
            $arguments,
            $result,
            $error,
            $status,
        );
    }

    /** @return list<ExecutionStep> */
    public function all(): array
    {
        return $this->steps;
    }

    public function count(): int
    {
        return count($this->steps);
    }

    public function isTruncated(): bool
    {
        return $this->truncated;
    }
}
