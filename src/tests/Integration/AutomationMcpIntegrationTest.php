<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Automation\Mcp\McpProtocol;
use YTassy\Tycms\CoreExts\Core\Helper\ServiceAccountTokenHelper;
use YTassy\Tycms\DB\Database;
use YTassy\Tycms\Request\Request;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Response\Response;
use YTassy\Tycms\Session\Session;
use YTassy\Tycms\Tests\Support\Integration\TestDatabase;
use YTassy\Tycms\Tests\Support\Integration\TestEnvironment;
use YTassy\Tycms\Tests\Support\Integration\TestFixtures;

/**
 * Serveur MCP réel : authentification par jeton d'un compte de service,
 * protocole JSON-RPC et exécution de code auditée sur la base isolée.
 */
final class AutomationMcpIntegrationTest extends TestCase
{
    private Application $app;
    private Database $db;
    private string $secret;
    private int $userId;

    protected function setUp(): void
    {
        $environment = TestEnvironment::load();
        TestFixtures::assertPrepared(new TestDatabase($environment));
        $this->app = new Application($environment->configuration());

        $session = $this->createStub(Session::class);
        $session->method('getUserId')->willReturn(1);
        $session->method('getSpaceId')->willReturn(TestFixtures::ROOT_SPACE_ID);

        $this->app->initialiseOrThrow(
            new Request(method: RequestMethod::GET, uri: '/'),
            initializeSession: false,
            session: $session,
            registerRoutes: false,
        );

        $this->db = $this->app->getDatabase();
        $this->db->execute('START TRANSACTION');

        // Compte de service jetable et son jeton, dans la transaction du test.
        $this->db->execute(
            "INSERT INTO users (space_id, type, first_name, last_name, email, password, params, register_date) "
            . "VALUES (%, 'service', 'Assistant', 'Test', %, '', '{}', current_timestamp())",
            [TestFixtures::ROOT_SPACE_ID, 'mcp-' . bin2hex(random_bytes(6)) . '@example.test'],
        );
        $this->userId = (int) $this->db->getLastInsertId();

        /** @var ServiceAccountTokenHelper $tokens */
        $tokens = $this->app->getOrAddSharedExtensionsClass(ServiceAccountTokenHelper::class);
        $created = $tokens->createToken($this->userId, 'Test', TestFixtures::ROOT_SPACE_ID);
        $this->secret = $created['token'];
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->execute('ROLLBACK');
        }
    }

    #[Test]
    public function rejectsARequestWithoutToken(): void
    {
        $response = $this->call(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'], '');

        self::assertSame(401, $response->code);
        self::assertSame('Bearer', $response->httpHeaders['WWW-Authenticate'] ?? null);
    }

    #[Test]
    public function rejectsAnUnknownToken(): void
    {
        $response = $this->call(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'],
            bin2hex(random_bytes(32)),
        );

        self::assertSame(401, $response->code);
    }

    #[Test]
    public function answersTheRawJsonRpcWithoutApiEnvelope(): void
    {
        $response = $this->call(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'ping']);
        $body = $this->decode($response);

        self::assertSame(200, $response->code);
        self::assertSame('2.0', $body['jsonrpc']);
        self::assertSame(7, $body['id']);

        // Aucun enrichissement `ApiResponse` ne doit fuiter dans le protocole.
        self::assertArrayNotHasKey('is_message', $body);
        self::assertArrayNotHasKey('message_level', $body);
        self::assertArrayNotHasKey('error', $body);
    }

    #[Test]
    public function initializesWithProtocolCapabilities(): void
    {
        $body = $this->decode($this->call([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18'],
        ]));

        self::assertSame('2025-06-18', $body['result']['protocolVersion']);
        self::assertSame(McpProtocol::SERVER_NAME, $body['result']['serverInfo']['name']);
        self::assertArrayHasKey('tools', $body['result']['capabilities']);
    }

    #[Test]
    public function acknowledgesNotificationsWithoutBody(): void
    {
        $response = $this->call(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

        // Le protocole impose une acceptation sans corps pour une notification.
        self::assertSame(202, $response->code);
        self::assertNull($response->body);
    }

    #[Test]
    public function listsTheTools(): void
    {
        $body = $this->decode($this->call([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
        ]));

        $names = array_column($body['result']['tools'], 'name');

        self::assertContains('tycms.run', $names);
        self::assertContains('tycms.list_actions', $names);
        self::assertContains('tycms.reference', $names);
    }

    #[Test]
    public function rejectsAnUnknownMethod(): void
    {
        $body = $this->decode($this->call([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'unknown/method',
        ]));

        self::assertSame(-32601, $body['error']['code']);
    }

    #[Test]
    public function runsCodeAndAuditsTheExecution(): void
    {
        $body = $this->decode($this->call([
            'jsonrpc' => '2.0',
            'id' => 4,
            'method' => 'tools/call',
            'params' => [
                'name' => 'tycms.run',
                'arguments' => [
                    'source' => 'function main() { return ["sum" => $input->a + $input->b]; }',
                    'input' => ['a' => 2, 'b' => 3],
                ],
            ],
        ]));

        self::assertFalse($body['result']['isError']);
        $output = json_decode($body['result']['content'][0]['text'], true);
        self::assertSame('success', $output['status']);
        self::assertSame(['sum' => 5], $output['output']);

        // L'audit est écrit avec le jeton réel et sans la source.
        $row = $this->db->selectOneOrNull(
            'SELECT * FROM mcp_executions WHERE user_id = % ORDER BY id DESC LIMIT 1',
            [$this->userId],
        );

        self::assertNotNull($row);
        self::assertSame('success', $row->status);
        self::assertSame(TestFixtures::ROOT_SPACE_ID, (int) $row->space_id);
        self::assertNotNull($row->token_id);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', (string) $row->source_hash);
    }

    #[Test]
    public function refusesAnExternalAction(): void
    {
        $body = $this->decode($this->call([
            'jsonrpc' => '2.0',
            'id' => 5,
            'method' => 'tools/call',
            'params' => [
                'name' => 'tycms.run',
                'arguments' => [
                    'source' => 'function main() { return core::sendMail("welcome", "a@example.test", []); }',
                ],
            ],
        ]));

        // L'action externe est inconnue à la compilation : le run échoue.
        $output = json_decode($body['result']['content'][0]['text'], true);
        self::assertSame('failed', $output['status']);
        self::assertTrue($body['result']['isError']);
    }

    /** @param array<string, mixed> $payload */
    private function call(array $payload, ?string $token = null): Response
    {
        // '' demande explicitement l'absence d'en-tête ; null utilise le jeton du test.
        $token ??= $this->secret;
        $request = new Request(
            method: RequestMethod::POST,
            uri: '/ai/mcp',
            headers: $token === '' ? [] : ['Authorization' => 'Bearer ' . $token],
            body: $payload,
            raw_body: json_encode($payload, JSON_THROW_ON_ERROR),
        );

        return $this->app->getOrAddSharedExtensionsClass(McpProtocol::class)->handle($request);
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        $body = json_decode((string) $response->body, true);

        self::assertIsArray($body, 'La réponse doit être un JSON-RPC valide.');

        return $body;
    }
}