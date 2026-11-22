<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\ExtensionApi;

use YTassy\Tycms\CoreExts\Automation\Language\Limits;
use YTassy\Tycms\CoreExts\Automation\Language\SourcePosition;
use YTassy\Tycms\CoreExts\Automation\Language\SourceSpan;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;
use YTassy\Tycms\CoreExts\Automation\Traits\TraitAutomationHelpers;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;

/**
 * Point d'entrée des producteurs de déclencheurs.
 *
 * `emit` écrit dans l'outbox et exécute après le commit. `triggerNow` exécute
 * immédiatement dans la transaction de l'appelant : le producteur décide de
 * commiter ou d'annuler. Aucun payload, secret ou contenu utilisateur n'est
 * journalisé ; les échecs synchrones laissent une trace technique.
 */
final class AutomationTrigger extends BaseHelper
{
    use TraitAutomationHelpers;

    private const MAX_SYNC_DEPTH = 8;
    private const TRIGGER_PATTERN = '/\A[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*){1,4}\z/';
    private const SCENARIO_KEY_PATTERN = '/\A[a-z][a-z0-9_-]{0,63}\z/';

    private int $syncDepth = 0;

    /**
     * Écrit un événement dans la transaction métier ; le drain après commit
     * exécute les scénarios abonnés.
     *
     * @param array<mixed> $payload
     */
    public function emit(
        int $spaceId,
        string $triggerId,
        array $payload,
        string $principalType = AutomationPrincipalType::USER,
        ?int $principalId = null,
        ?string $targetScenarioKey = null,
    ): ?string {
        return $this->automationEventBuffer->emit(
            $spaceId,
            $triggerId,
            $payload,
            $principalType,
            $principalId,
            $targetScenarioKey,
        );
    }

    /**
     * Exécute synchroniquement les scénarios publiés du trigger dans la
     * transaction de l'appelant.
     *
     * @param array<mixed> $payload
     */
    public function triggerNow(
        int $spaceId,
        string $triggerId,
        array $payload,
        ?int $actorId = null,
        ?string $scenarioKey = null,
        ?string $dedupKey = null,
    ): AutomationTriggerResult {
        if ($spaceId <= 0 || preg_match(self::TRIGGER_PATTERN, $triggerId) !== 1) {
            $this->logWarning(
                'Déclenchement synchrone refusé : identifiants invalides.',
                ['space_id' => $spaceId],
                'AUTOMATION_TRIGGER_SYNC_INVALID',
                'automation',
            );

            return new AutomationTriggerResult();
        }

        if ($this->syncDepth >= self::MAX_SYNC_DEPTH) {
            $this->logWarning(
                'Profondeur maximale des déclenchements synchrones atteinte.',
                ['space_id' => $spaceId, 'trigger_id' => $triggerId, 'depth' => $this->syncDepth],
                'AUTOMATION_TRIGGER_SYNC_DEPTH',
                'automation',
            );

            return new AutomationTriggerResult();
        }

        try {
            $safePayload = ValueTools::assertSafe($payload, new Limits(), self::neutralSpan());
        } catch (\Throwable $exception) {
            $this->logWarning(
                'La charge utile du déclenchement synchrone est invalide.',
                ['space_id' => $spaceId, 'trigger_id' => $triggerId],
                'AUTOMATION_TRIGGER_SYNC_PAYLOAD_INVALID',
                'automation',
            );
            $this->logException($exception);

            return new AutomationTriggerResult();
        }

        if (!is_array($safePayload) || ($safePayload !== [] && array_is_list($safePayload))) {
            $this->logWarning(
                'La charge utile du déclenchement synchrone doit être une map.',
                ['space_id' => $spaceId, 'trigger_id' => $triggerId],
                'AUTOMATION_TRIGGER_SYNC_PAYLOAD_INVALID',
                'automation',
            );

            return new AutomationTriggerResult();
        }

        $scenarios = $this->scenarios($spaceId, $triggerId, $scenarioKey);

        if ($scenarios === []) {
            $this->logWarning(
                'Aucun scénario publié pour ce déclenchement synchrone.',
                [
                    'space_id' => $spaceId,
                    'trigger_id' => $triggerId,
                    'scenario_key' => $scenarioKey,
                ],
                'AUTOMATION_TRIGGER_SYNC_EMPTY',
                'automation',
            );

            return new AutomationTriggerResult();
        }

        $dedupKey = $this->normalizeDedupKey($dedupKey);
        $this->syncDepth++;
        $runs = [];

        try {
            foreach ($scenarios as $scenarioId) {
                $this->logInfo(
                    'Déclenchement synchrone démarré.',
                    [
                        'space_id' => $spaceId,
                        'trigger_id' => $triggerId,
                        'scenario_id' => $scenarioId,
                        'depth' => $this->syncDepth,
                    ],
                    'AUTOMATION_TRIGGER_SYNC_START',
                    'automation',
                );

                try {
                    $result = $this->automationRunExecutor->runNow(
                        $scenarioId,
                        $safePayload,
                        $actorId ?? 0,
                        $triggerId,
                        $dedupKey,
                        $this->syncDepth,
                    );
                } catch (\Throwable $exception) {
                    $this->logError(
                        'Le run synchrone a échoué.',
                        [
                            'space_id' => $spaceId,
                            'trigger_id' => $triggerId,
                            'scenario_id' => $scenarioId,
                            'depth' => $this->syncDepth,
                        ],
                        'AUTOMATION_TRIGGER_SYNC_FAILED',
                        'automation',
                    );
                    $this->logException($exception);
                    $runs[] = new AutomationRunOutcome(
                        null,
                        $scenarioId,
                        'failed',
                        null,
                        'Erreur interne du run.',
                    );
                    continue;
                }

                $status = (string) ($result['status'] ?? 'failed');
                $runId = isset($result['run_id']) && is_int($result['run_id']) ? $result['run_id'] : null;
                $message = isset($result['message']) && is_string($result['message']) ? $result['message'] : null;
                $outcome = new AutomationRunOutcome(
                    $runId,
                    $scenarioId,
                    $status,
                    $result['output'] ?? null,
                    $message,
                );
                $runs[] = $outcome;

                $context = [
                    'space_id' => $spaceId,
                    'trigger_id' => $triggerId,
                    'scenario_id' => $scenarioId,
                    'run_id' => $runId,
                    'status' => $status,
                    'depth' => $this->syncDepth,
                ];

                if ($outcome->isFailed()) {
                    $context['message'] = $message;
                    $this->logWarning(
                        'Run synchrone en échec.',
                        $context,
                        'AUTOMATION_TRIGGER_SYNC_RUN_FAILED',
                        'automation',
                    );
                } else {
                    $this->logInfo(
                        'Run synchrone terminé.',
                        $context,
                        'AUTOMATION_TRIGGER_SYNC_RUN',
                        'automation',
                    );
                }
            }
        } finally {
            $this->syncDepth--;
        }

        return new AutomationTriggerResult($runs);
    }

    /** @return list<int> */
    private function scenarios(int $spaceId, string $triggerId, ?string $scenarioKey): array
    {
        if ($scenarioKey !== null) {
            if (preg_match(self::SCENARIO_KEY_PATTERN, $scenarioKey) !== 1) {
                $this->logWarning(
                    'Clé de scénario invalide pour un déclenchement synchrone.',
                    ['space_id' => $spaceId, 'trigger_id' => $triggerId],
                    'AUTOMATION_TRIGGER_SYNC_INVALID',
                    'automation',
                );

                return [];
            }

            $scenario = $this->automationRepository->findScenarioByKey($spaceId, $scenarioKey);

            if ($scenario === null || (string) $scenario->status !== 'active') {
                return [];
            }

            $trigger = $this->automationRepository->findTrigger((int) $scenario->id);

            if ($trigger === null
                || (string) $trigger->trigger_id !== $triggerId
                || (int) $trigger->enabled !== 1
                || $this->automationRepository->findPublishedVersion((int) $scenario->id) === null) {
                return [];
            }

            return [(int) $scenario->id];
        }

        $rows = $this->automationRepository->findActiveScenariosForTrigger($spaceId, $triggerId);

        return array_values(array_map(
            static fn (object $row): int => (int) $row->scenario_id,
            $rows,
        ));
    }

    private function normalizeDedupKey(?string $key): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }

        return strlen($key) <= 100 ? $key : hash('sha256', $key);
    }

    private static function neutralSpan(): SourceSpan
    {
        $position = new SourcePosition(0, 1, 1);

        return new SourceSpan($position, $position);
    }
}
