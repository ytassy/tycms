<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Runtime;

use YTassy\Tycms\CoreExts\Automation\Plugin;
use YTassy\Tycms\CoreExts\Automation\Traits\TraitAutomationHelpers;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;

/**
 * Résout les scénarios abonnés à un événement et exécute leurs runs.
 *
 * Un événement est réservé avant traitement : deux drains concurrents ne
 * peuvent pas exécuter deux fois la même ligne. La déduplication par run
 * protège en plus chaque couple scénario/événement.
 */
final class AutomationEventDispatcher extends BaseHelper
{
    use TraitAutomationHelpers;

    /** @return list<array<string, mixed>> Résultats des runs effectivement exécutés. */
    public function dispatch(string $eventId): array
    {
        $results = [];
        $event = $this->automationRepository->findEventByEventId($eventId);

        // Un événement absent a été annulé avec une transaction imbriquée :
        // il n'y a rien à exécuter et ce n'est pas une anomalie.
        if ($event === null) {
            return $results;
        }

        if ((string) $event->status !== 'pending') {
            return $results;
        }

        $triggerId = (string) $event->trigger_id;

        // Un déclencheur retiré par une extension désactivée ne doit plus agir.
        if (!$this->triggerIsRegistered($triggerId)) {
            $this->logWarning(
                'Le déclencheur d’automatisation n’est plus fourni par une extension active.',
                ['space_id' => (int) $event->space_id, 'trigger_id' => $triggerId],
                'AUTOMATION_TRIGGER_UNAVAILABLE',
                'automation',
            );
            $this->automationRepository->markEventFailed(
                (int) $event->id,
                'Déclencheur indisponible.',
            );

            return $results;
        }

        // La réservation est atomique : un événement déjà pris est ignoré.
        if (!$this->automationRepository->markEventProcessing((int) $event->id)) {
            return $results;
        }

        $spaceId = (int) $event->space_id;
        $failure = null;

        try {
            $scenarios = $this->automationRepository->findActiveScenariosForTrigger(
                $spaceId,
                $triggerId,
            );
            $targetScenarioKey = (string) ($event->target_scenario_key ?? '');

            if ($targetScenarioKey !== '') {
                $scenarios = array_values(array_filter(
                    $scenarios,
                    static fn (object $row): bool => (string) $row->scenario_key === $targetScenarioKey,
                ));
            }

            foreach ($scenarios as $scenario) {
                $scenarioId = (int) $scenario->scenario_id;

                // Un événement rejoué ne recrée pas un run déjà présent.
                if ($this->automationRepository->findRunByDedup($scenarioId, $eventId) !== null) {
                    continue;
                }

                try {
                    $result = $this->automationRunExecutor->runFromEvent($scenarioId, $event);

                    if (is_array($result)) {
                        $results[] = $result;
                    }
                } catch (\Throwable $exception) {
                    $failure ??= 'Un scénario n’a pas pu démarrer.';
                    $this->logError(
                        'Un run d’automatisation n’a pas pu démarrer.',
                        ['space_id' => $spaceId, 'scenario_id' => $scenarioId],
                        'AUTOMATION_EVENT_DISPATCH_FAILED',
                        'automation',
                    );
                    $this->logException($exception);
                }
            }
        } catch (\Throwable $exception) {
            $failure = 'La résolution des déclencheurs a échoué.';
            $this->logException($exception);
        }

        if ($failure !== null) {
            $this->automationRepository->markEventFailed((int) $event->id, $failure);

            return $results;
        }

        $this->automationRepository->markEventProcessed((int) $event->id);

        return $results;
    }

    private function triggerIsRegistered(string $triggerId): bool
    {
        $plugin = $this->app->getExtensions()['plg_automation'] ?? null;

        return $plugin instanceof Plugin
            && $plugin->getTriggerRegistry()?->has($triggerId) === true;
    }
}
