<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\JobQueue;

use Throwable;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Job\JobContext;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Job\JobResult;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Job\JobType;
use YTassy\Tycms\DB\FormatIn;
use YTassy\Tycms\Session\RunSession;

/**
 * Worker de la file de jobs.
 *
 * Un seul worker à la fois : un verrou nommé non bloquant fait office de
 * mutualisation, et l'appelant qui le trouve pris s'arrête immédiatement. La
 * correction repose ensuite sur la réservation ligne à ligne et sur un bail,
 * jamais sur une attente de verrou.
 *
 * Le tick est borné en durée et en nombre de jobs. Un job n'est réservé que si
 * le budget restant couvre son `min_time` : un handler lent ne bloque pas les
 * suivants, et un job trop long attend un tick plus large.
 */
final class JobWorker extends BaseHelper
{
    public const SOURCE_CRON = 'cron';
    public const SOURCE_TRAFFIC = 'traffic';
    public const SOURCE_ADMIN = 'admin';

    /** Budget du CRON de la file : un passage par minute sur mutualisé. */
    public const CRON_BUDGET_SECONDS = 30;

    /**
     * Politique de temps par porte d'entrée.
     *
     * Le trafic reste court parce qu'il s'exécute sur une requête déclenchée
     * par un visiteur. Le CRON et l'administration peuvent voir plus grand.
     * Chaque valeur est ensuite bornée par la limite réelle du serveur.
     */
    public const TRAFFIC_BUDGET_SECONDS = 5;

    /** Exécution manuelle depuis l'administration : plus large, mais bornée. */
    public const ADMIN_BUDGET_SECONDS = 20;

    /**
     * Marge laissée sous la limite du serveur, en secondes.
     *
     * Elle couvre la fin de requête : libération du verrou, écriture du
     * battement, envoi de la réponse.
     */
    public const SAFETY_MARGIN_SECONDS = 10;

    /** Budget minimal : même sous une limite serveur serrée, un job doit pouvoir démarrer. */
    public const MIN_BUDGET_SECONDS = 3;

    /**
     * Garde-fou de boucle, jamais un plafond fonctionnel.
     *
     * Le temps borne chaque passage ; ce nombre évite seulement qu'une file de
     * milliers de jobs instantanés monopolise la requête.
     */
    public const SAFETY_MAX_JOBS = 200;

    /** Fenêtre de candidats lue en une requête. */
    private const SCAN_WINDOW = 20;

    /** Marge ajoutée au `min_time` pour fixer le bail. */
    private const LEASE_MARGIN_SECONDS = 15;

    /** Rétention des jobs terminés. */
    public const RETENTION_DAYS = 14;

    /** Intervalle minimal entre deux purges. */
    private const PURGE_INTERVAL_SECONDS = 3600;

    private const PURGE_BATCH = 500;

    private const RECOVERY_BATCH = 100;

    /** Durée maximale d'un message d'erreur stocké. */
    private const MAX_ERROR_LENGTH = 500;

    /** @var array<string, Application> Applications isolées par principal et par Space. */
    private array $applications = [];

    /**
     * Traite les jobs dus dans la limite du budget de la porte d'entrée.
     *
     * @return array{
     *     ran: int,
     *     completed: int,
     *     failed: int,
     *     deferred: int,
     *     unavailable: int,
     *     recovered: int,
     *     purged: int,
     *     busy: bool,
     *     duration_ms: int
     * }
     */
    public function run(string $source = self::SOURCE_CRON): array
    {
        $startedAt = microtime(true);
        $policy = match ($source) {
            self::SOURCE_TRAFFIC => self::TRAFFIC_BUDGET_SECONDS,
            self::SOURCE_ADMIN => self::ADMIN_BUDGET_SECONDS,
            default => self::CRON_BUDGET_SECONDS,
        };
        $budget = self::clampBudget($policy, (int) ini_get('max_execution_time'));
        $deadline = $startedAt + $budget;

        $summary = [
            'ran' => 0,
            'completed' => 0,
            'failed' => 0,
            'deferred' => 0,
            'unavailable' => 0,
            'recovered' => 0,
            'purged' => 0,
            'busy' => false,
            'duration_ms' => 0,
        ];

        // Une contention de ligne ne doit jamais faire patienter la requête :
        // le verrou nommé évite déjà deux workers, cette borne couvre le reste.
        try {
            $this->db->execute('SET SESSION innodb_lock_wait_timeout = 2');
        } catch (Throwable) {
            // Variable de session refusée : le worker reste utilisable.
        }

        $this->applyExecutionLimit($budget);

        if (!$this->acquireLock()) {
            $summary['busy'] = true;
            $summary['duration_ms'] = (int) round((microtime(true) - $startedAt) * 1000);

            return $summary;
        }

        try {
            $summary['recovered'] = $this->recoverExpiredLeases();
            $summary['purged'] = $this->purgeIfDue();
            $this->drain($summary, $deadline);
        } finally {
            $this->releaseLock();

            try {
                $this->writeHeartbeat($source, $summary, (int) round((microtime(true) - $startedAt) * 1000));
            } catch (Throwable) {
                // Le battement est un diagnostic : il ne doit pas masquer le
                // résultat d'un tick déjà exécuté.
            }
        }

        $summary['duration_ms'] = (int) round((microtime(true) - $startedAt) * 1000);

        if ($summary['ran'] > 0 || $summary['failed'] > 0 || $summary['unavailable'] > 0) {
            $this->logInfo(
                'Worker de la file terminé.',
                $summary,
                'JOB_WORKER_SUMMARY',
                'jobs',
            );
        }

        // Un job dû mais non démarré faute de budget n'est pas une anomalie
        // courante : il est repris au tick suivant, et signalé s'il persiste.
        if ($summary['deferred'] > 0) {
            $this->logWarning(
                'Le worker de la file a différé des jobs dus.',
                $summary,
                'JOB_WORKER_BUDGET',
                'jobs',
            );
        }

        return $summary;
    }

    /**
     * @param array<string, int|bool> $summary
     */
    private function drain(array &$summary, float $deadline): void
    {
        /** @var list<int> $skipped Identifiants écartés pour ce tick (budget insuffisant). */
        $skipped = [];

        while ($summary['ran'] < self::SAFETY_MAX_JOBS) {
            $remaining = $deadline - microtime(true);

            // Sous la plus courte durée déclarée, aucun type ne peut démarrer.
            if ($remaining < JobType::MIN_TIME_MIN) {
                break;
            }

            $candidates = $this->findCandidates($skipped);

            if ($candidates === []) {
                break;
            }

            $claimed = false;

            foreach ($candidates as $candidate) {
                $jobType = $this->jobType((string) $candidate->type);

                // Un type retiré (extension désactivée) ne reviendra pas : le
                // marquer en échec évite qu'il occupe la tête de file.
                if ($jobType === null) {
                    $this->failUnavailableType((int) $candidate->id, (string) $candidate->type);
                    $summary['unavailable']++;
                    continue;
                }

                if ($jobType->minTime > $remaining) {
                    $skipped[] = (int) $candidate->id;
                    $summary['deferred']++;
                    continue;
                }

                $job = $this->claim((int) $candidate->id, $jobType);

                // Un autre a réservé la ligne, ou un producteur l'a annulée.
                if ($job === null) {
                    continue;
                }

                $claimed = true;
                $this->runJob($job, $jobType, $summary);
                break;
            }

            // Plus rien de réservable dans la fenêtre : inutile de boucler.
            if (!$claimed) {
                break;
            }
        }
    }

    /**
     * @param list<int> $skipped
     *
     * @return list<object>
     */
    private function findCandidates(array $skipped): array
    {
        $exclusion = $skipped === [] ? '' : ' AND id NOT IN (%)';
        $values = $skipped === [] ? [] : [FormatIn::get($skipped)];

        return $this->db->select(
            "SELECT id, type
               FROM jobs
              WHERE status = 'pending' AND available_at <= now()"
                . $exclusion
                . ' ORDER BY priority DESC, available_at, id
              LIMIT ' . self::SCAN_WINDOW,
            $values,
        );
    }

    /**
     * Réserve une ligne de façon atomique.
     *
     * Le jeton de réservation permet de refuser une écriture finale si le bail
     * a expiré entre-temps et que la ligne a été reprise ailleurs.
     */
    private function claim(int $jobId, JobType $jobType): ?object
    {
        $claim = bin2hex(random_bytes(16));
        $lease = $jobType->minTime + self::LEASE_MARGIN_SECONDS;

        try {
            $affected = $this->db->execute(
                "UPDATE jobs
                    SET status = 'processing', claim = %,
                        reserved_until = DATE_ADD(now(), INTERVAL % SECOND),
                        attempts = attempts + 1, started_at = now()
                  WHERE id = % AND status = 'pending' AND available_at <= now()",
                [$claim, $lease, $jobId],
            );
        } catch (Throwable $exception) {
            // Contention ou blocage résiduel : la ligne sera retentée au tick
            // suivant, sans interrompre le reste du lot.
            $this->logWarning(
                'La réservation d’un job a échoué.',
                ['job_id' => $jobId],
                'JOB_CLAIM_FAILED',
                'jobs',
            );
            $this->logException($exception);

            return null;
        }

        if ($affected === 0) {
            return null;
        }

        return $this->db->selectOneOrNull(
            'SELECT * FROM jobs WHERE id = % AND claim = % LIMIT 1',
            [$jobId, $claim],
        );
    }

    /**
     * @param array<string, int|bool> $summary
     */
    private function runJob(object $job, JobType $jobType, array &$summary): void
    {
        $summary['ran']++;
        $jobId = (int) $job->id;
        $payload = JobPayload::decode((string) $job->payload);

        if ($payload === null) {
            $this->finishFinal($job, 'Payload de job illisible.');
            $summary['failed']++;
            $this->logWarning(
                'Un job porte un payload illisible.',
                ['job_id' => $jobId, 'type' => $jobType->id],
                'JOB_PAYLOAD_INVALID',
                'jobs',
            );

            return;
        }

        $context = new JobContext(
            $jobId,
            $jobType->id,
            $job->space_id === null ? null : (int) $job->space_id,
            (string) $job->principal,
            $job->exec_user_id === null ? null : (int) $job->exec_user_id,
            (string) $job->producer,
            (int) $job->attempts,
            (int) $job->max_attempts,
        );

        $startedAt = microtime(true);

        try {
            $handlerClass = $jobType->handlerClass;
            $handler = new $handlerClass($this->executionApplication($job));
            /** @var JobResult $result */
            $result = $handler->handle($context, $payload);
            $success = $result->success;
            $message = $result->message;
        } catch (JobPrincipalException) {
            // Rejouer ne servirait à rien tant que le compte est absent ou
            // bloqué : échec définitif, sans consommer les tentatives restantes.
            $this->finishFinal($job, 'Principal d’exécution indisponible.');
            $summary['failed']++;
            $this->logWarning(
                'Le principal d’un job n’est plus disponible.',
                ['job_id' => $jobId, 'type' => $jobType->id],
                'JOB_PRINCIPAL_UNAVAILABLE',
                'jobs',
            );

            return;
        } catch (Throwable $exception) {
            $success = false;
            $message = $exception->getMessage();
            $this->logError(
                'Le handler du job a levé une exception.',
                ['job_id' => $jobId, 'type' => $jobType->id],
                'JOB_FAILED',
                'jobs',
            );
            $this->logException($exception);
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        if ($success) {
            $this->finishSuccess($job, $durationMs);
            $summary['completed']++;

            return;
        }

        $summary['failed']++;
        $this->finishFailure($job, $message, $durationMs);
    }

    private function finishSuccess(object $job, int $durationMs): void
    {
        $this->db->execute(
            "UPDATE jobs
                SET status = 'completed', finished_at = now(), duration_ms = %, last_error = NULL
              WHERE id = % AND claim = %",
            [$durationMs, (int) $job->id, (string) $job->claim],
        );
    }

    private function finishFailure(object $job, string $message, int $durationMs): void
    {
        $attempts = (int) $job->attempts;
        $maxAttempts = (int) $job->max_attempts;

        // Une annulation demandée pendant l'exécution est honorée ici : inutile
        // de replanifier un job que l'administrateur a interrompu. La valeur est
        // relue, celle du moment de la réservation serait périmée.
        if ($this->isCancelRequested((int) $job->id)) {
            $this->finishCancelled($job, $message);
            $this->logInfo(
                'Job annulé après demande d’arrêt.',
                ['job_id' => (int) $job->id],
                'JOB_CANCELLED',
                'jobs',
            );

            return;
        }

        if ($attempts >= $maxAttempts) {
            $this->finishFinal($job, $message);
            $this->logWarning(
                'Job en échec définitif.',
                ['job_id' => (int) $job->id, 'attempts' => $attempts],
                'JOB_FAILED_FINAL',
                'jobs',
            );

            return;
        }

        $delay = JobBackoff::seconds($attempts);

        $this->db->execute(
            "UPDATE jobs
                SET status = 'pending', claim = NULL, reserved_until = NULL,
                    available_at = DATE_ADD(now(), INTERVAL % SECOND),
                    duration_ms = %, last_error = %
              WHERE id = % AND claim = %",
            [$delay, $durationMs, $this->truncate($message), (int) $job->id, (string) $job->claim],
        );
    }

    private function isCancelRequested(int $jobId): bool
    {
        $row = $this->db->selectOneOrNull(
            'SELECT cancel_requested FROM jobs WHERE id = % LIMIT 1',
            [$jobId],
        );

        return $row !== null && (int) $row->cancel_requested === 1;
    }

    private function finishFinal(object $job, string $message): void
    {
        $this->db->execute(
            "UPDATE jobs
                SET status = 'failed', finished_at = now(), claim = NULL, reserved_until = NULL,
                    last_error = %
              WHERE id = % AND claim = %",
            [$this->truncate($message), (int) $job->id, (string) $job->claim],
        );
    }

    /** Un job interrompu à la demande de l'administrateur n'est pas un échec. */
    private function finishCancelled(object $job, string $message): void
    {
        $this->db->execute(
            "UPDATE jobs
                SET status = 'cancelled', finished_at = now(), claim = NULL, reserved_until = NULL,
                    last_error = %
              WHERE id = % AND claim = %",
            [$this->truncate($message), (int) $job->id, (string) $job->claim],
        );
    }

    private function failUnavailableType(int $jobId, string $type): void
    {
        $this->db->execute(
            "UPDATE jobs
                SET status = 'failed', finished_at = now(), claim = NULL, reserved_until = NULL,
                    last_error = 'Type de job indisponible.'
              WHERE id = % AND status = 'pending'",
            [$jobId],
        );

        $this->logWarning(
            'Un job référence un type qui n’est plus fourni.',
            ['job_id' => $jobId, 'type' => $type],
            'JOB_TYPE_UNAVAILABLE',
            'jobs',
        );
    }

    /**
     * Ouvre l'Application isolée du principal, puis la réutilise pour les jobs
     * qui partagent le même principal et le même Space.
     *
     * Une seule Application par principal : un lot de vingt jobs coûte au plus
     * deux initialisations, sauf s'il mélange vingt utilisateurs distincts.
     */
    private function executionApplication(object $job): Application
    {
        $principal = (string) $job->principal;
        $execUserId = $job->exec_user_id === null ? null : (int) $job->exec_user_id;
        $spaceId = $job->space_id === null ? null : (int) $job->space_id;

        if ($principal === JobQueue::PRINCIPAL_USER && $execUserId === null) {
            throw new JobPrincipalException('Principal utilisateur sans identifiant.');
        }

        $key = $principal . ':' . ($execUserId ?? '') . ':' . ($spaceId ?? '');

        if (isset($this->applications[$key])) {
            return $this->applications[$key];
        }

        $userId = match ($principal) {
            JobQueue::PRINCIPAL_SYSTEM => $this->getSharedExtensionsClass(SystemAccount::class)->id(),
            JobQueue::PRINCIPAL_USER => $execUserId,
            default => null,
        };

        if ($userId !== null) {
            $this->assertPrincipalAvailable($userId);
        }

        // La configuration est clonée : la file ne modifie jamais celle de la
        // requête courante, et un run n'écrase pas l'état du site.
        $configuration = clone $this->app->getConf();
        $configuration->RESET_DATABASE = false;

        $application = new Application($configuration);
        $session = new RunSession($application, $userId, $spaceId);
        $application->initialiseOrThrow(null, false, $session, false);

        return $this->applications[$key] = $application;
    }

    private function assertPrincipalAvailable(int $userId): void
    {
        $row = $this->db->selectOneOrNull(
            'SELECT blocked FROM users WHERE id = % LIMIT 1',
            [$userId],
        );

        if ($row === null || (int) $row->blocked === 1) {
            throw new JobPrincipalException('Utilisateur absent ou bloqué.');
        }
    }

    private function recoverExpiredLeases(): int
    {
        $recovered = $this->db->execute(
            "UPDATE jobs
                SET status = 'pending', claim = NULL, reserved_until = NULL,
                    last_error = 'Bail expiré sans fin d’exécution.'
              WHERE status = 'processing' AND reserved_until < now()
              LIMIT " . self::RECOVERY_BATCH,
        );

        if ($recovered > 0) {
            $this->logWarning(
                'Des jobs abandonnés ont été remis en attente.',
                ['count' => $recovered],
                'JOB_LEASE_EXPIRED',
                'jobs',
            );
        }

        return $recovered;
    }

    private function purgeIfDue(): int
    {
        $state = $this->db->selectOneOrNull(
            'SELECT last_purge_at FROM job_worker_state WHERE id = 1 LIMIT 1',
        );

        $lastPurge = $state->last_purge_at ?? null;

        if ($lastPurge !== null && strtotime((string) $lastPurge) > time() - self::PURGE_INTERVAL_SECONDS) {
            return 0;
        }

        $days = self::RETENTION_DAYS;
        $batch = self::PURGE_BATCH;

        $deleted = $this->db->execute(
            "DELETE FROM jobs
              WHERE status IN ('completed', 'failed', 'cancelled')
                AND finished_at < DATE_SUB(now(), INTERVAL {$days} DAY)
              LIMIT {$batch}",
        );

        $this->db->execute('UPDATE job_worker_state SET last_purge_at = now() WHERE id = 1');

        return $deleted;
    }

    /**
     * @param array<string, int|bool> $summary
     */
    private function writeHeartbeat(string $source, array $summary, int $durationMs): void
    {
        $this->db->execute(
            'UPDATE job_worker_state
                SET last_run_at = now(), last_source = %, last_ran = %, last_budget_ms = %
              WHERE id = 1',
            [$source, $summary['ran'], $durationMs],
        );
    }

    private function acquireLock(): bool
    {
        $row = $this->db->selectOneOrNull('SELECT GET_LOCK(%, 0) AS locked', [$this->lockName()]);

        return $row !== null && (int) $row->locked === 1;
    }

    private function releaseLock(): void
    {
        try {
            $this->db->selectOneOrNull('SELECT RELEASE_LOCK(%) AS released', [$this->lockName()]);
        } catch (Throwable) {
            // La libération est un confort : le verrou tombe avec la connexion.
        }
    }

    /**
     * Le nom dépend de la base : sur un mutualisé, plusieurs installations
     * peuvent partager le même serveur MariaDB sans se sérialiser entre elles.
     */
    private function lockName(): string
    {
        $database = (string) ($this->app->getConf()->db_dbname ?? '');

        return 'tycms_job_' . substr(sha1($database), 0, 32);
    }

    private function jobType(string $type): ?JobType
    {
        /** @var JobTypeRegistry $registry */
        $registry = $this->getSharedExtensionsClass(JobTypeRegistry::class);

        return $registry->get($type);
    }

    /**
     * Budget effectif d'un passage.
     *
     * La politique de la porte d'entrée est bornée par la limite PHP déclarée,
     * moins une marge de fin de requête. Une limite nulle (0) signifie
     * « illimitée » : la politique s'applique seule.
     *
     * Cette limite exprime un temps processeur, pas un temps écoulé : elle sert
     * de plafond, jamais de mesure. Le worker mesure le temps réel.
     */
    public static function clampBudget(
        int $policySeconds,
        int $serverSeconds,
        int $marginSeconds = self::SAFETY_MARGIN_SECONDS,
    ): int {
        if ($serverSeconds <= 0) {
            return $policySeconds;
        }

        return max(
            self::MIN_BUDGET_SECONDS,
            min($policySeconds, $serverSeconds - $marginSeconds),
        );
    }

    /**
     * Impose une limite PHP cohérente avec le budget du passage.
     *
     * Elle peut l'abaisser comme la relever : c'est la limite du passage, pas
     * une extension. Indispensable quand le serveur déclare `0` (illimité),
     * car il ne reste alors que cette borne. `set_time_limit` est souvent
     * neutralisé sur mutualisé : l'appel reste une protection, jamais une
     * garantie.
     */
    private function applyExecutionLimit(int $budgetSeconds): void
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        if (!in_array('set_time_limit', $disabled, true)) {
            set_time_limit($budgetSeconds + self::SAFETY_MARGIN_SECONDS);
        }
    }

    private function truncate(string $message): string
    {
        $message = trim(preg_replace('/\s+/u', ' ', $message) ?? $message);

        // mbstring n'est pas une dépendance déclarée du projet : la troncature
        // par caractères est préférée, mais son absence ne doit pas être fatale.
        return function_exists('mb_substr')
            ? mb_substr($message, 0, self::MAX_ERROR_LENGTH)
            : substr($message, 0, self::MAX_ERROR_LENGTH);
    }
}
