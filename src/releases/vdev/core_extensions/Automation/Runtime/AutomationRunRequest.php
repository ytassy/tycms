<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Runtime;

use YTassy\Tycms\Application;

/**
 * Paramètres résolus d'un run, que l'origine soit manuelle ou un événement.
 *
 * L'Application d'exécution peut être différente de l'Application courante :
 * le mode author utilise une instance isolée portant la session de l'auteur.
 */
final readonly class AutomationRunRequest
{
    /**
     * @param array<mixed> $input
     * @param object $scenario ligne automation_scenarios
     * @param object $version ligne automation_versions publiée
     */
    public function __construct(
        public object $scenario,
        public object $version,
        public array $input,
        public string $triggerId,
        public ?int $eventRowId,
        public ?string $eventUuid,
        public ?string $dedupKey,
        public int $depth,
        public ?int $parentRunId,
        public string $principalType,
        public int $principalId,
        public ?string $principalLabel,
        public ?string $correlationId,
        public Application $executionApp,
        public bool $notifyOnFailure = true,
    ) {
    }
}
