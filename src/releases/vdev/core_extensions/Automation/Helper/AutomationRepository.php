<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Helper;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\DB\FormatIn;

/** Accès SQL aux scénarios, versions, déclencheurs, runs et étapes. */
final class AutomationRepository extends BaseHelper
{
    public function findScenarioById(int $scenarioId): ?object
    {
        return $this->db->selectOneOrNull(
            'SELECT * FROM automation_scenarios WHERE id = % LIMIT 1',
            [$scenarioId],
        );
    }

    public function findScenarioByKey(int $spaceId, string $key): ?object
    {
        return $this->db->selectOneOrNull(
            'SELECT * FROM automation_scenarios WHERE space_id = % AND `key` = % LIMIT 1',
            [$spaceId, $key],
        );
    }

    public function findPublishedVersion(int $scenarioId): ?object
    {
        return $this->db->selectOneOrNull(
            'SELECT v.* FROM automation_versions v
             INNER JOIN automation_scenarios s ON s.published_version_id = v.id
             WHERE s.id = % LIMIT 1',
            [$scenarioId],
        );
    }

    public function findVersion(int $versionId): ?object
    {
        return $this->db->selectOneOrNull(
            'SELECT * FROM automation_versions WHERE id = % LIMIT 1',
            [$versionId],
        );
    }

    public function findTrigger(int $scenarioId): ?object
    {
        return $this->db->selectOneOrNull(
            'SELECT * FROM automation_triggers WHERE scenario_id = % LIMIT 1',
            [$scenarioId],
        );
    }

    /**
     * Scénarios visibles pour l'administration, avec leur dernier run.
     *
     * @param list<int> $spaceIds
     * @return list<object>
     */
    public function listScenarios(array $spaceIds): array
    {
        if ($spaceIds === []) {
            return [];
        }

        return $this->db->select(
            'SELECT s.*, sp.title AS space_title, t.trigger_id, t.enabled AS trigger_enabled,
                    dv.version_number AS draft_version, pv.version_number AS published_version,
                    (SELECT r.status FROM automation_runs r
                     WHERE r.scenario_id = s.id ORDER BY r.id DESC LIMIT 1) AS last_run_status,
                    (SELECT r.created FROM automation_runs r
                     WHERE r.scenario_id = s.id ORDER BY r.id DESC LIMIT 1) AS last_run_at
             FROM automation_scenarios s
             INNER JOIN spaces sp ON sp.id = s.space_id
             LEFT JOIN automation_triggers t ON t.scenario_id = s.id
             LEFT JOIN automation_versions dv ON dv.id = s.draft_version_id
             LEFT JOIN automation_versions pv ON pv.id = s.published_version_id
             WHERE s.space_id IN (%) AND s.status <> \'archived\'
             ORDER BY COALESCE(s.updated, s.created) DESC, s.id DESC
             LIMIT 200',
            [new FormatIn($spaceIds)],
        );
    }

    /**
     * Scénarios actifs du Space abonnés à un déclencheur.
     *
     * Seuls les scénarios dont la version publiée existe sont retournés : un
     * déclencheur sans version exécutable ne doit pas créer de run vide.
     *
     * @return list<object>
     */
    public function findActiveScenariosForTrigger(int $spaceId, string $triggerId): array
    {
        return $this->db->select(
            'SELECT s.id AS scenario_id, s.`key` AS scenario_key, s.name AS scenario_name,
                    s.space_id, s.principal_mode, s.execution_user_id, s.published_version_id
             FROM automation_triggers t
             INNER JOIN automation_scenarios s ON s.id = t.scenario_id
             WHERE t.trigger_id = % AND t.enabled = 1
               AND s.space_id = % AND s.status = \'active\'
               AND s.published_version_id IS NOT NULL
             ORDER BY s.id',
            [$triggerId, $spaceId],
        );
    }

    /**
     * Déclencheurs planifiés actifs et publiés, avec le fuseau de leur Space.
     *
     * Le worker ne charge que ce qui peut réellement s'exécuter : un trigger
     * désactivé ou un scénario sans version publiée ne doit pas consommer de
     * créneau ni créer de run vide.
     *
     * @return list<object>
     */
    public function findActiveScheduledTriggers(string $triggerId): array
    {
        return $this->db->select(
            'SELECT t.id AS trigger_row_id, t.trigger_id, t.config, t.enabled,
                    s.id AS scenario_id, s.`key` AS scenario_key, s.name AS scenario_name,
                    s.space_id, sp.params AS space_params
             FROM automation_triggers t
             INNER JOIN automation_scenarios s ON s.id = t.scenario_id
             INNER JOIN spaces sp ON sp.id = s.space_id
             WHERE t.trigger_id = % AND t.enabled = 1
               AND s.status = \'active\' AND s.published_version_id IS NOT NULL
             ORDER BY t.id
             LIMIT 500',
            [$triggerId],
        );
    }

    public function createScenario(
        int $spaceId,
        string $key,
        string $name,
        string $description,
        string $principalMode,
        ?int $executionUserId,
        ?int $createdBy,
    ): int {
        $this->db->execute(
            "INSERT INTO automation_scenarios
                (space_id, `key`, name, description, status, principal_mode, execution_user_id, created_by, created)
             VALUES (%, %, %, %, 'active', %, %, %, current_timestamp())",
            [$spaceId, $key, $name, $description, $principalMode, $executionUserId, $createdBy],
        );

        return $this->db->getLastInsertId();
    }

    public function nextVersionNumber(int $scenarioId): int
    {
        $row = $this->db->selectOneOrNull(
            'SELECT COALESCE(MAX(version_number), 0) + 1 AS next_number
             FROM automation_versions WHERE scenario_id = %',
            [$scenarioId],
        );

        return (int) ($row->next_number ?? 1);
    }

    public function createVersion(
        int $scenarioId,
        int $versionNumber,
        string $compileStatus,
        string $source,
        string $sourceHash,
        ?string $ast,
        int $schemaVersion,
        int $grammarVersion,
        ?string $diagnostics,
        ?int $createdBy,
    ): int {
        $this->db->execute(
            "INSERT INTO automation_versions
                (scenario_id, version_number, status, compile_status, source, source_hash, ast,
                 schema_version, grammar_version, diagnostics, created_by, created)
             VALUES (%, %, 'draft', %, %, %, %, %, %, %, %, current_timestamp())",
            [
                $scenarioId,
                $versionNumber,
                $compileStatus,
                $source,
                $sourceHash,
                $ast,
                $schemaVersion,
                $grammarVersion,
                $diagnostics,
                $createdBy,
            ],
        );

        return $this->db->getLastInsertId();
    }

    public function publishVersion(int $scenarioId, int $versionId, ?int $publishedBy): void
    {
        $this->db->execute(
            "UPDATE automation_versions
             SET status = 'published', published_by = %, published_at = current_timestamp()
             WHERE id = % AND scenario_id = %",
            [$publishedBy, $versionId, $scenarioId],
        );

        $this->db->execute(
            "UPDATE automation_scenarios
             SET published_version_id = %, status = 'active', updated = current_timestamp()
             WHERE id = %",
            [$versionId, $scenarioId],
        );
    }

    public function updateScenario(
        int $scenarioId,
        string $name,
        string $description,
        string $principalMode,
        ?int $executionUserId,
    ): void {
        $this->db->execute(
            "UPDATE automation_scenarios
             SET name = %, description = %, principal_mode = %, execution_user_id = %,
                 updated = current_timestamp()
             WHERE id = %",
            [$name, $description, $principalMode, $executionUserId, $scenarioId],
        );
    }

    public function setExecutionUser(int $scenarioId, ?int $executionUserId): void
    {
        $this->db->execute(
            'UPDATE automation_scenarios
             SET execution_user_id = %, updated = current_timestamp()
             WHERE id = %',
            [$executionUserId, $scenarioId],
        );
    }

    public function setDraftVersion(int $scenarioId, int $versionId): void
    {
        $this->db->execute(
            'UPDATE automation_scenarios
             SET draft_version_id = %, updated = current_timestamp()
             WHERE id = %',
            [$versionId, $scenarioId],
        );
    }

    public function archiveScenario(int $scenarioId): void
    {
        $this->db->execute(
            "UPDATE automation_scenarios
             SET status = 'archived', archived_at = current_timestamp(), updated = current_timestamp()
             WHERE id = %",
            [$scenarioId],
        );
    }

    public function upsertTrigger(
        int $scenarioId,
        int $spaceId,
        string $triggerId,
        string $entryFunction,
        string $config,
        ?string $secretHash,
        bool $enabled,
    ): void {
        $this->db->execute(
            'INSERT INTO automation_triggers
                (scenario_id, space_id, trigger_id, entry_function, config, secret_hash, enabled, created)
             VALUES (%, %, %, %, %, %, %, current_timestamp())
             ON DUPLICATE KEY UPDATE
                trigger_id = VALUES(trigger_id),
                entry_function = VALUES(entry_function),
                config = VALUES(config),
                secret_hash = VALUES(secret_hash),
                enabled = VALUES(enabled),
                updated = current_timestamp()',
            [$scenarioId, $spaceId, $triggerId, $entryFunction, $config, $secretHash, $enabled ? 1 : 0],
        );
    }

    /** Met à jour la configuration et le secret sans toucher au reste du trigger. */
    public function updateTriggerSecrets(int $scenarioId, string $config, ?string $secretHash): void
    {
        $this->db->execute(
            'UPDATE automation_triggers
             SET config = %, secret_hash = %, updated = current_timestamp()
             WHERE scenario_id = %',
            [$config, $secretHash, $scenarioId],
        );
    }

    public function createRun(
        int $spaceId,
        int $scenarioId,
        int $versionId,
        ?string $triggerId,
        ?int $eventId,
        ?string $dedupKey,
        string $principalType,
        ?int $principalId,
        ?string $principalLabel,
        string $input,
        int $depth,
        ?int $parentRunId,
    ): int {
        $this->db->execute(
            "INSERT INTO automation_runs
                (space_id, scenario_id, version_id, trigger_id, event_id, dedup_key,
                 principal_type, principal_id, principal_label, input, status, depth,
                 parent_run_id, queued_at, started_at, created)
             VALUES (%, %, %, %, %, %, %, %, %, %, 'running', %, %, current_timestamp(), current_timestamp(), current_timestamp())",
            [
                $spaceId,
                $scenarioId,
                $versionId,
                $triggerId,
                $eventId,
                $dedupKey,
                $principalType,
                $principalId,
                $principalLabel,
                $input,
                $depth,
                $parentRunId,
            ],
        );

        return $this->db->getLastInsertId();
    }

    public function finishRun(
        int $runId,
        string $status,
        ?string $output,
        ?string $message,
        ?string $errorCode,
        ?string $errorMessage,
        int $stepsCount,
        int $loopIterations,
        int $actionCalls,
        int $durationMs,
    ): void {
        $this->db->execute(
            'UPDATE automation_runs
             SET status = %, finished_at = current_timestamp(), duration_ms = %,
                 steps_count = %, loop_iterations = %, action_calls = %,
                 output = %, message = %, error_code = %, error_message = %
             WHERE id = %',
            [
                $status,
                $durationMs,
                $stepsCount,
                $loopIterations,
                $actionCalls,
                $output,
                $message,
                $errorCode,
                $errorMessage,
                $runId,
            ],
        );
    }

    public function addStep(
        int $runId,
        int $sequence,
        string $kind,
        string $identifier,
        string $status,
        ?string $input,
        ?string $output,
        ?string $errorCode,
        ?string $errorMessage,
        int $spanStartLine,
        int $spanStartColumn,
        int $spanEndLine,
        int $spanEndColumn,
    ): void {
        $this->db->execute(
            'INSERT INTO automation_run_steps
                (run_id, sequence, kind, identifier, status, started_at, duration_ms,
                 input, output, error_code, error_message,
                 span_start_line, span_start_column, span_end_line, span_end_column)
             VALUES (%, %, %, %, %, NULL, NULL, %, %, %, %, %, %, %, %)',
            [
                $runId,
                $sequence,
                $kind,
                $identifier,
                $status,
                $input,
                $output,
                $errorCode,
                $errorMessage,
                $spanStartLine,
                $spanStartColumn,
                $spanEndLine,
                $spanEndColumn,
            ],
        );
    }

    /** @return list<object> */
    public function listRuns(int $scenarioId, int $offset, int $limit): array
    {
        return $this->db->select(
            'SELECT id, space_id, scenario_id, version_id, trigger_id, dedup_key,
                    principal_type, principal_id, principal_label, status, depth, parent_run_id,
                    queued_at, started_at, finished_at, duration_ms, steps_count,
                    loop_iterations, action_calls, error_code, error_message, created
             FROM automation_runs
             WHERE scenario_id = %
             ORDER BY id DESC
             LIMIT %, %',
            [$scenarioId, $offset, $limit],
        );
    }

    public function countRuns(int $scenarioId): int
    {
        $row = $this->db->selectOneOrNull(
            'SELECT COUNT(*) AS total FROM automation_runs WHERE scenario_id = %',
            [$scenarioId],
        );

        return (int) ($row->total ?? 0);
    }

    public function findRunById(int $runId): ?object
    {
        return $this->db->selectOneOrNull(
            'SELECT r.*, s.name AS scenario_name, s.space_id AS scenario_space_id
             FROM automation_runs r
             INNER JOIN automation_scenarios s ON s.id = r.scenario_id
             WHERE r.id = % LIMIT 1',
            [$runId],
        );
    }

    /** @return list<object> */
    public function listRunSteps(int $runId): array
    {
        return $this->db->select(
            'SELECT sequence, kind, identifier, status, duration_ms,
                    error_code, error_message,
                    span_start_line, span_start_column, span_end_line, span_end_column
             FROM automation_run_steps
             WHERE run_id = %
             ORDER BY sequence
             LIMIT 2000',
            [$runId],
        );
    }

    public function createEvent(
        string $eventId,
        int $spaceId,
        string $triggerId,
        ?string $targetScenarioKey,
        string $payload,
        string $principalType,
        ?int $principalId,
        ?string $correlationId,
        int $depth,
        ?string $causationId,
    ): int {
        $this->db->execute(
            "INSERT INTO automation_events
                (event_id, space_id, trigger_id, target_scenario_key, payload, principal_type, principal_id,
                 correlation_id, depth, causation_id, occurred_at, status, created)
             VALUES (%, %, %, %, %, %, %, %, %, %, current_timestamp(), 'pending', current_timestamp())",
            [
                $eventId,
                $spaceId,
                $triggerId,
                $targetScenarioKey,
                $payload,
                $principalType,
                $principalId,
                $correlationId,
                $depth,
                $causationId,
            ],
        );

        return $this->db->getLastInsertId();
    }

    public function findEventByEventId(string $eventId): ?object
    {
        return $this->db->selectOneOrNull(
            'SELECT * FROM automation_events WHERE event_id = % LIMIT 1',
            [$eventId],
        );
    }

    /** Réserve l'événement ; false si un autre drain l'a déjà pris. */
    public function countRecentEvents(int $spaceId, string $triggerId, int $seconds): int
    {
        $row = $this->db->selectOneOrNull(
            'SELECT COUNT(*) AS total FROM automation_events
             WHERE space_id = % AND trigger_id = %
               AND occurred_at >= DATE_SUB(current_timestamp(), INTERVAL % SECOND)',
            [$spaceId, $triggerId, $seconds],
        );

        return (int) ($row->total ?? 0);
    }

    public function countRecentEventsForScenario(
        int $spaceId,
        string $triggerId,
        string $scenarioKey,
        int $seconds,
    ): int {
        $row = $this->db->selectOneOrNull(
            'SELECT COUNT(*) AS total FROM automation_events
             WHERE space_id = % AND trigger_id = % AND target_scenario_key = %
               AND occurred_at >= DATE_SUB(current_timestamp(), INTERVAL % SECOND)',
            [$spaceId, $triggerId, $scenarioKey, $seconds],
        );

        return (int) ($row->total ?? 0);
    }

    /**
     * Runs synchrones du trigger, sans événement d'outbox.
     *
     * Ils complètent le quota horaire des producteurs qui appellent
     * `triggerNow` : sans événement, `countRecentEvents` ne les verrait pas.
     */
    public function countRecentDetachedRuns(int $spaceId, string $triggerId, int $seconds): int
    {
        $row = $this->db->selectOneOrNull(
            'SELECT COUNT(*) AS total FROM automation_runs
             WHERE space_id = % AND trigger_id = % AND event_id IS NULL
               AND created >= DATE_SUB(current_timestamp(), INTERVAL % SECOND)',
            [$spaceId, $triggerId, $seconds],
        );

        return (int) ($row->total ?? 0);
    }

    public function markEventProcessing(int $eventId): bool
    {
        return $this->db->execute(
            'UPDATE automation_events
             SET status = \'processing\', attempts = attempts + 1
             WHERE id = % AND status = \'pending\'',
            [$eventId],
        ) === 1;
    }

    public function markEventProcessed(int $eventId): void
    {
        $this->db->execute(
            'UPDATE automation_events
             SET status = \'processed\', processed_at = current_timestamp(), last_error = NULL
             WHERE id = %',
            [$eventId],
        );
    }

    public function markEventFailed(int $eventId, string $error): void
    {
        $this->db->execute(
            'UPDATE automation_events
             SET status = \'failed\', processed_at = current_timestamp(), last_error = %
             WHERE id = %',
            [$eventId, mb_substr($error, 0, 1000, 'UTF-8')],
        );
    }

    public function findRunByDedup(int $scenarioId, string $dedupKey): ?object
    {
        return $this->db->selectOneOrNull(
            'SELECT * FROM automation_runs WHERE scenario_id = % AND dedup_key = % LIMIT 1',
            [$scenarioId, $dedupKey],
        );
    }

    public function findRunByEventId(int $eventRowId, int $scenarioId): ?object
    {
        return $this->db->selectOneOrNull(
            'SELECT * FROM automation_runs
             WHERE event_id = % AND scenario_id = % ORDER BY id LIMIT 1',
            [$eventRowId, $scenarioId],
        );
    }

    public function findEventById(int $eventId): ?object
    {
        return $this->db->selectOneOrNull(
            'SELECT * FROM automation_events WHERE id = % LIMIT 1',
            [$eventId],
        );
    }

    /** @return list<object> */
    public function findStuckEvents(
        int $spaceId,
        int $pendingSeconds,
        int $processingSeconds,
        int $limit,
    ): array {
        return $this->db->select(
            'SELECT id, event_id, space_id, trigger_id, target_scenario_key,
                    principal_type, principal_id, status, attempts, last_error,
                    occurred_at, processed_at, created
             FROM automation_events
             WHERE space_id = %
               AND (
                   (status = \'pending\'
                       AND occurred_at <= DATE_SUB(current_timestamp(), INTERVAL % SECOND))
                   OR (status = \'processing\'
                       AND occurred_at <= DATE_SUB(current_timestamp(), INTERVAL % SECOND))
               )
             ORDER BY occurred_at, id
             LIMIT %',
            [$spaceId, $pendingSeconds, $processingSeconds, $limit],
        );
    }

    /** @return list<object> */
    public function findStuckRuns(int $spaceId, int $seconds, int $limit): array
    {
        return $this->db->select(
            'SELECT r.id, r.scenario_id, r.trigger_id, r.status, r.queued_at,
                    r.started_at, r.created, s.name AS scenario_name, s.`key` AS scenario_key
             FROM automation_runs r
             INNER JOIN automation_scenarios s ON s.id = r.scenario_id
             WHERE r.space_id = % AND r.status IN (\'queued\', \'running\')
               AND COALESCE(r.started_at, r.queued_at, r.created)
                   <= DATE_SUB(current_timestamp(), INTERVAL % SECOND)
             ORDER BY COALESCE(r.started_at, r.queued_at, r.created), r.id
             LIMIT %',
            [$spaceId, $seconds, $limit],
        );
    }

    /** @return list<object> */
    public function findRecentFailedRuns(int $spaceId, int $limit): array
    {
        return $this->db->select(
            'SELECT r.id, r.scenario_id, r.trigger_id, r.status, r.created, r.finished_at,
                    r.error_code, r.error_message, s.name AS scenario_name, s.`key` AS scenario_key
             FROM automation_runs r
             INNER JOIN automation_scenarios s ON s.id = r.scenario_id
             WHERE r.space_id = % AND r.status IN (\'failed\', \'stopped\')
             ORDER BY r.id DESC
             LIMIT %',
            [$spaceId, $limit],
        );
    }

    /** Remet un événement processing bloqué en pending, une seule fois. */
    public function recoverStuckEvent(int $eventId, int $seconds, int $maxAttempts): bool
    {
        return $this->db->execute(
            'UPDATE automation_events
             SET status = \'pending\', last_error = NULL
             WHERE id = % AND status = \'processing\' AND processed_at IS NULL
               AND attempts < %
               AND occurred_at <= DATE_SUB(current_timestamp(), INTERVAL % SECOND)',
            [$eventId, $maxAttempts, $seconds],
        ) === 1;
    }

    /** Marque un run bloqué en échec, sans écraser un run déjà terminé. */
    public function markStuckRunFailed(int $runId, int $seconds): bool
    {
        return $this->db->execute(
            'UPDATE automation_runs
             SET status = \'failed\', finished_at = current_timestamp(),
                 duration_ms = TIMESTAMPDIFF(
                     SECOND,
                     COALESCE(started_at, queued_at, created),
                     current_timestamp()
                 ) * 1000,
                 error_code = \'AUTOMATION_RUN_STUCK\',
                 error_message = \'Run interrompu : aucun worker ne l’a terminé.\',
                 message = \'Run interrompu.\'
             WHERE id = % AND status IN (\'queued\', \'running\')
               AND COALESCE(started_at, queued_at, created)
                   <= DATE_SUB(current_timestamp(), INTERVAL % SECOND)',
            [$runId, $seconds],
        ) === 1;
    }

    public function countPurgeableEvents(int $spaceId, int $days): int
    {
        $row = $this->db->selectOneOrNull(
            'SELECT COUNT(*) AS total FROM automation_events
             WHERE space_id = % AND status IN (\'processed\', \'failed\')
               AND occurred_at < DATE_SUB(current_timestamp(), INTERVAL % DAY)',
            [$spaceId, $days],
        );

        return (int) ($row->total ?? 0);
    }

    public function countPurgeableRuns(int $spaceId, int $days): int
    {
        $row = $this->db->selectOneOrNull(
            'SELECT COUNT(*) AS total FROM automation_runs
             WHERE space_id = % AND status IN (\'success\', \'failed\', \'stopped\', \'skipped\')
               AND created < DATE_SUB(current_timestamp(), INTERVAL % DAY)',
            [$spaceId, $days],
        );

        return (int) ($row->total ?? 0);
    }

    public function deleteOldEvents(int $spaceId, int $days, int $limit): int
    {
        return $this->db->execute(
            'DELETE FROM automation_events
             WHERE space_id = % AND status IN (\'processed\', \'failed\')
               AND occurred_at < DATE_SUB(current_timestamp(), INTERVAL % DAY)
             ORDER BY id
             LIMIT %',
            [$spaceId, $days, $limit],
        );
    }

    public function deleteOldRuns(int $spaceId, int $days, int $limit): int
    {
        // Les étapes suivent la clé étrangère ON DELETE CASCADE des runs.
        return $this->db->execute(
            'DELETE FROM automation_runs
             WHERE space_id = % AND status IN (\'success\', \'failed\', \'stopped\', \'skipped\')
               AND created < DATE_SUB(current_timestamp(), INTERVAL % DAY)
             ORDER BY id
             LIMIT %',
            [$spaceId, $days, $limit],
        );
    }
}
