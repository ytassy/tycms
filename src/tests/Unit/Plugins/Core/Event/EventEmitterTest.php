<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\Event;

use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use YTassy\Tycms\Application;
use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\CoreExts\Core\Event\Event;
use YTassy\Tycms\CoreExts\Core\Event\EventAudience;
use YTassy\Tycms\CoreExts\Core\Event\EventDefinition;
use YTassy\Tycms\CoreExts\Core\Event\EventDefinitionRegistry;
use YTassy\Tycms\CoreExts\Core\Event\EventEmitter;
use YTassy\Tycms\CoreExts\Core\Event\EventListenerDefinition;
use YTassy\Tycms\CoreExts\Core\Event\EventListenerRegistry;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Event\IEventListener;
use YTassy\Tycms\CoreExts\Core\Helper\Acl\AclUserResolver;
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
            type: 'core.user.role.assigned',
            spaceId: 4,
            data: ['user' => ['id' => 12]],
            audience: EventAudience::users([5, 5, 8]),
            actorId: 42,
        );

        self::assertSame('/administrator/users/12', $event->urlPath);
        self::assertSame([5, 8], $event->recipientUserIds);
        self::assertSame($event, EmitterProbeRecorder::$event);
    }

    #[Test]
    public function permissionAudienceIsResolvedWithTheCapPlusOne(): void
    {
        $app = $this->createApplication();
        $this->registerDefinition($app, static fn (Event $event): string => '/administrator');

        $resolver = $this->createMock(AclUserResolver::class);
        $resolver->expects(self::once())
            ->method('usersWithGlobalPermission')
            ->with('plg_core__USER_ROLES_READ', 4, 2001)
            ->willReturn([3, 4]);

        $this->inject($app, AclUserResolver::class, $resolver);

        $event = (new EventEmitter($app))->emit(
            type: 'core.user.role.assigned',
            spaceId: 4,
            audience: EventAudience::globalPermission('plg_core__USER_ROLES_READ', 4),
        );

        self::assertSame([3, 4], $event->recipientUserIds);
    }

    #[Test]
    public function recipientCapIsClampedToOne(): void
    {
        $app = $this->createApplication();
        $app->getConf()->notification_max_recipients = 0;

        self::assertSame(1, (new EventEmitter($app))->maxRecipients());
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

    private function inject(Application $app, string $class, object $instance): void
    {
        $classes = (new ReflectionProperty(Application::class, 'sharedExtensionsClasses'))->getValue($app);
        $classes[$class] = $instance;
        (new ReflectionProperty(Application::class, 'sharedExtensionsClasses'))->setValue($app, $classes);
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
