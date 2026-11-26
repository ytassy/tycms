<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\JobQueue;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;

/**
 * Lectures et actions d'administration de la file.
 *
 * Le jeton de réservation (`claim`) reste interne : il ne sert à aucun
 * diagnostic et n'est jamais renvoyé à l'interface. Le payload, lui, est
 * lisible : le contrat des handlers interdit d'y placer un secret.
 */
final class JobAdminHelper extends BaseHelper
{
    /** Taille de page fixe : la file se consulte, elle ne s'explore pas. */
    public const PER_PAGE = 25;

    /** Garde-fou de la purge manuelle, en jours. */
    public const PURGE_MAX_DAYS = 365;

    /**
     * @return array{
     *     items: list<array<string, mixed>>,
     *     total: int,
     *     page: int,
     *     perPage: int,
     *     types: list<string>,
     *     counts: array<string, int>
     * }
     */
    public function list(?string $status, ?string $type, ?int $spaceId, int $page): array
    {
        $clauses = [];
        $values = [];

        if ($status !== null) {
            $clauses[] = 'status = %';
            $values[] = $status;
        }

        if ($type !== null) {
            $clauses[] = 'type = %';
            $values[] = $type;
        }

        if ($spaceId !== null) {
            $clauses[] = 'space_id = %';
            $values[] = $spaceId;
        }

        $where = $clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses);
        $total = (int) $this->db->selectOneOrNull('SELECT COUNT(*) AS total FROM jobs' . $where, $values)->total;
        $offset = max(0, ($page - 1) * self::PER_PAGE);

        // La liste ne charge pas le payload : elle doit rester légère même avec
        // des jobs volumineux. Le détail le lit pour un seul job.
        $rows = $this->db->select(
            'SELECT id, space_id, type, producer, status, priority, attempts, max_attempts,
                    principal, exec_user_id, created_by, dedup_key, available_at, reserved_until,
                    cancel_requested, last_error, created, started_at, finished_at, duration_ms
               FROM jobs' . $where . '
              ORDER BY id DESC
              LIMIT ' . self::PER_PAGE . ' OFFSET ' . $offset,
            $values,
        );

        return [
            'items' => array_map($this->present(...), $rows),
            'total' => $total,
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'types' => $this->types(),
            'counts' => $this->counts(),
        ];
    }

    /** @return array<string, mixed>|null */
    public function detail(int $jobId): ?array
    {
        $row = $this->db->selectOneOrNull('SELECT * FROM jobs WHERE id = % LIMIT 1', [$jobId]);

        if ($row === null) {
            return null;
        }

        $payload = JobPayload::decode((string) $row->payload);

        return $this->present($row) + [
            'payload' => $payload,
            // Le texte brut reste affichable si le JSON est invalide, cas qu'un
            // job en échec peut laisser derrière lui.
            'payloadText' => (string) $row->payload,
        ];
    }

    /**
     * Remet un job terminal en attente, avec un compteur neuf.
     *
     * Seuls les états définitifs sont relançables : un job déjà `pending` ou
     * `processing` n'a pas à être dupliqué par une action d'administration.
     */
    public function retry(int $jobId): bool
    {
        return $this->db->execute(
            "UPDATE jobs
                SET status = 'pending', attempts = 0, claim = NULL, reserved_until = NULL,
                    last_error = NULL, available_at = now(), started_at = NULL,
                    finished_at = NULL, duration_ms = NULL
              WHERE id = % AND status IN ('failed', 'cancelled')",
            [$jobId],
        ) > 0;
    }

    /** Supprime les jobs terminés plus anciens que le délai demandé. */
    public function purge(int $days): int
    {
        $days = max(1, min(self::PURGE_MAX_DAYS, $days));

        return $this->db->execute(
            "DELETE FROM jobs
              WHERE status IN ('completed', 'failed', 'cancelled')
                AND finished_at < DATE_SUB(now(), INTERVAL {$days} DAY)
              LIMIT 500",
        );
    }

    /** @return array{lastRunAt: string|null, lastSource: string, lastRan: int, lastBudgetMs: int, lastPurgeAt: string|null} */
    public function workerState(): array
    {
        $row = $this->db->selectOneOrNull(
            'SELECT last_run_at, last_source, last_ran, last_budget_ms, last_purge_at
               FROM job_worker_state
              WHERE id = 1
              LIMIT 1',
        );

        return [
            'lastRunAt' => $row?->last_run_at,
            'lastSource' => (string) ($row->last_source ?? ''),
            'lastRan' => (int) ($row->last_ran ?? 0),
            'lastBudgetMs' => (int) ($row->last_budget_ms ?? 0),
            'lastPurgeAt' => $row?->last_purge_at,
        ];
    }

    /** @return list<string> */
    private function types(): array
    {
        $rows = $this->db->select('SELECT DISTINCT type FROM jobs ORDER BY type LIMIT 200');

        return array_map(static fn (object $row): string => (string) $row->type, $rows);
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $rows = $this->db->select('SELECT status, COUNT(*) AS total FROM jobs GROUP BY status');
        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row->status] = (int) $row->total;
        }

        return $counts;
    }

    /** @return array<string, mixed> */
    private function present(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'spaceId' => $row->space_id === null ? null : (int) $row->space_id,
            'type' => (string) $row->type,
            'producer' => (string) $row->producer,
            'status' => (string) $row->status,
            'priority' => (int) $row->priority,
            'attempts' => (int) $row->attempts,
            'maxAttempts' => (int) $row->max_attempts,
            'principal' => (string) $row->principal,
            'execUserId' => $row->exec_user_id === null ? null : (int) $row->exec_user_id,
            'createdBy' => $row->created_by === null ? null : (int) $row->created_by,
            'dedupKey' => $row->dedup_key,
            'availableAt' => $row->available_at,
            'reservedUntil' => $row->reserved_until,
            'cancelRequested' => (int) $row->cancel_requested === 1,
            'lastError' => $row->last_error,
            'created' => $row->created,
            'startedAt' => $row->started_at,
            'finishedAt' => $row->finished_at,
            'durationMs' => $row->duration_ms === null ? null : (int) $row->duration_ms,
        ];
    }
}
