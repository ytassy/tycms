<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\ExtensionApi;

use YTassy\Tycms\CoreExts\Automation\Language\SourceSpan;

/** Contexte explicite fourni à une action : identité, Space et run. */
final readonly class ActionContext
{
    public function __construct(
        public string $principalType,
        public ?int $principalId,
        public int $spaceId,
        public ?int $runId,
        public int $scenarioId,
        public int $versionId,
        public SourceSpan $span,
    ) {
    }

    public function isUser(): bool
    {
        return $this->principalType === AutomationPrincipalType::USER
            && $this->principalId !== null;
    }

    public function isService(): bool
    {
        return $this->principalType === AutomationPrincipalType::SERVICE;
    }
}
