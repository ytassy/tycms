<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionProperty;
use YTassy\Tycms\Application;
use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\CoreExts\Core\Helper\Acl\AclHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Space\SpaceHelper;
use YTassy\Tycms\CoreExts\Core\Models\Space;
use YTassy\Tycms\CoreExts\Core\Plugin;
use YTassy\Tycms\Request\Request;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Response\RedirectResponse;
use YTassy\Tycms\Routing\Controller\BaseController;
use YTassy\Tycms\Routing\Router;
use YTassy\Tycms\Session\Session;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class SpaceSelectionTest extends FrameworkTestCase
{
    #[Test]
    public function firstRequestSelectsTheSpaceForTheHostBeforeRoutesAreBuilt(): void
    {
        [$helper, $session] = $this->context(null, 'site.example');

        $helper->resolveInitialSpace();

        self::assertSame(2, $session->getSpaceId());
    }

    #[Test]
    public function anExistingSessionChoiceWinsOverTheHost(): void
    {
        [$helper, $session] = $this->context(1, 'site.example');

        $helper->resolveInitialSpace();

        self::assertSame(1, $session->getSpaceId());
    }

    #[Test]
    public function aDeletedSessionSpaceFallsBackToTheHost(): void
    {
        [$helper, $session] = $this->context(99, 'site.example');

        $helper->resolveInitialSpace();

        self::assertSame(2, $session->getSpaceId());
    }

    #[Test]
    public function anAmbiguousHostDoesNotPickAnArbitrarySpace(): void
    {
        [$helper, $session] = $this->context(null, 'site.example', true);

        $helper->resolveInitialSpace();

        self::assertNull($session->getSpaceId());
    }

    #[Test]
    #[DataProvider('localHostContexts')]
    public function spaceChoiceAndInitialSelectionUseTheSameHostComparison(
        bool $dev,
        string $configuredHost,
        int $port,
        bool $ambiguous,
        array $expectedIds,
        ?int $expectedSelection,
    ): void {
        [$helper, $session] = $this->context(null, 'localhost', $ambiguous, $configuredHost, $port, $dev);

        self::assertSame($expectedIds, array_map(
            static fn (Space $space): int => $space->id,
            $helper->getDefaultSpacesFromContext(),
        ));

        $helper->resolveInitialSpace();

        self::assertSame($expectedSelection, $session->getSpaceId());
    }

    public static function localHostContexts(): iterable
    {
        yield 'deux Spaces sur le même port local' => [true, 'localhost:8088', 8088, true, [1, 2], null];
        yield 'un seul Space sur le port local' => [true, 'localhost:8088', 8088, false, [1, 2], 2];
        yield 'un autre port ne correspond pas' => [true, 'localhost:8089', 8088, false, [1], 1];
        yield 'localhost sans port reste compatible' => [true, 'localhost', 8088, false, [1, 2], 2];
        yield 'port local standard explicite' => [true, 'localhost:80', 80, false, [1, 2], 2];
        yield 'port local ignoré en production' => [false, 'localhost:8088', 8088, false, [1], 1];
    }

    #[Test]
    public function anUnknownPageRedirectsToSpaceChoiceBeforeRouteResolution(): void
    {
        $app = $this->createApplication();
        $session = $this->makeSession($app);
        (new ReflectionProperty(Application::class, 'session'))->setValue($app, $session);
        (new ReflectionProperty(Application::class, 'request'))->setValue($app, new Request(
            uri: '/unknown-page', headers: ['Accept' => 'text/html'],
        ));
        $router = new Router($app);
        $controller = new class($app) extends BaseController {
            public function choose(): void {}
        };
        $router->registerRoute('SpaceChoose', RequestMethod::GET, '/space/choose',
            $controller, 'choose', [], false);
        (new ReflectionProperty(Application::class, 'router'))->setValue($app, $router);

        $spaces = $this->getMockBuilder(SpaceHelper::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getDefaultSpacesFromContext'])
            ->getMock();
        $spaces->expects(self::once())->method('getDefaultSpacesFromContext')
            ->willReturn([new Space($app), new Space($app)]);
        (new ReflectionProperty(Application::class, 'sharedExtensionsClasses'))->setValue($app, [
            SpaceHelper::class => $spaces,
        ]);

        try {
            (new Plugin($app))->onBeforeResolveRoute();
            self::fail('An ambiguous Space must redirect before resolving an unknown route.');
        } catch (ExceptionResponse $exception) {
            self::assertInstanceOf(RedirectResponse::class, $exception->getResponse());
            self::assertSame('/space/choose', $exception->getResponse()->httpHeaders['Location']);
        }
    }

    /** @return array{SpaceHelper, Session} */
    private function context(
        ?int $selectedId,
        string $host,
        bool $ambiguousHost = false,
        string $configuredHost = 'site.example',
        int $port = 80,
        bool $dev = true,
    ): array
    {
        $app = $this->createApplication();
        $app->getConf()->dev = $dev;
        $acl = $this->createStub(AclHelper::class);
        $acl->method('getSpacesHavingGlobalPermission')->willReturn([1]);
        (new ReflectionProperty(Application::class, 'sharedExtensionsClasses'))->setValue($app, [
            AclHelper::class => $acl,
        ]);

        $session = $this->makeSession($app);
        $session->setSpaceId($selectedId);
        (new ReflectionProperty(Application::class, 'session'))->setValue($app, $session);
        (new ReflectionProperty(Application::class, 'request'))->setValue($app, new Request(host: $host, port: $port));

        $root = new Space($app);
        $root->id = 1;
        $root->hosts = $ambiguousHost ? [$configuredHost] : ['root.example'];
        $site = new Space($app);
        $site->id = 2;
        $site->hosts = [$configuredHost];

        $helper = (new ReflectionClass(SpaceHelper::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(BaseApplicationItem::class, '_app'))->setValue($helper, $app);
        (new ReflectionProperty(SpaceHelper::class, 'spaces'))->setValue($helper, [1 => $root, 2 => $site]);

        return [$helper, $session];
    }

    private function makeSession(Application $app): Session
    {
        return new class($app) extends Session {
            private array $values = [];

            public function initialize(): void {}
            public function destroy(): void { $this->values = []; }
            public function rotateId(): void {}
            public function get(string $key, string|array|object|int|float|bool|null $defaultValue = null): string|array|object|int|float|bool|null
            {
                return $this->values[$key] ?? $defaultValue;
            }
            public function set(string $key, string|array|object|int|float|bool|null $value): void
            {
                $this->values[$key] = $value;
            }
            public function remove(string $key): void
            {
                unset($this->values[$key]);
            }
        };
    }
}
