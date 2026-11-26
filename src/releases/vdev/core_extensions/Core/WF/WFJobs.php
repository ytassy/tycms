<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\WF;

use DateTimeImmutable;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Job\JobType;
use YTassy\Tycms\CoreExts\Core\JobQueue\JobPayload;
use YTassy\Tycms\CoreExts\Core\JobQueue\JobWorker;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\CoreExts\Core\Helper\TraitAllHelpers;
use YTassy\Tycms\Exceptions\AccessDeniedException;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\WF\BaseWF;

/**
 * Administration de la file de jobs.
 *
 * Écran réservé à ROOT : la file montre le travail de toute l'installation, y
 * compris des payloads de référence. Les actions sont journalisées, car elles
 * modifient l'exécution future de traitements métier.
 */
final class WFJobs extends BaseWF
{
    use TraitAllHelpers;

    /** Un worker sans passage depuis ce délai est considéré comme arrêté. */
    public const STALE_SECONDS = 300;

    private const STATUSES = ['pending', 'processing', 'completed', 'failed', 'cancelled'];

    /**
     * @return array<string, mixed>
     */
    public function list(?string $status, ?string $type, ?int $spaceId, int $page): array
    {
        $this->assertRoot();

        // Une valeur inconnue n'est pas une erreur : elle est ignorée pour ne
        // pas casser l'écran quand un filtre obsolète reste dans l'URL.
        $status = in_array($status, self::STATUSES, true) ? $status : null;
        $type = $type !== null && ($type === '' || strlen($type) > 100) ? null : $type;
        $spaceId = $spaceId !== null && $spaceId <= 0 ? null : $spaceId;

        $result = $this->coreJobAdminHelper->list($status, $type, $spaceId, max(1, $page));
        $state = $this->coreJobAdminHelper->workerState();

        // Le battement est la seule preuve qu'un second CRON est configuré.
        $result['worker'] = $state + [
            'stale' => $this->isStale($state['lastRunAt']),
        ];

        return $result;
    }

    /** @return array<string, mixed> */
    public function detail(int $jobId): array
    {
        $this->assertRoot();

        $job = $this->coreJobAdminHelper->detail($jobId);

        if ($job === null) {
            ExceptionResponse::throwWarning('Job introuvable.');
        }

        return $job;
    }

    public function retry(int $jobId): bool
    {
        $this->assertRoot();

        if (!$this->coreJobAdminHelper->retry($jobId)) {
            ExceptionResponse::throwWarning('Ce job n’est pas dans un état relançable.');
        }

        $this->logInfo(
            'Job relancé manuellement.',
            ['job_id' => $jobId],
            'JOB_ADMIN_RETRY',
            'jobs',
        );

        return true;
    }

    public function cancel(int $jobId): bool
    {
        $this->assertRoot();

        /** @var \YTassy\Tycms\CoreExts\Core\JobQueue\JobQueue $queue */
        $queue = $this->getSharedExtensionsClass(\YTassy\Tycms\CoreExts\Core\JobQueue\JobQueue::class);

        if (!$queue->cancel($jobId)) {
            ExceptionResponse::throwWarning('Ce job est déjà terminé.');
        }

        $this->logInfo(
            'Job annulé manuellement.',
            ['job_id' => $jobId],
            'JOB_ADMIN_CANCEL',
            'jobs',
        );

        return true;
    }

    public function purge(int $days): int
    {
        $this->assertRoot();

        $days = max(1, min(\YTassy\Tycms\CoreExts\Core\JobQueue\JobAdminHelper::PURGE_MAX_DAYS, $days));
        $deleted = $this->coreJobAdminHelper->purge($days);

        $this->logInfo(
            'Purge manuelle des jobs terminés.',
            ['days' => $days, 'deleted' => $deleted],
            'JOB_ADMIN_PURGE',
            'jobs',
        );

        return $deleted;
    }

    /**
     * Valeurs réelles de configuration, pour l'onglet Documentation.
     *
     * Aucun secret n'est renvoyé : seulement la présence d'un jeton.
     *
     * @return array<string, mixed>
     */
    public function documentation(): array
    {
        $this->assertRoot();
        $conf = $this->app->getConf();
        $state = $this->coreJobAdminHelper->workerState();

        return [
            'cron' => [
                'path' => '/cron',
                'field' => 'cron_token',
                'configured' => (string) ($conf->cron_token ?? '') !== '',
            ],
            'queue' => [
                'path' => '/queue-worker',
                'field' => 'job_worker_token',
                'configured' => (string) ($conf->job_worker_token ?? '') !== '',
            ],
            'trafficTrigger' => ($conf->job_traffic_trigger ?? false) === true,
            'worker' => $state + ['stale' => $this->isStale($state['lastRunAt'])],
            'limits' => [
                'cronJobs' => JobWorker::CRON_MAX_JOBS,
                'cronSeconds' => JobWorker::CRON_BUDGET_SECONDS,
                'trafficJobs' => JobWorker::TRAFFIC_MAX_JOBS,
                'trafficSeconds' => JobWorker::TRAFFIC_BUDGET_SECONDS,
                'retentionDays' => JobWorker::RETENTION_DAYS,
                'minTimeMax' => JobType::MIN_TIME_MAX,
                'payloadBytes' => JobPayload::MAX_BYTES,
            ],
        ];
    }

    private function assertRoot(): void
    {
        if (!$this->coreAclHelper->isRoot()) {
            throw new AccessDeniedException();
        }
    }

    private function isStale(?string $lastRunAt): bool
    {
        if ($lastRunAt === null) {
            return true;
        }

        $timestamp = strtotime($lastRunAt);

        return $timestamp === false || $timestamp < time() - self::STALE_SECONDS;
    }
}
