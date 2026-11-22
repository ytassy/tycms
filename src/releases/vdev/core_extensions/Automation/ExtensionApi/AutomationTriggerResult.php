<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\ExtensionApi;

/** Résultats d'un déclenchement synchrone. */
final readonly class AutomationTriggerResult
{
    /** @param list<AutomationRunOutcome> $runs */
    public function __construct(public array $runs = [])
    {
    }

    public function hasRuns(): bool
    {
        return $this->runs !== [];
    }

    public function hasFailures(): bool
    {
        foreach ($this->runs as $run) {
            if ($run->isFailed()) {
                return true;
            }
        }

        return false;
    }

    public function isSuccessful(): bool
    {
        return $this->runs !== [] && !$this->hasFailures();
    }
}
