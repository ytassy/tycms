<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Automation\Mcp\McpProtocol;
use YTassy\Tycms\CoreExts\Core\Helper\ServiceAccountTokenHelper;
use YTassy\Tycms\Request\Request;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Response\Response;
use YTassy\Tycms\Session\Session;
use YTassy\Tycms\Tests\Support\Integration\TestDatabase;
use YTassy\Tycms\Tests\Support\Integration\TestEnvironment;
use YTassy\Tycms\Tests\Support\Integration\TestFixtures;

/**
 * Accès aux documents MCP : deux modes distincts, session ROOT ou jeton d'API.
 *
 * Chaque mode suffit seul ; aucun des deux n'élargit l'autre. La session
 * ordinaire d'un utilisateur non ROOT ne doit jamais donner accès.
 */
final class AutomationMcpDocumentsTest extends TestCase
{
    private Application $app;
    private ?\YTassy\Tycms\DB\Database $db = null;
    private string $secret = '';
    private int $serviceUserId = 0;

    protected function setUp(): void
    {
        $environment = TestEnvironment::load();
        TestFixtures::assertPrepared(new TestDatabase($environment));
        $this->app = new Application($environment->configuration());
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->db->execute('ROLLBACK');
            $this->db = null;
        }
    }

    private function boot(?int $sessionUserId): void
    {
        // Une session absente produit un principal visiteur, pas ROOT.
        $session = $this->createStub(Session::class);
        $session->method('getUserId')->willReturn($sessionUserId);
        $session->method('getSpaceId')->willReturn(TestFixtures::ROOT_SPACE_ID);

        $this->app->initialiseOrThrow(
            new Request(method: RequestMethod::GET, uri: '/'),
            initializeSession: false,
            session: $session,
            registerRoutes: false,
        );

        $this->db = $this->app->getDatabase();
        $this->db->execute('START TRANSACTION');
    }

    /** Crée un compte de service jetable et son jeton, dans la transaction du test. */
    private function createServiceToken(): void
    {
        $this->db->execute(
            "INSERT INTO users (space_id, type, first_name, last_name, email, password, params, register_date) "
            . "VALUES (%, 'service', 'Doc', 'Test', %, '', '{}', current_timestamp())",
            [TestFixtures::ROOT_SPACE_ID, 'doc-' . bin2hex(random_bytes(6)) . '@example.test'],
        );
        $this->serviceUserId = (int) $this->db->getLastInsertId();

        /** @var ServiceAccountTokenHelper $tokens */
        $tokens = $this->app->getOrAddSharedExtensionsClass(ServiceAccountTokenHelper::class);
        $this->secret = $tokens->createToken($this->serviceUserId, 'Doc', TestFixtures::ROOT_SPACE_ID)['token'];
    }

    /** Route GET des documents, avec ou sans jeton. */
    private function getDocument(string $path, ?string $token = null): Response
    {
        $request = new Request(
            method: RequestMethod::GET,
            uri: $path,
            headers: $token === null ? [] : ['Authorization' => 'Bearer ' . $token],
        );

        /** @var \YTassy\Tycms\CoreExts\Automation\WF\WFAutomationMcp $wf */
        $wf = $this->app->getOrAddSharedExtensionsClass(
            \YTassy\Tycms\CoreExts\Automation\WF\WFAutomationMcp::class,
        );

        return $path === '/ai/mcp/reference' ? $wf->reference($request) : $wf->guide($request);
    }

    #[Test]
    public function aRootSessionReadsTheDocumentsWithoutAnyToken(): void
    {
        // Le compte ROOT des fixtures est l'utilisateur 1 du Space ROOT.
        $this->boot(1);

        self::assertSame(200, $this->getDocument('/ai/mcp/guide')->code);
        self::assertSame(200, $this->getDocument('/ai/mcp/reference')->code);
    }

    #[Test]
    public function anApiTokenReadsTheDocumentsWithoutAnySession(): void
    {
        $this->boot(null);
        $this->createServiceToken();

        $guide = $this->getDocument('/ai/mcp/guide', $this->secret);
        self::assertSame(200, $guide->code);
        self::assertStringContainsString('Syntaxe du langage', (string) $guide->body);

        self::assertSame(200, $this->getDocument('/ai/mcp/reference', $this->secret)->code);
    }

    #[Test]
    public function noSessionAndNoTokenIsRejected(): void
    {
        $this->boot(null);

        $response = $this->getDocument('/ai/mcp/guide');

        // Les deux modes échouent : refus explicite, identique aux autres routes.
        self::assertSame(401, $response->code);
        self::assertSame('Bearer', $response->httpHeaders['WWW-Authenticate'] ?? null);
    }

    #[Test]
    public function anUnknownTokenIsRejected(): void
    {
        $this->boot(null);

        $response = $this->getDocument('/ai/mcp/guide', bin2hex(random_bytes(32)));

        self::assertSame(401, $response->code);
    }

    #[Test]
    public function aNonRootSessionDoesNotReplaceTheToken(): void
    {
        // Un utilisateur non ROOT ne doit jamais ouvrir la documentation :
        // c'est la frontière entre les deux modes.
        $this->boot(999999);

        $response = $this->getDocument('/ai/mcp/guide');

        self::assertSame(401, $response->code);
    }
}
