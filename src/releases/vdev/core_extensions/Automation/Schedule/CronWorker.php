<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Schedule;

use YTassy\Tycms\CoreExts\Automation\Traits\TraitAutomationHelpers;
use YTassy\Tycms\CoreExts\Core\Cron\CronTick;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;

/**
 * Worker des déclencheurs planifiés, appelé par le hook CRON de Core.
 *
 * Chaque scénario dû est exécuté dans sa propre transaction et dédupliqué par
 * créneau ; un échec n'empêche donc pas les autres scénarios de tourner. Le
 * worker borne son temps et son nombre de scénarios pour rester sous la limite
 * PHP de l'hébergement mutualisé. Aucun payload ni secret n'est journalisé.
 */
final class CronWorker extends BaseHelper
{
    use TraitAutomationHelpers;

    public const TRIGGER_ID = 'automation.schedule.tick';

    /** Configurations lues au maximum par tick. */
    private const MAX_TRIGGERS = 500;

    /** Budget souple pour rester sous un max_execution_time de 60 secondes. */
    private const MAX_RUNTIME_SECONDS = 45;

    /** Nombre maximal de scénarios réellement démarrés dans un tick. */
    private const MAX_SCENARIOS_PER_TICK = 100;

    /**
     * @return array{
     *     due: int,
     *     started: int,
     *     succeeded: int,
     *     skipped: int,
     *     failed: int,
     *     deferred: int
     * }
     */
    public function run(CronTick $tick): array
    {
        $startedAt = microtime(true);
        $rows = $this->automationRepository->findActiveScheduledTriggers(self::TRIGGER_ID);
        $summary = [
            'due' => 0,
            'started' => 0,
            'succeeded' => 0,
            'skipped' => 0,
            'failed' => 0,
            'deferred' => 0,
        ];

        if (count($rows) >= self::MAX_TRIGGERS) {
            $this->logWarning(
                'Le worker de planification a atteint sa limite de déclencheurs.',
                ['limit' => self::MAX_TRIGGERS],
                'AUTOMATION_CRON_TRIGGER_LIMIT',
                'automation',
            );
        }

        foreach ($rows as $row) {
            $this->runScenario($row, $summary, $tick, $startedAt);
        }

        if ($summary['deferred'] > 0) {
            $this->logWarning(
                'Le worker de planification a différé des scénarios dus.',
                $summary,
                'AUTOMATION_CRON_BUDGET',
                'automation',
            );
        }

        // Une ligne par minute sans créneau dû ne doit pas saturer les journaux.
        if ($summary['due'] > 0 || $summary['failed'] > 0) {
            $this->logInfo(
                'Worker de planification terminé.',
                $summary,
                'AUTOMATION_CRON_SUMMARY',
                'automation',
            );
        }

        return $summary;
    }

    /**
     * @param array{
     *     due: int,
     *     started: int,
     *     succeeded: int,
     *     skipped: int,
     *     failed: int,
     *     deferred: int
     * } $summary
     */
    private function runScenario(object $row, array &$summary, CronTick $tick, float $startedAt): void
    {
        $scenarioId = (int) ($row->scenario_id ?? 0);
        $spaceId = (int) ($row->space_id ?? 0);
        $scenarioKey = (string) ($row->scenario_key ?? '');

        if ($scenarioId <= 0 || $spaceId <= 0 || $scenarioKey === '') {
            $summary['failed']++;
            $this->logWarning(
                'Le déclencheur planifié est mal identifié.',
                ['space_id' => $spaceId],
                'AUTOMATION_CRON_CONFIG_INVALID',
                'automation',
            );

            return;
        }

        try {
            $config = ScheduleConfig::normalize(
                ScheduleConfig::decode((string) ($row->config ?? '')),
            );
            $timezone = ScheduleCalculator::spaceTimezone($row->space_params ?? null);
            $slot = ScheduleCalculator::dueSlot($config, $timezone, $tick->now);
        } catch (\Throwable $exception) {
            $summary['failed']++;
            $this->logWarning(
                'La planification du scénario #' . $scenarioId . ' est invalide.',
                ['space_id' => $spaceId, 'scenario_id' => $scenarioId],
                'AUTOMATION_CRON_CONFIG_INVALID',
                'automation',
            );
            $this->logException($exception);

            return;
        }

        if ($slot === null) {
            return;
        }

        $summary['due']++;
        $dedupKey = 'schedule:' . $scenarioId . ':' . ScheduleCalculator::slotLabel($slot);

        // La contrainte unique des runs protège aussi ce contrôle, mais il
        // évite de rouvrir une transaction pour un créneau déjà traité.
        if ($this->automationRepository->findRunByDedup($scenarioId, $dedupKey) !== null) {
            $summary['skipped']++;

            return;
        }

        // On s'arrête avant d'ouvrir une transaction si le tick a consommé son
        // budget : le créneau n'est pas rattrapé automatiquement.
        if ($summary['started'] >= self::MAX_SCENARIOS_PER_TICK
            || microtime(true) - $startedAt >= self::MAX_RUNTIME_SECONDS) {
            $summary['deferred']++;

            return;
        }

        $summary['started']++;
        $payload = [
            'scheduled_at' => ScheduleCalculator::scheduledAt($slot),
            'slot' => ScheduleCalculator::slotLabel($slot),
            'preset' => $config['preset'],
        ];

        // Chaque scénario est indépendant : un échec ne doit pas annuler les
        // runs déjà validés des autres déclencheurs.
        $this->db->execute('START TRANSACTION');

        try {
            $result = $this->automationTrigger->triggerNow(
                $spaceId,
                self::TRIGGER_ID,
                $payload,
                null,
                $scenarioKey,
                $dedupKey,
            );
            $this->db->execute('COMMIT');
        } catch (\Throwable $exception) {
            try {
                $this->db->execute('ROLLBACK');
            } catch (\Throwable) {
                // La connexion peut déjà être fermée ; l'erreur d'origine est
                // plus utile que l'échec du rollback.
            }

            $summary['failed']++;
            $this->logError(
                'Le run planifié du scénario #' . $scenarioId . ' a échoué.',
                ['space_id' => $spaceId, 'scenario_id' => $scenarioId],
                'AUTOMATION_CRON_FAILED',
                'automation',
            );
            $this->logException($exception);

            return;
        }

        if (!$result->hasRuns()) {
            $summary['failed']++;
            $this->logWarning(
                'Aucun run planifié n’a été créé pour le scénario #' . $scenarioId . '.',
                ['space_id' => $spaceId, 'scenario_id' => $scenarioId],
                'AUTOMATION_CRON_EMPTY',
                'automation',
            );

            return;
        }

        foreach ($result->runs as $outcome) {
            if ($outcome->isFailed()) {
                $summary['failed']++;
            } elseif ($outcome->status === 'skipped') {
                $summary['skipped']++;
            } else {
                $summary['succeeded']++;
            }
        }
    }
}
