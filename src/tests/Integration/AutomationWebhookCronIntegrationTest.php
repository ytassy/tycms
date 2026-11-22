<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationEventBuffer;
use YTassy\Tycms\CoreExts\Automation\Runtime\Identity\SystemIdGenerator;
use YTassy\Tycms\CoreExts\Automation\Webhook\WebhookConfig;
use YTassy\Tycms\CoreExts\Automation\Webhook\WebhookReceiver;
use YTassy\Tycms\CoreExts\Core\Cron\CronDispatcher;
use YTassy\Tycms\CoreExts\Core\Cron\CronTick;
use YTassy\Tycms\CoreExts\Core\Helper\Crypt\CryptHelper;
use YTassy\Tycms\DB\Database;
use YTassy\Tycms\Request\Request;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Session\Session;
use YTassy\Tycms\Tests\Support\Integration\TestDatabase;
use YTassy\Tycms\Tests\Support\Integration\TestEnvironment;
use YTassy\Tycms\Tests\Support\Integration\TestFixtures;

/** Webhook et CRON réels sur la base isolée. */
final class AutomationWebhookCronIntegrationTest extends TestCase
{
    private Application $app;
    private Database $db;
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
            new Request(method: RequestMethod::GET, uri: '/'),
            initializeSession: false,
            session: $session,
            registerRoutes: false,
        );
        $this->db = $this->app->getDatabase();
        $this->db->execute('START TRANSACTION');
        $this->key = 'it-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->execute('ROLLBACK');
        }
    }

    #[Test]
    public function bearerWebhookAcceptsAndRunsTheScenario(): void
    {
        $token = bin2hex(random_bytes(16));
        $scenarioId = $this->scenario(
            WebhookReceiver::TRIGGER_ID,
            ['auth_mode' => 'bearer', 'response_mode' => 'accepted'],
            hash('sha256', $token),
        );

        $response = $this->receiver()->receive(
            $scenarioId,
            $this->post('/automation/webhook/' . $scenarioId, ['hello' => 'world'], [
                'Authorization' => 'Bearer ' . $token,
            ]),
        );

        self::assertSame(['accepted' => true], $response);
        $this->drain();
        $run = $this->latestRun($scenarioId);
        self::assertNotNull($run);
        self::assertSame('success', (string) $run->status);
    }

    #[Test]
    public function resultModeReturnsTheRunOutput(): void
    {
        $token = bin2hex(random_bytes(16));
        $scenarioId = $this->scenario(
            WebhookReceiver::TRIGGER_ID,
            ['auth_mode' => 'bearer', 'response_mode' => 'result'],
            hash('sha256', $token),
        );

        $response = $this->receiver()->receive(
            $scenarioId,
            $this->post('/automation/webhook/' . $scenarioId, ['hello' => 'world'], [
                'Authorization' => 'Bearer ' . $token,
            ]),
        );

        self::assertTrue($response['accepted']);
        self::assertSame('success', $response['status']);
        self::assertIsInt($response['run_id']);
        self::assertSame('world', $response['output']['hello'] ?? null);
    }

    #[Test]
    public function queryTokenIsRemovedFromInput(): void
    {
        $token = bin2hex(random_bytes(16));
        $scenarioId = $this->scenario(
            WebhookReceiver::TRIGGER_ID,
            ['auth_mode' => 'query', 'response_mode' => 'result'],
            hash('sha256', $token),
        );

        $response = $this->receiver()->receive(
            $scenarioId,
            new Request(
                method: RequestMethod::GET,
                uri: '/automation/webhook/' . $scenarioId . '?tycms_token=' . $token . '&hello=world',
                query: ['tycms_token' => $token, 'hello' => 'world'],
            ),
        );

        self::assertTrue($response['accepted']);
        self::assertSame('world', $response['output']['hello'] ?? null);
        self::assertArrayNotHasKey('tycms_token', $response['output']);
    }

    #[Test]
    public function hmacSignatureAuthenticatesTheRawBody(): void
    {
        $secret = bin2hex(random_bytes(16));
        $encrypted = $this->app->getOrAddSharedExtensionsClass(CryptHelper::class)->encrypt(
            $secret,
            WebhookConfig::HMAC_PURPOSE,
        );
        $scenarioId = $this->scenario(
            WebhookReceiver::TRIGGER_ID,
            [
                'auth_mode' => 'hmac',
                'response_mode' => 'result',
                WebhookConfig::HMAC_FIELD => $encrypted,
            ],
            null,
        );
        $body = '{"hello":"world"}';
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, $secret);

        $response = $this->receiver()->receive(
            $scenarioId,
            new Request(
                method: RequestMethod::POST,
                uri: '/automation/webhook/' . $scenarioId,
                headers: [
                    'Content-Type' => 'application/json',
                    'X-Tycms-Timestamp' => $timestamp,
                    'X-Tycms-Signature' => 'sha256=' . $signature,
                ],
                body: ['hello' => 'world'],
                raw_body: $body,
            ),
        );

        self::assertTrue($response['accepted']);
        self::assertSame('world', $response['output']['hello'] ?? null);
    }

    #[Test]
    public function wrongTokenIsRejected(): void
    {
        $scenarioId = $this->scenario(
            WebhookReceiver::TRIGGER_ID,
            ['auth_mode' => 'bearer', 'response_mode' => 'accepted'],
            hash('sha256', 'expected'),
        );

        $exception = $this->captureException(fn () => $this->receiver()->receive(
            $scenarioId,
            $this->post('/automation/webhook/' . $scenarioId, ['hello' => 'world'], [
                'Authorization' => 'Bearer wrong',
            ]),
        ));

        self::assertSame(401, $exception->getResponse()->code);
    }

    #[Test]
    public function bearerModeRefusesGet(): void
    {
        $scenarioId = $this->scenario(
            WebhookReceiver::TRIGGER_ID,
            ['auth_mode' => 'bearer', 'response_mode' => 'accepted'],
            hash('sha256', 'secret'),
        );

        $exception = $this->captureException(fn () => $this->receiver()->receive(
            $scenarioId,
            new Request(method: RequestMethod::GET, uri: '/automation/webhook/' . $scenarioId, headers: [
                'Authorization' => 'Bearer secret',
            ]),
        ));

        self::assertSame(405, $exception->getResponse()->code);
    }

    #[Test]
    public function oversizedBodyIsRejectedBeforeAuthentication(): void
    {
        $scenarioId = $this->scenario(
            WebhookReceiver::TRIGGER_ID,
            ['auth_mode' => 'bearer', 'response_mode' => 'accepted'],
            hash('sha256', 'secret'),
        );
        $body = '{"x":"' . str_repeat('a', WebhookReceiver::MAX_BODY_BYTES) . '"}';

        $exception = $this->captureException(fn () => $this->receiver()->receive(
            $scenarioId,
            $this->post('/automation/webhook/' . $scenarioId, [], [
                'Content-Type' => 'application/json',
            ], $body),
        ));

        self::assertSame(413, $exception->getResponse()->code);
    }

    #[Test]
    public function listPayloadIsRejected(): void
    {
        $scenarioId = $this->scenario(
            WebhookReceiver::TRIGGER_ID,
            ['auth_mode' => 'bearer', 'response_mode' => 'accepted'],
            hash('sha256', 'secret'),
        );
        $body = '[1,2,3]';

        $exception = $this->captureException(fn () => $this->receiver()->receive(
            $scenarioId,
            $this->post('/automation/webhook/' . $scenarioId, [1, 2, 3], [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer secret',
            ], $body),
        ));

        self::assertSame(422, $exception->getResponse()->code);
    }

    #[Test]
    public function quotaIsEnforcedPerScenario(): void
    {
        $token = bin2hex(random_bytes(16));
        $scenarioId = $this->scenario(
            WebhookReceiver::TRIGGER_ID,
            ['auth_mode' => 'bearer', 'response_mode' => 'accepted'],
            hash('sha256', $token),
        );

        for ($i = 0; $i < WebhookReceiver::MAX_PER_MINUTE; $i++) {
            $this->db->execute(
                'INSERT INTO automation_events
                    (event_id, space_id, trigger_id, target_scenario_key, payload, principal_type,
                     principal_id, occurred_at, status, attempts, created)
                 VALUES (%, 1, %, %, \'{}\', \'service\', NULL, current_timestamp(), \'processed\', 0, current_timestamp())',
                [(new SystemIdGenerator())->uuid(), WebhookReceiver::TRIGGER_ID, $this->key],
            );
        }

        $exception = $this->captureException(fn () => $this->receiver()->receive(
            $scenarioId,
            $this->post('/automation/webhook/' . $scenarioId, ['hello' => 'world'], [
                'Authorization' => 'Bearer ' . $token,
            ]),
        ));

        self::assertSame(429, $exception->getResponse()->code);
    }

    #[Test]
    public function coreCronRunsTheDueScheduleOnlyOncePerSlot(): void
    {
        $this->app->getConf()->cron_token = 'cron-secret';
        $scenarioId = $this->scenario(
            'automation.schedule.tick',
            ['preset' => 'daily', 'time' => '09:00', 'weekday' => 1],
            null,
            'function main() { return ["ok" => true]; }',
        );

        // CronWorker ouvre et valide sa propre transaction par scénario :
        // le rollback du tearDown ne suffit pas, on nettoie explicitement.
        try {
            $dispatcher = new CronDispatcher($this->app);
            $request = new Request(
                method: RequestMethod::GET,
                headers: ['Authorization' => 'Bearer cron-secret'],
            );
            $tick = CronTick::fromDateTime(new \DateTimeImmutable(
                '2026-11-18 09:00:00',
                new \DateTimeZone('Europe/Paris'),
            ));

            $first = $dispatcher->run($request, $tick);
            $summary = $first['results']['minute']['plg_automation'];

            self::assertTrue($first['ok']);
            self::assertSame(1, $summary['due']);
            self::assertSame(1, $summary['started']);
            self::assertSame(1, $summary['succeeded']);

            $second = $dispatcher->run($request, $tick);
            $secondSummary = $second['results']['minute']['plg_automation'];

            self::assertSame(1, $secondSummary['due']);
            self::assertSame(0, $secondSummary['started']);
            self::assertSame(1, $secondSummary['skipped']);

            $count = $this->db->selectOneOrNull(
                'SELECT COUNT(*) AS total FROM automation_runs
                 WHERE scenario_id = % AND trigger_id = \'automation.schedule.tick\'',
                [$scenarioId],
            );
            self::assertSame(1, (int) ($count->total ?? 0));
        } finally {
            $this->cleanupScenario($scenarioId);
        }
    }

    private function cleanupScenario(int $scenarioId): void
    {
        $this->db->execute('DELETE FROM automation_runs WHERE scenario_id = %', [$scenarioId]);
        $this->db->execute(
            'DELETE FROM automation_events WHERE space_id = 1 AND target_scenario_key = %',
            [$this->key],
        );
        $this->db->execute('DELETE FROM automation_triggers WHERE scenario_id = %', [$scenarioId]);
        $this->db->execute('DELETE FROM automation_versions WHERE scenario_id = %', [$scenarioId]);
        $this->db->execute('DELETE FROM automation_scenarios WHERE id = %', [$scenarioId]);
    }

    private function receiver(): WebhookReceiver
    {
        return $this->app->getOrAddSharedExtensionsClass(WebhookReceiver::class);
    }

    private function drain(): void
    {
        /** @var AutomationEventBuffer $buffer */
        $buffer = $this->app->getOrAddSharedExtensionsClass(AutomationEventBuffer::class);
        $buffer->drain(true);
    }

    private function post(string $uri, array $body, array $headers = [], ?string $rawBody = null): Request
    {
        return new Request(
            method: RequestMethod::POST,
            uri: $uri,
            headers: $headers,
            body: $body,
            raw_body: $rawBody ?? json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    private function latestRun(int $scenarioId): ?object
    {
        return $this->db->selectOneOrNull(
            'SELECT * FROM automation_runs WHERE scenario_id = % ORDER BY id DESC LIMIT 1',
            [$scenarioId],
        );
    }

    private function captureException(callable $callback): ExceptionResponse
    {
        try {
            $callback();
        } catch (ExceptionResponse $exception) {
            return $exception;
        }

        self::fail('Une exception de réponse était attendue.');
    }

    private function scenario(
        string $triggerId,
        array $config,
        ?string $secretHash,
        string $source = 'function main() { return $input; }',
    ): int {
        $this->db->execute(
            'INSERT INTO automation_scenarios
                (space_id, `key`, name, description, status, principal_mode, execution_user_id, created_by, created)
             VALUES (1, %, %, \'\', \'active\', \'author\', 1, 1, current_timestamp())',
            [$this->key, 'Intégration ' . $this->key],
        );
        $scenarioId = $this->db->getLastInsertId();

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
             VALUES (%, 1, %, \'main\', %, %, 1, current_timestamp())',
            [$scenarioId, $triggerId, json_encode($config, JSON_THROW_ON_ERROR), $secretHash],
        );

        return $scenarioId;
    }
}
