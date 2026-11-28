<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\Event;

use PHPUnit\Framework\Attributes\Test;
use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\CoreExts\Core\Event\Event;
use YTassy\Tycms\CoreExts\Core\Event\EventDefinition;
use YTassy\Tycms\CoreExts\Core\Event\EventDefinitionRegistry;
use YTassy\Tycms\CoreExts\Core\Event\EventListenerDefinition;
use YTassy\Tycms\CoreExts\Core\Event\EventListenerRegistry;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Event\IEventListener;
use YTassy\Tycms\Exceptions\TechException;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class EventListenerRegistryTest extends FrameworkTestCase
{
    #[Test]
    public function listenersAreSortedByPriority(): void
    {
        $registry = new EventListenerRegistry($this->createApplication());

        $registry->register($this->listener('core.listener.late', 80));
        $registry->register($this->listener('core.listener.early', 20));

        $ids = array_map(
            static fn (EventListenerDefinition $definition): string => $definition->id,
            $registry->forEvent('core.user.role.assigned'),
        );

        self::assertSame(['core.listener.early', 'core.listener.late'], $ids);
    }

    #[Test]
    public function equalPrioritiesKeepRegistrationOrder(): void
    {
        $registry = new EventListenerRegistry($this->createApplication());

        $registry->register($this->listener('core.listener.first', 50));
        $registry->register($this->listener('core.listener.second', 50));

        $ids = array_map(
            static fn (EventListenerDefinition $definition): string => $definition->id,
            $registry->forEvent('core.user.role.assigned'),
        );

        self::assertSame(['core.listener.first', 'core.listener.second'], $ids);
    }

    #[Test]
    public function duplicateListenerIdentifierIsRefused(): void
    {
        $registry = new EventListenerRegistry($this->createApplication());
        $registry->register($this->listener('core.listener.one', 50));

        $this->expectException(TechException::class);

        $registry->register($this->listener('core.listener.one', 60));
    }

    #[Test]
    public function unknownEventTypeIsRefusedAfterRegistration(): void
    {
        $definitions = new EventDefinitionRegistry($this->createApplication());
        $definitions->register(new EventDefinition(
            id: 'core.user.role.assigned',
            label: 'Rôle attribué',
            description: 'Un rôle est attribué à un compte.',
        ));

        $listeners = new EventListenerRegistry($this->createApplication());
        $listeners->register(new EventListenerDefinition(
            'core.listener.unknown',
            ['core.event.unknown'],
            PriorityListener::class,
        ));

        $this->expectException(TechException::class);
        $this->expectExceptionMessageMatches('/core\.event\.unknown/');

        $listeners->assertEventTypesKnown($definitions);
    }

    private function listener(string $id, int $priority): EventListenerDefinition
    {
        return new EventListenerDefinition(
            $id,
            ['core.user.role.assigned'],
            PriorityListener::class,
            $priority,
        );
    }
}

final class PriorityListener extends BaseApplicationItem implements IEventListener
{
    public function handle(Event $event): void
    {
    }
}
