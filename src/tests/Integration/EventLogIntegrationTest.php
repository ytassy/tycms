<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Core\Event\Event;
use YTassy\Tycms\CoreExts\Core\Event\EventActorType;
use YTassy\Tycms\CoreExts\Core\Event\EventDefinition;
use YTassy\Tycms\CoreExts\Core\Event\EventJournalListener;
use YTassy\Tycms\CoreExts\Core\Helper\Admin\ExtensionsHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Event\EventLogHelper;
use YTassy\Tycms\CoreExts\Core\WF\WFAdminUser;
use YTassy\Tycms\DB\Database;
use YTassy\Tycms\DB\FormatIn;
use YTassy\Tycms\Request\Request;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Session\Session;
use YTassy\Tycms\Tests\Support\Integration\TestDatabase;
use YTassy\Tycms\Tests\Support\Integration\TestEnvironment;
use YTassy\Tycms\Tests\Support\Integration\TestFixtures;

/**
 * Écriture et purge du journal d'événements sur la base isolée.
 *
 * Chaque scénario tourne dans une transaction annulée : aucune ligne ne
 * survit au test. La purge est testée avec des dates explicites, sans attendre.
 */
final class EventLogIntegrationTest extends TestCase
{
    private Application $app;

    private Database $db;

    private EventLogHelper $logs;

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

        /** @var EventLogHelper $logs */
        $logs = $this->app->getOrAddSharedExtensionsClass(EventLogHelper::class);
        $this->logs = $logs;
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->execute('ROLLBACK');
        }
    }

    #[Test]
    public function journalRowDescribesTheOccurrence(): void
    {
        $occurredAt = new DateTimeImmutable('2026-01-02 03:04:05', new DateTimeZone('UTC'));
        $event = $this->event(
            spaceId: TestFixtures::SITE_SPACE_ID,
            occurredAt: $occurredAt,
            category: EventDefinition::CATEGORY_TECHNICAL,
            severity: EventDefinition::SEVERITY_WARNING,
        );

        (new EventJournalListener($this->app))->handle($event);

        self::assertNotNull($event->logId);
        $row = $this->db->selectOneOrNull('SELECT * FROM event_log WHERE id = %', [$event->logId]);
        self::assertNotNull($row);

        self::assertSame(TestFixtures::SITE_SPACE_ID, (int) $row->space_id);
        self::assertSame('core.test.event', $row->event_type);
        self::assertSame(EventDefinition::CATEGORY_TECHNICAL, $row->category);
        self::assertSame(EventDefinition::SEVERITY_WARNING, $row->severity);
        self::assertSame(1, (int) $row->actor_id);
        self::assertSame('user', $row->actor_type);
        self::assertSame('user', $row->subject_type);
        self::assertSame(12, (int) $row->subject_id);
        self::assertSame('2026-01-02 03:04:05', $row->created_at);
        self::assertSame(
            ['user' => ['id' => 12, 'name' => 'Alice'], 'role' => ['id' => 4, 'name' => 'Administrateur']],
            json_decode((string) $row->data, true, flags: JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function eventWithoutJournalWritesNothing(): void
    {
        $event = $this->event(log: false);

        $before = $this->highestId();

        (new EventJournalListener($this->app))->handle($event);

        self::assertNull($event->logId);
        self::assertSame($before, $this->highestId());
    }

    #[Test]
    public function journalWriteFollowsTheCallerTransaction(): void
    {
        $event = $this->event();

        $before = $this->highestId();

        (new EventJournalListener($this->app))->handle($event);
        self::assertNotNull($event->logId);
        self::assertNotNull($this->db->selectOneOrNull(
            'SELECT id FROM event_log WHERE id = %', [$event->logId],
        ));

        // Sans commit, la ligne disparaît : le journal ne survit pas à l'annulation métier.
        $this->db->execute('ROLLBACK');

        self::assertNull($this->db->selectOneOrNull(
            'SELECT id FROM event_log WHERE id = %', [$event->logId],
        ));
        self::assertSame($before, $this->highestId());
    }

    #[Test]
    public function purgeAppliesTheRetentionOfEachSpace(): void
    {
        // Deux Spaces, une ligne ancienne et une récente dans chacun.
        $oldSpaceOne = $this->event(spaceId: 1, occurredAt: $this->daysAgo(40));
        $recentSpaceOne = $this->event(spaceId: 1, occurredAt: $this->daysAgo(1));
        $oldSpaceTwo = $this->event(spaceId: 2, occurredAt: $this->daysAgo(40));
        $recentSpaceTwo = $this->event(spaceId: 2, occurredAt: $this->daysAgo(1));

        $ids = [];
        foreach ([$oldSpaceOne, $recentSpaceOne, $oldSpaceTwo, $recentSpaceTwo] as $event) {
            $event->logId = $this->logs->write($event);
            $ids[] = $event->logId;
        }

        // `0` = aucune limite : rien n'est supprimé.
        self::assertSame(0, $this->logs->purge(1, 0));
        self::assertSame(4, $this->countIds($ids));

        // La purge ne touche que le Space demandé et que les lignes éligibles.
        self::assertSame(1, $this->logs->purge(1, 30));
        self::assertSame(3, $this->countIds($ids));
        self::assertNull($this->db->selectOneOrNull(
            'SELECT id FROM event_log WHERE id = %', [$oldSpaceOne->logId],
        ));
        self::assertNotNull($this->db->selectOneOrNull(
            'SELECT id FROM event_log WHERE id = %', [$recentSpaceOne->logId],
        ));
        self::assertNotNull($this->db->selectOneOrNull(
            'SELECT id FROM event_log WHERE id = %', [$oldSpaceTwo->logId],
        ));

        self::assertSame(1, $this->logs->purge(2, 30));
        self::assertSame(2, $this->countIds($ids));
    }

    #[Test]
    public function purgeExpiredAppliesTheConfiguredRetentionOfEachSpace(): void
    {
        $oldSpaceOne = $this->event(spaceId: 1, occurredAt: $this->daysAgo(40));
        $oldSpaceTwo = $this->event(spaceId: 2, occurredAt: $this->daysAgo(40));
        $oldSpaceOne->logId = $this->logs->write($oldSpaceOne);
        $oldSpaceTwo->logId = $this->logs->write($oldSpaceTwo);

        /** @var ExtensionsHelper $extensions */
        $extensions = $this->app->getOrAddSharedExtensionsClass(ExtensionsHelper::class);
        $extensions->saveExtensionConfig(1, 'plg_core', ['event_log_retention_days' => 30]);
        $extensions->saveExtensionConfig(2, 'plg_core', ['event_log_retention_days' => 0]);

        // Seul le Space 1 purge ; `0` conserve tout pour le Space 2.
        self::assertSame(1, $this->logs->purgeExpired());

        self::assertNull($this->db->selectOneOrNull(
            'SELECT id FROM event_log WHERE id = %', [$oldSpaceOne->logId],
        ));
        self::assertNotNull($this->db->selectOneOrNull(
            'SELECT id FROM event_log WHERE id = %', [$oldSpaceTwo->logId],
        ));
    }

    #[Test]
    public function emissionWorksWithoutRoutesForAutomationRuns(): void
    {
        // Un run d'automatisation initialise l'application sans routes. Le lien
        // de notification est alors nul : l'émission et le journal ne doivent
        // pas échouer pour autant.
        /** @var WFAdminUser $workflow */
        $workflow = $this->app->getOrAddSharedExtensionsClass(WFAdminUser::class);
        $workflow->setRole(1, 4, TestFixtures::SITE_SPACE_ID, true);

        $row = $this->db->selectOneOrNull(
            "SELECT * FROM event_log WHERE event_type = 'core.user.role.assigned'
                AND subject_id = 1 ORDER BY id DESC LIMIT 1",
        );

        self::assertNotNull($row);
        self::assertSame(TestFixtures::SITE_SPACE_ID, (int) $row->space_id);
        self::assertSame(1, (int) $row->actor_id);
    }

    private function highestId(): int
    {
        $row = $this->db->selectOneOrNull('SELECT MAX(id) AS highest FROM event_log');

        return (int) ($row->highest ?? 0);
    }

    /** @param list<int> $ids */
    private function countIds(array $ids): int
    {
        $row = $this->db->selectOneOrNull(
            'SELECT COUNT(*) AS total FROM event_log WHERE id IN (%)',
            [FormatIn::get($ids)],
        );

        return (int) ($row->total ?? 0);
    }

    private function daysAgo(int $days): DateTimeImmutable
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify(sprintf('-%d days', $days));
    }

    private function event(
        int $spaceId = 1,
        bool $log = true,
        ?DateTimeImmutable $occurredAt = null,
        string $category = EventDefinition::CATEGORY_USER,
        string $severity = EventDefinition::SEVERITY_INFO,
    ): Event {
        $definition = new EventDefinition(
            id: 'core.test.event',
            label: 'Événement de test',
            description: 'Occurrence écrite par le test du journal.',
            category: $category,
            severity: $severity,
            log: $log,
        );

        return new Event(
            $definition,
            $spaceId,
            ['user' => ['id' => 12, 'name' => 'Alice'], 'role' => ['id' => 4, 'name' => 'Administrateur']],
            [],
            1,
            EventActorType::USER,
            'user',
            12,
            null,
            $occurredAt,
        );
    }
}
