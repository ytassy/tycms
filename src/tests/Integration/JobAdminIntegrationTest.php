<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Integration;

use PHPUnit\Framework\TestCase;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Job\BaseJobHandler;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Job\JobContext;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Job\JobResult;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Job\JobType;
use YTassy\Tycms\CoreExts\Core\JobQueue\JobQueue;
use YTassy\Tycms\CoreExts\Core\JobQueue\JobTypeRegistry;
use YTassy\Tycms\CoreExts\Core\WF\WFJobs;
use YTassy\Tycms\DB\Database;
use YTassy\Tycms\Request\Request;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Session\Session;
use YTassy\Tycms\Tests\Support\Integration\TestDatabase;
use YTassy\Tycms\Tests\Support\Integration\TestEnvironment;
use YTassy\Tycms\Tests\Support\Integration\TestFixtures;

/**
 * Administration de la file : filtres, relance, purge et détail.
 *
 * Chaque scénario tourne dans une transaction annulée, sur une file vidée au
 * départ : les jobs laissés par d'autres tests restent hors du périmètre.
 */
final class JobAdminIntegrationTest extends TestCase
{
    private Application $app;
    private Database $db;
    private WFJobs $wf;
    private JobQueue $queue;

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
        $this->db->execute('DELETE FROM jobs');

        /** @var JobTypeRegistry $registry */
        $registry = $this->app->getOrAddSharedExtensionsClass(JobTypeRegistry::class);
        $registry->register(new JobType('plg_test.noop', 'Test sans effet', NoopAdminJobHandler::class, 1, 1));

        /** @var JobQueue $queue */
        $queue = $this->app->getOrAddSharedExtensionsClass(JobQueue::class);
        $this->queue = $queue;

        /** @var WFJobs $wf */
        $wf = $this->app->getOrAddSharedExtensionsClass(WFJobs::class);
        $this->wf = $wf;
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->execute('ROLLBACK');
        }
    }

    public function testListFiltersByStatusAndCountsStatuses(): void
    {
        $this->insertJob('failed');
        $this->insertJob('pending');
        $this->insertJob('pending');

        $result = $this->wf->list('failed', null, null, 1);

        self::assertSame(1, $result['total']);
        self::assertCount(1, $result['items']);
        self::assertSame('failed', $result['items'][0]['status']);
        self::assertSame(2, $result['counts']['pending']);
        self::assertArrayHasKey('worker', $result);
    }

    public function testRetryRequeuesAFailedJobWithAFreshCounter(): void
    {
        $jobId = $this->insertJob('failed', attempts: 3);

        self::assertTrue($this->wf->retry($jobId));

        $row = $this->queue->status($jobId);

        self::assertSame('pending', $row['status']);
        self::assertSame(0, (int) $row['attempts']);
        self::assertNull($row['last_error']);
        self::assertNotNull($row['available_at']);
    }

    public function testRetryRefusesAJobThatIsAlreadyQueued(): void
    {
        $jobId = $this->insertJob('pending');

        $this->expectException(ExceptionResponse::class);

        $this->wf->retry($jobId);
    }

    public function testPurgeDeletesOnlyOldTerminalJobs(): void
    {
        $old = $this->insertJob('completed');
        $this->db->execute(
            "UPDATE jobs SET finished_at = DATE_SUB(now(), INTERVAL 20 DAY) WHERE id = %",
            [$old],
        );

        $recent = $this->insertJob('failed');
        $this->db->execute('UPDATE jobs SET finished_at = now() WHERE id = %', [$recent]);

        // Une file encore active ne doit jamais être purgée.
        $pending = $this->insertJob('pending');

        self::assertSame(1, $this->wf->purge(14));
        self::assertNull($this->queue->status($old));
        self::assertNotNull($this->queue->status($recent));
        self::assertNotNull($this->queue->status($pending));
    }

    public function testDetailExposesThePayloadButNeverTheReservationToken(): void
    {
        $jobId = $this->insertJob('failed');

        $detail = $this->wf->detail($jobId);

        self::assertSame($jobId, $detail['id']);
        self::assertArrayHasKey('payload', $detail);
        self::assertArrayHasKey('payloadText', $detail);
        self::assertArrayNotHasKey('claim', $detail);
    }

    private function insertJob(string $status, int $attempts = 0, string $type = 'plg_test.noop'): int
    {
        $this->db->execute(
            "INSERT INTO jobs (space_id, type, producer, payload, status, attempts, last_error)
             VALUES (1, %, 'plg_test', '{}', %, %, %)",
            [$type, $status, $attempts, $status === 'failed' ? 'Échec demandé par le test.' : null],
        );

        return (int) $this->db->getLastInsertId();
    }
}

/** Handler minimal pour les scénarios d'administration. */
final class NoopAdminJobHandler extends BaseJobHandler
{
    public function handle(JobContext $context, array $payload): JobResult
    {
        return JobResult::success();
    }
}
