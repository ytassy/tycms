<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Integration;

use PHPUnit\Framework\TestCase;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Job\BaseJobHandler;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Job\JobContext;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Job\JobRequest;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Job\JobResult;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Job\JobType;
use YTassy\Tycms\CoreExts\Core\JobQueue\JobQueue;
use YTassy\Tycms\CoreExts\Core\JobQueue\JobTypeRegistry;
use YTassy\Tycms\CoreExts\Core\JobQueue\JobWorker;
use YTassy\Tycms\DB\Database;
use YTassy\Tycms\Request\Request;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Session\Session;
use YTassy\Tycms\Tests\Support\Integration\TestDatabase;
use YTassy\Tycms\Tests\Support\Integration\TestEnvironment;
use YTassy\Tycms\Tests\Support\Integration\TestFixtures;

/**
 * File de jobs réelle sur la base isolée.
 *
 * Chaque scénario tourne dans une transaction annulée : rien ne survit au test,
 * et les handlers de test n'écrivent aucune donnée métier.
 */
final class JobQueueIntegrationTest extends TestCase
{
    private Application $app;
    private Database $db;
    private JobQueue $queue;
    private JobWorker $worker;

    protected function setUp(): void
    {
        $environment = TestEnvironment::load();
        TestFixtures::assertPrepared(new TestDatabase($environment));

        $this->app = new Application($environment->configuration());

        $session = $this->createStub(Session::class);
        $session->method('getUserId')->willReturn(1);
        $session->method('getSpaceId')->willReturn(1);

        $this->app->initialiseOrThrow(
            new Request(method: RequestMethod::GET, uri: '/'),
            initializeSession: false,
            session: $session,
            registerRoutes: false,
        );

        $this->db = $this->app->getDatabase();
        $this->db->execute('START TRANSACTION');

        // Les autres tests d'intégration committent leurs propres écritures :
        // les messages de bienvenue qu'ils enfilent resteraient dans cette base
        // partagée et seraient drainés par le worker de ce test. Chaque
        // scénario part donc d'une file vide, restaurée par le rollback.
        $this->db->execute('DELETE FROM jobs');

        // Types de test déclarés dans le registre partagé de l'application.
        /** @var JobTypeRegistry $registry */
        $registry = $this->app->getOrAddSharedExtensionsClass(JobTypeRegistry::class);
        $registry->register(new JobType('plg_test.noop', 'Test sans effet', NoopJobHandler::class, 1, 1));
        $registry->register(new JobType('plg_test.failing', 'Test en échec', FailingJobHandler::class, 1, 2));

        /** @var JobQueue $queue */
        $queue = $this->app->getOrAddSharedExtensionsClass(JobQueue::class);
        $this->queue = $queue;

        /** @var JobWorker $worker */
        $worker = $this->app->getOrAddSharedExtensionsClass(JobWorker::class);
        $this->worker = $worker;
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->execute('ROLLBACK');
        }
    }

    public function testWorkerRunsADueJobToCompletion(): void
    {
        $jobId = $this->queue->enqueue(new JobRequest('plg_test.noop', [], ['space_id' => 1]));

        $summary = $this->worker->run(JobWorker::SOURCE_CRON);

        self::assertSame(1, $summary['ran']);
        self::assertSame(1, $summary['completed']);
        self::assertSame('completed', $this->queue->status($jobId)['status']);
    }

    public function testFutureJobIsNotRun(): void
    {
        $jobId = $this->queue->enqueue(new JobRequest('plg_test.noop', [], [
            'space_id' => 1,
            'available_at' => new \DateTimeImmutable('+10 minutes'),
        ]));

        $summary = $this->worker->run(JobWorker::SOURCE_CRON);

        self::assertSame(0, $summary['ran']);
        self::assertSame('pending', $this->queue->status($jobId)['status']);
    }

    public function testDeduplicationKeepsASingleJob(): void
    {
        $key = 'dedup-' . bin2hex(random_bytes(6));

        $first = $this->queue->enqueue(new JobRequest('plg_test.noop', [], ['space_id' => 1, 'dedup_key' => $key]));
        $second = $this->queue->enqueue(new JobRequest('plg_test.noop', [], ['space_id' => 1, 'dedup_key' => $key]));

        self::assertSame($first, $second);

        $count = $this->db->selectOneOrNull(
            'SELECT COUNT(*) AS total FROM jobs WHERE dedup_key = %',
            [$key],
        );
        self::assertSame(1, (int) $count->total);
    }

    public function testFailureSchedulesARetryWithinTheLimit(): void
    {
        $jobId = $this->queue->enqueue(new JobRequest('plg_test.failing', [], ['space_id' => 1]));

        $summary = $this->worker->run(JobWorker::SOURCE_CRON);

        self::assertSame(1, $summary['ran']);
        self::assertSame(1, $summary['failed']);

        $row = $this->queue->status($jobId);

        // Une tentative sur deux : le job reste en attente, avec un message.
        self::assertSame('pending', $row['status']);
        self::assertSame(1, (int) $row['attempts']);
        self::assertNotEmpty($row['last_error']);

        // Le jeton de réservation reste interne : `status()` expose le bail,
        // pas le secret de réservation.
        self::assertNull($row['reserved_until']);
    }

    public function testExhaustedAttemptsEndInFailure(): void
    {
        $jobId = $this->queue->enqueue(new JobRequest('plg_test.failing', [], ['space_id' => 1, 'max_attempts' => 1]));

        $this->worker->run(JobWorker::SOURCE_CRON);

        $row = $this->queue->status($jobId);

        self::assertSame('failed', $row['status']);
        self::assertNotNull($row['finished_at']);
        self::assertNull($row['reserved_until']);
    }

    public function testExpiredLeaseIsRecoveredAndRunAgain(): void
    {
        $jobId = $this->queue->enqueue(new JobRequest('plg_test.noop', [], ['space_id' => 1]));

        // Simule un worker interrompu : bail dépassé, ligne restée en cours.
        $this->db->execute(
            "UPDATE jobs
                SET status = 'processing', claim = 'interrompu',
                    reserved_until = DATE_SUB(now(), INTERVAL 5 MINUTE)
              WHERE id = %",
            [$jobId],
        );

        $summary = $this->worker->run(JobWorker::SOURCE_CRON);

        self::assertGreaterThanOrEqual(1, $summary['recovered']);
        self::assertSame('completed', $this->queue->status($jobId)['status']);
    }

    public function testUnknownTypeIsFailedWithoutRunning(): void
    {
        $this->db->execute(
            "INSERT INTO jobs (space_id, type, producer, payload)
             VALUES (1, 'plg_test.disparu', 'plg_test', '{}')",
        );
        $jobId = (int) $this->db->getLastInsertId();

        $summary = $this->worker->run(JobWorker::SOURCE_CRON);

        self::assertSame(1, $summary['unavailable']);
        self::assertSame(0, $summary['ran']);
        self::assertSame('failed', $this->queue->status($jobId)['status']);
    }

    public function testCancelRemovesAPendingJobFromTheQueue(): void
    {
        $jobId = $this->queue->enqueue(new JobRequest('plg_test.noop', [], ['space_id' => 1]));

        self::assertTrue($this->queue->cancel($jobId));
        self::assertFalse($this->queue->cancel($jobId), 'Une seconde annulation ne change rien.');

        $this->worker->run(JobWorker::SOURCE_CRON);

        self::assertSame('cancelled', $this->queue->status($jobId)['status']);
    }
}

/** Handler sans effet : le job doit seulement atteindre l'état terminal. */
final class NoopJobHandler extends BaseJobHandler
{
    public function handle(JobContext $context, array $payload): JobResult
    {
        return JobResult::success();
    }
}

/** Handler en échec : vérifie le comptage des tentatives et le backoff. */
final class FailingJobHandler extends BaseJobHandler
{
    public function handle(JobContext $context, array $payload): JobResult
    {
        return JobResult::failure('Échec demandé par le test.');
    }
}
