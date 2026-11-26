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
            'stale' => $this->isStale($state['lastRunAgeSeconds']),
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
     * Lance un passage du worker sans attendre le CRON ni une visite.
     *
     * Borné comme les autres déclencheurs ; utile pour vérifier une
     * installation ou drainer une file après un correctif.
     *
     * @return array<string, mixed>
     */
    public function run(): array
    {
        $this->assertRoot();

        /** @var JobWorker $worker */
        $worker = $this->getSharedExtensionsClass(JobWorker::class);
        $summary = $worker->run(JobWorker::SOURCE_ADMIN);

        $this->logInfo(
            'Worker de la file lancé manuellement.',
            ['ran' => $summary['ran'], 'failed' => $summary['failed']],
            'JOB_ADMIN_RUN',
            'jobs',
        );

        return $summary;
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
        $serverSeconds = (int) ini_get('max_execution_time');

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
            'worker' => $state + ['stale' => $this->isStale($state['lastRunAgeSeconds'])],
            // Les budgets affichés sont les budgets effectifs, déjà bornés par
            // la limite PHP de l'hébergement : c'est ce qui se passe vraiment.
            'limits' => [
                'cronSeconds' => JobWorker::clampBudget(JobWorker::CRON_BUDGET_SECONDS, $serverSeconds),
                'trafficSeconds' => JobWorker::clampBudget(JobWorker::TRAFFIC_BUDGET_SECONDS, $serverSeconds),
                'adminSeconds' => JobWorker::clampBudget(JobWorker::ADMIN_BUDGET_SECONDS, $serverSeconds),
                'serverSeconds' => $serverSeconds,
                'marginSeconds' => JobWorker::SAFETY_MARGIN_SECONDS,
                'maxJobs' => JobWorker::SAFETY_MAX_JOBS,
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

    /**
     * Un passage est périmé au-delà du seuil, l'âge venant de la base.
     *
     * Aucune conversion de fuseau ici : comparer une heure SQL à `time()` en
     * PHP ferait croire à un worker arrêté dès que les deux horloges diffèrent.
     */
    private function isStale(?int $ageSeconds): bool
    {
        return $ageSeconds === null || $ageSeconds > self::STALE_SECONDS;
    }
}
