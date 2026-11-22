<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Integration;

use PHPUnit\Framework\TestCase;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Automation\Helper\AutomationRepository;
use YTassy\Tycms\CoreExts\Automation\Runtime\Identity\SystemIdGenerator;
use YTassy\Tycms\CoreExts\Automation\WF\WFAutomation;
use YTassy\Tycms\DB\Database;
use YTassy\Tycms\Request\Request;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Session\Session;
use YTassy\Tycms\Tests\Support\Integration\TestDatabase;
use YTassy\Tycms\Tests\Support\Integration\TestEnvironment;
use YTassy\Tycms\Tests\Support\Integration\TestFixtures;

/** Reprise, arrêt et nettoyage manuels sur la base isolée. */
final class AutomationMaintenanceIntegrationTest extends TestCase
{
    private Application $app;
    private Database $db;
    private WFAutomation $wf;
    private string $key;

    protected function setUp(): void
    {
        $environment = TestEnvironment::load();
        TestFixtures::assertPrepared(new TestDatabase($environment));
        $this->app = new Application($environment->configuration());

        $session = $this->createStub(Session::class);
        $session->method('getUserId')->willReturn(1);
        $session->method('getSpaceId')->willReturn(1);

        $this->app->initialiseOrThrow(
            new Request(method: \YTassy\Tycms\Request\RequestMethod::GET, uri: '/'),
            initializeSession: false,
            session: $session,
            registerRoutes: false,
        );
        $this->db = $this->app->getDatabase();
        $this->db->execute('START TRANSACTION');
        $this->wf = new WFAutomation($this->app);
        $this->key = 'maint-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->execute('ROLLBACK');
        }
    }

    public function testStuckEventIsRecoveredOnceAndRunsTheScenario(): void
    {
        [$scenarioId, $versionId] = $this->story();
        $eventId = $this->stuckEvent($scenarioId, 'pending', 20);

        $result = $this->wf->recoverEvent($eventId);

        self::assertTrue($result['recovered']);
        self::assertSame(1, $result['runs']);
        self::assertSame('processed', $result['status']);

        $run = $this->db->selectOneOrNull(
            'SELECT r.status, r.output FROM automation_runs r
             INNER JOIN automation_events e ON e.id = r.event_id
             WHERE e.id = % LIMIT 1',
            [$eventId],
        );
        self::assertNotNull($run);
        self::assertSame('success', (string) $run->status);
        self::assertStringContainsString('"ok":true', (string) $run->output);

        $this->expectException(ExceptionResponse::class);
        $this->wf->recoverEvent($eventId);
    }

    public function testProcessingEventCanBeReservedOnlyOnce(): void
    {
        [$scenarioId] = $this->story();
        $eventId = $this->stuckEvent($scenarioId, 'processing', 20, 1);
        $repository = new AutomationRepository($this->app);

        self::assertTrue($repository->recoverStuckEvent($eventId, 600, 3));
        self::assertFalse($repository->recoverStuckEvent($eventId, 600, 3));
    }

    public function testStuckRunIsFailedOnceAndThenRejected(): void
    {
        [$scenarioId, $versionId] = $this->story();
        $runId = $this->stuckRun($scenarioId, $versionId, 'running', 20);

        $result = $this->wf->failStuckRun($runId);

        self::assertTrue($result['failed']);
        $run = $this->db->selectOneOrNull(
            'SELECT status, error_code FROM automation_runs WHERE id = % LIMIT 1',
            [$runId],
        );
        self::assertNotNull($run);
        self::assertSame('failed', (string) $run->status);
        self::assertSame('AUTOMATION_RUN_STUCK', (string) $run->error_code);

        $this->expectException(ExceptionResponse::class);
        $this->wf->failStuckRun($runId);
    }

    public function testFailedRunIsRerunWithThePublishedVersion(): void
    {
        [$scenarioId, $versionId] = $this->story();
        $failedRunId = $this->stuckRun($scenarioId, $versionId, 'failed', 5);

        $result = $this->wf->rerunRun($failedRunId);

        self::assertSame('success', (string) $result['status']);
        self::assertIsInt($result['run_id']);
        self::assertNotSame($failedRunId, $result['run_id']);

        $newRun = $this->db->selectOneOrNull(
            'SELECT status, version_id FROM automation_runs WHERE id = %',
            [$result['run_id']],
        );
        self::assertNotNull($newRun);
        self::assertSame('success', (string) $newRun->status);
        self::assertSame($versionId, (int) $newRun->version_id);
    }

    public function testRerunRequiresRunManagePermission(): void
    {
        [$scenarioId, $versionId] = $this->story();
        $failedRunId = $this->stuckRun($scenarioId, $versionId, 'failed', 5);
        $session = $this->createStub(Session::class);
        $session->method('getUserId')->willReturn(999);
        $session->method('getSpaceId')->willReturn(1);
        (new \ReflectionProperty(Application::class, 'session'))->setValue($this->app, $session);

        $this->expectException(ExceptionResponse::class);
        $this->wf->rerunRun($failedRunId);
    }

    public function testCleanupOnlyDeletesTerminalRowsOlderThanRetention(): void
    {
        [$scenarioId, $versionId] = $this->story();
        $this->retentionConfig(30, 90);

        $oldProcessedEvent = $this->stuckEvent($scenarioId, 'processed', 40 * 24 * 60);
        $oldPendingEvent = $this->stuckEvent($scenarioId, 'pending', 40 * 24 * 60);
        $oldSuccessRun = $this->stuckRun($scenarioId, $versionId, 'success', 100 * 24 * 60);
        $oldRunningRun = $this->stuckRun($scenarioId, $versionId, 'running', 100 * 24 * 60);

        $result = $this->wf->cleanupMaintenance(1);

        self::assertGreaterThanOrEqual(1, $result['events_deleted']);
        self::assertGreaterThanOrEqual(1, $result['runs_deleted']);
        self::assertNull($this->db->selectOneOrNull(
            'SELECT id FROM automation_events WHERE id = %',
            [$oldProcessedEvent],
        ));
        self::assertNotNull($this->db->selectOneOrNull(
            'SELECT id FROM automation_events WHERE id = %',
            [$oldPendingEvent],
        ));
        self::assertNull($this->db->selectOneOrNull(
            'SELECT id FROM automation_runs WHERE id = %',
            [$oldSuccessRun],
        ));
        self::assertNotNull($this->db->selectOneOrNull(
            'SELECT id FROM automation_runs WHERE id = %',
            [$oldRunningRun],
        ));
    }

    /** @return array{0: int, 1: int} */
    private function story(): array
    {
        $this->db->execute(
            'INSERT INTO automation_scenarios
                (space_id, `key`, name, description, status, principal_mode, execution_user_id, created_by, created)
             VALUES (1, %, % , \'\', \'active\', \'author\', 1, 1, current_timestamp())',
            [$this->key, 'Maintenance ' . $this->key],
        );
        $scenarioId = $this->db->getLastInsertId();
        $source = 'function main() { return ["ok" => true]; }';

        $this->db->execute(
            'INSERT INTO automation_versions
                (scenario_id, version_number, status, compile_status, source, source_hash,
                 ast, schema_version, grammar_version, diagnostics, created_by, created, published_by, published_at)
             VALUES (%, 1, \'published\', \'ok\', %, %, NULL, 1, 1, NULL, 1, current_timestamp(), 1, current_timestamp())',
            [$scenarioId, $source, hash('sha256', $source)],
        );
        $versionId = $this->db->getLastInsertId();
        $this->db->execute(
            'UPDATE automation_scenarios SET published_version_id = %, draft_version_id = % WHERE id = %',
            [$versionId, $versionId, $scenarioId],
        );
        $this->db->execute(
            'INSERT INTO automation_triggers
                (scenario_id, space_id, trigger_id, entry_function, config, secret_hash, enabled, created)
             VALUES (%, 1, \'core.page.button.clicked\', \'main\', \'{}\', NULL, 1, current_timestamp())',
            [$scenarioId],
        );

        return [$scenarioId, $versionId];
    }

    private function stuckEvent(int $scenarioId, string $status, int $ageMinutes, int $attempts = 0): int
    {
        $eventId = (new SystemIdGenerator())->uuid();
        $this->db->execute(
            'INSERT INTO automation_events
                (event_id, space_id, trigger_id, target_scenario_key, payload, principal_type,
                 principal_id, correlation_id, depth, causation_id, occurred_at, status, attempts, created)
             VALUES (%, 1, \'core.page.button.clicked\', %, \'{}\', \'service\', NULL, NULL, 0, NULL,
                     DATE_SUB(current_timestamp(), INTERVAL % MINUTE), %, %, current_timestamp())',
            [$eventId, $this->key, $ageMinutes, $status, $attempts],
        );

        return $this->db->getLastInsertId();
    }

    private function stuckRun(int $scenarioId, int $versionId, string $status, int $ageMinutes): int
    {
        $this->db->execute(
            'INSERT INTO automation_runs
                (space_id, scenario_id, version_id, trigger_id, event_id, dedup_key,
                 principal_type, principal_id, input, status, depth, queued_at, started_at, created)
             VALUES (1, %, %, \'core.page.button.clicked\', NULL, NULL, \'user\', 1, \'{}\',
                     %, 0,
                     DATE_SUB(current_timestamp(), INTERVAL % MINUTE),
                     DATE_SUB(current_timestamp(), INTERVAL % MINUTE),
                     DATE_SUB(current_timestamp(), INTERVAL % MINUTE))',
            [$scenarioId, $versionId, $status, $ageMinutes, $ageMinutes, $ageMinutes],
        );

        return $this->db->getLastInsertId();
    }

    private function retentionConfig(int $eventDays, int $runDays): void
    {
        $params = json_encode(
            ['event_retention_days' => $eventDays, 'run_retention_days' => $runDays],
            JSON_THROW_ON_ERROR,
        );
        $this->db->execute(
            'INSERT INTO config (space_id, extension, params) VALUES (1, \'plg_automation\', %)
             ON DUPLICATE KEY UPDATE params = VALUES(params)',
            [$params],
        );
    }
}
