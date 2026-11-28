<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\Event;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\CoreExts\Core\Event\Event;
use YTassy\Tycms\CoreExts\Core\Event\EventListenerDefinition;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Event\IEventListener;

final class EventListenerDefinitionTest extends TestCase
{
    #[Test]
    public function listenerMustImplementTheContractAndExtendTheApplicationItem(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new EventListenerDefinition(
            'core.listener.invalid',
            ['core.user.role.assigned'],
            ListenerWithoutApplication::class,
        );
    }

    #[Test]
    public function classMustImplementTheListenerContract(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new EventListenerDefinition(
            'core.listener.invalid',
            ['core.user.role.assigned'],
            \stdClass::class,
        );
    }

    #[Test]
    public function abstractListenerIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new EventListenerDefinition(
            'core.listener.invalid',
            ['core.user.role.assigned'],
            AbstractListener::class,
        );
    }

    #[Test]
    public function eventTypesAreUniqueAndValidated(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new EventListenerDefinition(
            'core.listener.duplicate',
            ['core.user.role.assigned', 'core.user.role.assigned'],
            ValidListener::class,
        );
    }

    #[Test]
    public function priorityIsBounded(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new EventListenerDefinition(
            'core.listener.priority',
            ['core.user.role.assigned'],
            ValidListener::class,
            EventListenerDefinition::PRIORITY_MAX + 1,
        );
    }
}

final class ValidListener extends BaseApplicationItem implements IEventListener
{
    public function handle(Event $event): void
    {
    }
}

final class ListenerWithoutApplication implements IEventListener
{
    public function handle(Event $event): void
    {
    }
}

abstract class AbstractListener extends BaseApplicationItem implements IEventListener
{
}
