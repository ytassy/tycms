<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\ExtensionApi;

/** Résultat d'un run exécuté par un déclenchement synchrone. */
final readonly class AutomationRunOutcome
{
    public function __construct(
        public ?int $runId,
        public int $scenarioId,
        public string $status,
        public mixed $output,
        public ?string $message,
    ) {
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }
}
