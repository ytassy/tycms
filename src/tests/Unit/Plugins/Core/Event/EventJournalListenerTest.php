<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\Event;

use Configuration;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use RuntimeException;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Core\Event\Event;
use YTassy\Tycms\CoreExts\Core\Event\EventDefinition;
use YTassy\Tycms\CoreExts\Core\Event\EventJournalListener;
use YTassy\Tycms\CoreExts\Core\Helper\Event\EventLogHelper;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

/**
 * Contrat de l'écouteur de journal.
 *
 * L'écouteur est critique : il ne masque pas une erreur d'écriture, mais il
 * respecte `log = false` et affecte l'identifiant de ligne à l'événement.
 */
final class EventJournalListenerTest extends FrameworkTestCase
{
    #[Test]
    public function eventWithoutJournalDoesNotWriteAndKeepsNoLogId(): void
    {
        $helper = $this->createMock(EventLogHelper::class);
        $helper->expects(self::never())->method('write');

        $event = $this->event(log: false);

        $this->listener($helper)->handle($event);

        self::assertNull($event->logId);
    }

    #[Test]
    public function journalListenerStoresTheLineIdentifier(): void
    {
        $helper = $this->createMock(EventLogHelper::class);
        $helper->expects(self::once())
            ->method('write')
            ->with(self::isInstanceOf(Event::class))
            ->willReturn(1234);

        $event = $this->event(log: true);

        $this->listener($helper)->handle($event);

        self::assertSame(1234, $event->logId);
    }

    #[Test]
    public function writeFailureIsRethrownBecauseTheListenerIsCritical(): void
    {
        $failure = new RuntimeException('base indisponible');

        $helper = $this->createStub(EventLogHelper::class);
        $helper->method('write')->willThrowException($failure);

        try {
            $this->listener($helper)->handle($this->event(log: true));
            self::fail('L’échec d’écriture aurait dû remonter à l’appelant.');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }
    }

    private function listener(EventLogHelper $helper): EventJournalListener
    {
        $configuration = new Configuration();
        $configuration->debug = true;
        $configuration->dev = true;
        $configuration->session_handler = 'php';
        // Le journal applicatif du test ne doit rien écrire dans le dépôt.
        $logRoot = sys_get_temp_dir() . '/tycms-event-listener-' . bin2hex(random_bytes(6));
        if (!is_dir($logRoot)) mkdir($logRoot, 0775, true);
        $configuration->log_path = $logRoot;

        $app = new Application($configuration);
        new ReflectionProperty(Application::class, 'sharedExtensionsClasses')
            ->setValue($app, [EventLogHelper::class => $helper]);

        return new EventJournalListener($app);
    }

    private function event(bool $log): Event
    {
        $definition = new EventDefinition(
            id: 'core.test.event',
            label: 'Événement de test',
            description: 'Occurrence utilisée par le test de l’écouteur de journal.',
            log: $log,
        );

        return new Event($definition, 1, ['user' => ['id' => 5, 'name' => 'Alice']], [5], 1);
    }
}
