<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\Event;

use PHPUnit\Framework\Attributes\Test;
use YTassy\Tycms\Application;
use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\CoreExts\Core\Event\Event;
use YTassy\Tycms\CoreExts\Core\Event\EventDefinition;
use YTassy\Tycms\CoreExts\Core\Event\EventDefinitionRegistry;
use YTassy\Tycms\CoreExts\Core\Event\EventEmitter;
use YTassy\Tycms\CoreExts\Core\Event\EventListenerDefinition;
use YTassy\Tycms\CoreExts\Core\Event\EventListenerRegistry;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Event\IEventListener;
use YTassy\Tycms\Exceptions\TechException;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class EventEmitterTest extends FrameworkTestCase
{
    protected function setUp(): void
    {
        EmitterProbeRecorder::$event = null;
    }

    #[Test]
    public function emitBuildsTheEventResolvesTheLinkAndDispatches(): void
    {
        $app = $this->createApplication();
        $this->registerDefinition($app, static fn (Event $event): string
            => '/administrator/users/' . $event->data['user']['id']);
        $this->registerProbe($app);

        $event = (new EventEmitter($app))->emit(
            'core.user.role.assigned',
            4,
            ['user' => ['id' => 12]],
            [5, 5, 8],
            42,
        );

        self::assertSame('/administrator/users/12', $event->urlPath);
        self::assertSame([5, 8], $event->recipientUserIds);
        self::assertSame($event, EmitterProbeRecorder::$event);
    }

    #[Test]
    public function undefinedTypeIsRefused(): void
    {
        $this->expectException(TechException::class);

        (new EventEmitter($this->createApplication()))->emit('core.event.unknown', 1);
    }

    #[Test]
    public function linkMustReturnAStringOrNull(): void
    {
        $app = $this->createApplication();
        $this->registerDefinition($app, static fn (Event $event): array => ['/administrator']);

        $this->expectException(TechException::class);

        (new EventEmitter($app))->emit('core.user.role.assigned', 1);
    }

    private function registerDefinition(Application $app, \Closure $link): void
    {
        $app->getOrAddSharedExtensionsClass(EventDefinitionRegistry::class)->register(
            new EventDefinition(
                id: 'core.user.role.assigned',
                label: 'Rôle attribué',
                description: 'Un rôle est attribué à un compte.',
                notifiable: true,
                title: 'Rôle attribué',
                bodySelf: 'Vous avez attribué le rôle {role.name}.',
                bodyOthers: '{actor.name} a attribué le rôle {role.name}.',
                link: $link,
            ),
        );
    }

    private function registerProbe(Application $app): void
    {
        $app->getOrAddSharedExtensionsClass(EventListenerRegistry::class)->register(
            new EventListenerDefinition(
                'core.listener.probe',
                ['core.user.role.assigned'],
                EmitterProbeListener::class,
                10,
            ),
        );
    }
}

final class EmitterProbeRecorder
{
    public static ?Event $event = null;
}

final class EmitterProbeListener extends BaseApplicationItem implements IEventListener
{
    public function handle(Event $event): void
    {
        EmitterProbeRecorder::$event = $event;
    }
}
