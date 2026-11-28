<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\Event;

use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use YTassy\Tycms\Application;
use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\CoreExts\Core\Event\Event;
use YTassy\Tycms\CoreExts\Core\Event\EventDefinition;
use YTassy\Tycms\CoreExts\Core\Event\EventDefinitionRegistry;
use YTassy\Tycms\CoreExts\Core\Event\EventDispatcher;
use YTassy\Tycms\CoreExts\Core\Event\EventListenerDefinition;
use YTassy\Tycms\CoreExts\Core\Event\EventListenerRegistry;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Event\IEventListener;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class EventDispatcherTest extends FrameworkTestCase
{
    protected function setUp(): void
    {
        DispatcherProbeRecorder::$calls = [];
        DispatcherProbeRecorder::$nextDepth = null;
    }

    #[Test]
    public function listenersRunInPriorityOrder(): void
    {
        $app = $this->createApplication();
        $this->definition($app);
        $listeners = $app->getOrAddSharedExtensionsClass(EventListenerRegistry::class);

        $listeners->register(new EventListenerDefinition(
            'core.listener.after',
            ['core.user.role.assigned'],
            DispatcherAfterListener::class,
            20,
        ));
        $listeners->register(new EventListenerDefinition(
            'core.listener.probe',
            ['core.user.role.assigned'],
            DispatcherProbeListener::class,
            10,
        ));

        ($this->dispatcher($app))->dispatch($this->event($app));

        self::assertSame(['probe', 'after'], DispatcherProbeRecorder::$calls);
    }

    #[Test]
    public function listenerSeesTheChildDepth(): void
    {
        $app = $this->createApplication();
        $this->definition($app);
        $app->getOrAddSharedExtensionsClass(EventListenerRegistry::class)
            ->register(new EventListenerDefinition(
                'core.listener.probe',
                ['core.user.role.assigned'],
                DispatcherProbeListener::class,
                10,
            ));

        ($this->dispatcher($app))->dispatch($this->event($app));

        self::assertSame(2, DispatcherProbeRecorder::$nextDepth);
    }

    #[Test]
    public function criticalFailurePropagatesAndStopsTheChain(): void
    {
        $app = $this->createApplication();
        $this->definition($app);
        $listeners = $app->getOrAddSharedExtensionsClass(EventListenerRegistry::class);

        $listeners->register(new EventListenerDefinition(
            'core.listener.failure',
            ['core.user.role.assigned'],
            DispatcherCriticalFailureListener::class,
            10,
        ));
        $listeners->register(new EventListenerDefinition(
            'core.listener.after',
            ['core.user.role.assigned'],
            DispatcherAfterListener::class,
            20,
        ));

        $dispatcher = $this->dispatcher($app);

        try {
            $dispatcher->dispatch($this->event($app));
            self::fail('L’échec critique aurait dû remonter.');
        } catch (RuntimeException $exception) {
            self::assertSame('critical', $exception->getMessage());
        }

        self::assertSame([], DispatcherProbeRecorder::$calls);
        self::assertSame(0, $dispatcher->currentDepth());
    }

    #[Test]
    public function optionalFailureIsIgnoredAndTheChainContinues(): void
    {
        $app = $this->createApplication();
        $this->definition($app);
        $listeners = $app->getOrAddSharedExtensionsClass(EventListenerRegistry::class);

        $listeners->register(new EventListenerDefinition(
            'core.listener.optional',
            ['core.user.role.assigned'],
            DispatcherOptionalFailureListener::class,
            10,
            critical: false,
        ));
        $listeners->register(new EventListenerDefinition(
            'core.listener.after',
            ['core.user.role.assigned'],
            DispatcherAfterListener::class,
            20,
        ));

        ($this->dispatcher($app))->dispatch($this->event($app));

        self::assertSame(['after'], DispatcherProbeRecorder::$calls);
    }

    #[Test]
    public function chainDeeperThanTheLimitIsNotDispatched(): void
    {
        $app = $this->createApplication();
        $this->definition($app);
        $app->getOrAddSharedExtensionsClass(EventListenerRegistry::class)
            ->register(new EventListenerDefinition(
                'core.listener.probe',
                ['core.user.role.assigned'],
                DispatcherProbeListener::class,
                10,
            ));

        ($this->dispatcher($app))->dispatch($this->event($app), EventDispatcher::MAX_DEPTH + 1);

        self::assertSame([], DispatcherProbeRecorder::$calls);
    }

    private function dispatcher(Application $app): EventDispatcher
    {
        $dispatcher = $app->getOrAddSharedExtensionsClass(EventDispatcher::class);
        self::assertInstanceOf(EventDispatcher::class, $dispatcher);

        return $dispatcher;
    }

    private function definition(Application $app): EventDefinition
    {
        $definition = new EventDefinition(
            id: 'core.user.role.assigned',
            label: 'Rôle attribué',
            description: 'Un rôle est attribué à un compte.',
        );

        $app->getOrAddSharedExtensionsClass(EventDefinitionRegistry::class)->register($definition);

        return $definition;
    }

    private function event(Application $app): Event
    {
        return new Event(
            $app->getOrAddSharedExtensionsClass(EventDefinitionRegistry::class)
                ->get('core.user.role.assigned'),
            1,
            [],
            [],
        );
    }
}

final class DispatcherProbeRecorder
{
    /** @var list<string> */
    public static array $calls = [];

    public static ?int $nextDepth = null;
}

final class DispatcherProbeListener extends BaseApplicationItem implements IEventListener
{
    public function handle(Event $event): void
    {
        DispatcherProbeRecorder::$calls[] = 'probe';
        DispatcherProbeRecorder::$nextDepth = $this->getSharedExtensionsClass(EventDispatcher::class)->nextDepth();
    }
}

final class DispatcherAfterListener extends BaseApplicationItem implements IEventListener
{
    public function handle(Event $event): void
    {
        DispatcherProbeRecorder::$calls[] = 'after';
    }
}

final class DispatcherCriticalFailureListener extends BaseApplicationItem implements IEventListener
{
    public function handle(Event $event): void
    {
        throw new RuntimeException('critical');
    }
}

final class DispatcherOptionalFailureListener extends BaseApplicationItem implements IEventListener
{
    public function handle(Event $event): void
    {
        throw new RuntimeException('optional');
    }
}
