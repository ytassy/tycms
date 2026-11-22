<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Integration;

use PHPUnit\Framework\TestCase;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Automation\Plugin;
use YTassy\Tycms\CoreExts\Core\Automation\Support\GoogleSheetsConfig;
use YTassy\Tycms\DB\Database;
use YTassy\Tycms\Request\Request;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Session\Session;
use YTassy\Tycms\Tests\Support\Integration\TestDatabase;
use YTassy\Tycms\Tests\Support\Integration\TestEnvironment;
use YTassy\Tycms\Tests\Support\Integration\TestFixtures;

/** Chiffrement et relecture du compte de service Google Sheets. */
final class AutomationGoogleSheetsConfigIntegrationTest extends TestCase
{
    private Application $app;
    private Database $db;
    private Plugin $plugin;

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
        $this->plugin = $this->app->getExtensions()['plg_automation'];
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->execute('ROLLBACK');
        }
    }

    public function testRootReceivesAdminPermissionFromTheMigration(): void
    {
        $row = $this->db->selectOneOrNull(
            "SELECT 1 AS present FROM acl_permissions
             WHERE role_id = 2 AND extension = 'plg_automation' AND name = 'ADMIN'
             LIMIT 1",
        );

        self::assertNotNull($row);
    }

    public function testServiceAccountIsEncryptedAtRestAndDecryptedForTheEditor(): void
    {
        $json = json_encode([
            'type' => 'service_account',
            'client_email' => 'automation@example.test',
            'private_key' => "-----BEGIN PRIVATE KEY-----\nsecret\n-----END PRIVATE KEY-----\n",
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ], JSON_THROW_ON_ERROR);

        $stored = $this->plugin->prepareConfigForStorage(1, [GoogleSheetsConfig::FIELD => $json]);
        self::assertStringStartsWith('tycmscrypt:v1:', (string) $stored[GoogleSheetsConfig::FIELD]);
        self::assertStringNotContainsString('PRIVATE KEY', (string) $stored[GoogleSheetsConfig::FIELD]);

        $this->db->execute(
            'INSERT INTO config (space_id, extension, params) VALUES (1, \'plg_automation\', %)
             ON DUPLICATE KEY UPDATE params = VALUES(params)',
            [json_encode($stored, JSON_THROW_ON_ERROR)],
        );

        $editor = $this->plugin->prepareConfigForEditor(1, []);
        self::assertSame($json, $editor[GoogleSheetsConfig::FIELD]);
    }

    public function testInvalidServiceAccountIsRejected(): void
    {
        $this->expectException(ExceptionResponse::class);

        $this->plugin->prepareConfigForStorage(1, [
            GoogleSheetsConfig::FIELD => '{"type":"user"}',
        ]);
    }
}
