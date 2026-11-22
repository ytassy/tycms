<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Runtime;

use YTassy\Tycms\CoreExts\Automation\Language\Diagnostic;

final readonly class ExecutionResult
{
    /** @param list<Diagnostic> $diagnostics */
    public function __construct(
        public ExecutionStatus $status,
        public mixed $output,
        public ?string $message,
        public array $diagnostics,
        public ExecutionJournal $journal,
        public int $steps,
        public int $loopIterations,
        public int $actionCalls,
        public float $durationSeconds,
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->status !== ExecutionStatus::FAILED;
    }
}
