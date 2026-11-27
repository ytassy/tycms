<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Integration;

use PHPUnit\Framework\TestCase;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Core\Helper\Mail\MailLogHelper;
use YTassy\Tycms\DB\Database;
use YTassy\Tycms\Request\Request;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Session\Session;
use YTassy\Tycms\Tests\Support\Integration\TestDatabase;
use YTassy\Tycms\Tests\Support\Integration\TestEnvironment;
use YTassy\Tycms\Tests\Support\Integration\TestFixtures;

/**
 * Journal des e-mails réel sur la base isolée.
 *
 * Chaque scénario tourne dans une transaction annulée : aucune ligne ne
 * survit au test.
 */
final class MailLogIntegrationTest extends TestCase
{
    private Application $app;

    private Database $db;

    private MailLogHelper $logs;

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
        $this->db->execute('DELETE FROM mail_logs');

        /** @var MailLogHelper $logs */
        $logs = $this->app->getOrAddSharedExtensionsClass(MailLogHelper::class);
        $this->logs = $logs;
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->execute('ROLLBACK');
        }
    }

    public function testAddMasksSecretsAndLifecycleMarksDone(): void
    {
        $id = $this->logs->add(
            spaceId: 1,
            kind: MailLogHelper::KIND_TEMPLATE,
            to: ['alice@example.test' => 'Alice'],
            subject: 'Bienvenue abc12345',
            bodyHtml: '<p>Mot de passe : abc12345</p>',
            cc: ['copy@example.test'],
            templateKey: 'plg_core__admin_account_welcome',
            locale: 'fr',
            secrets: ['abc12345'],
            fromEmail: 'source@example.test',
            fromName: 'Source',
        );

        self::assertNotNull($id);

        $row = $this->db->selectOneOrNull('SELECT * FROM mail_logs WHERE id = %', [$id]);
        self::assertSame('processing', $row->status);
        self::assertStringContainsString('[SECRET]', $row->subject);
        self::assertStringNotContainsString('abc12345', $row->body_html);
        self::assertSame('alice@example.test', json_decode($row->to_json, true)[0]['email']);

        $this->logs->markDone((int) $id, '<message@example.test>', '<p>Texte</p>', [], 12);

        $row = $this->db->selectOneOrNull('SELECT * FROM mail_logs WHERE id = %', [$id]);
        self::assertSame('done', $row->status);
        self::assertSame('<message@example.test>', $row->message_id);
        self::assertSame('<p>Texte</p>', $row->body_text);
        self::assertSame(12, (int) $row->duration_ms);
    }

    public function testFailedStatusKeepsABoundedErrorMessageAndMasksIt(): void
    {
        $id = (int) $this->logs->add(
            spaceId: 1,
            kind: MailLogHelper::KIND_HTML,
            to: 'alice@example.test',
            subject: 'Sujet',
            bodyHtml: '<p>HTML</p>',
        );

        $this->logs->markFailed(
            $id,
            'SMTP_SEND_FAILED',
            \RuntimeException::class,
            'Refus abc12345',
            null,
            ['abc12345'],
            7,
        );

        $row = $this->db->selectOneOrNull('SELECT * FROM mail_logs WHERE id = %', [$id]);
        self::assertSame('failed', $row->status);
        self::assertSame('SMTP_SEND_FAILED', $row->error_code);
        self::assertSame('Refus [SECRET]', $row->error_message);
    }

    public function testListReturnsMetadataOnlyAndDetailDecodesContent(): void
    {
        $first = (int) $this->logs->add(
            spaceId: 1,
            kind: MailLogHelper::KIND_TEMPLATE,
            to: ['alice@example.test' => 'Alice', 'bob@example.test'],
            subject: 'Premier',
            bodyHtml: '<p>Corps complet</p>',
            cc: ['copy@example.test'],
            templateKey: 'plg_core__registration_welcome',
            locale: 'fr',
            fromEmail: 'source@example.test',
            fromName: 'Source',
        );

        $this->logs->add(
            spaceId: 1,
            kind: MailLogHelper::KIND_HTML,
            to: 'second@example.test',
            subject: 'Second',
            bodyHtml: '<p>Second corps</p>',
        );

        $list = $this->logs->listMetadata(1);
        self::assertTrue($list['truncated']);
        self::assertCount(1, $list['items']);
        self::assertArrayNotHasKey('body_html', $list['items'][0]);
        self::assertSame('second@example.test', $list['items'][0]['first_to']);
        self::assertSame(1, $list['items'][0]['to_count']);
        self::assertSame(1, $list['items'][0]['space_id']);
        self::assertSame('Second', $list['items'][0]['subject']);

        $detail = $this->logs->detail($first);
        self::assertNotNull($detail);
        self::assertSame('<p>Corps complet</p>', $detail['body_html']);
        self::assertSame('alice@example.test', $detail['to'][0]['email']);
        self::assertSame('Alice', $detail['to'][0]['name']);
        self::assertSame('bob@example.test', $detail['to'][1]['email']);
        self::assertSame('copy@example.test', $detail['cc'][0]['email']);
        self::assertSame('source@example.test', $detail['from_email']);
        self::assertSame('Source', $detail['from_name']);
        self::assertSame('plg_core__registration_welcome', $detail['template_key']);
        self::assertSame('fr', $detail['locale']);
    }

    public function testPurgeDeletesRowsInBatches(): void
    {
        $this->logs->add(
            spaceId: 1, kind: MailLogHelper::KIND_HTML,
            to: 'a@example.test', subject: 'A', bodyHtml: '<p>A</p>',
        );
        $this->logs->add(
            spaceId: 1, kind: MailLogHelper::KIND_HTML,
            to: 'b@example.test', subject: 'B', bodyHtml: '<p>B</p>',
        );

        self::assertSame(2, $this->logs->purge(0));
        self::assertSame(
            0,
            (int) $this->db->selectOneOrNull('SELECT COUNT(*) AS total FROM mail_logs')->total,
        );
    }
}
