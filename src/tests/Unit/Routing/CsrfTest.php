<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Routing;

use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use YTassy\Tycms\Application;
use YTassy\Tycms\ApplicationRouteRunner;
use YTassy\Tycms\DB\Database;
use YTassy\Tycms\Request\Request;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Response\ApiResponse;
use YTassy\Tycms\Routing\Annotation\Doc\Controller;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerMethod;
use YTassy\Tycms\Routing\Controller\BaseController;
use YTassy\Tycms\Routing\Route;
use YTassy\Tycms\Routing\Router;
use YTassy\Tycms\Session\Session;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class CsrfTest extends FrameworkTestCase
{
    private function makeSession(Application $app): Session
    {
        return new class($app) extends Session {
            private array $data = [];

            public function initialize(): void {}
            public function destroy(): void { $this->data = []; }
            public function rotateId(): void {}
            public function get(string $key, string|array|object|int|float|bool|null $defaultValue = null): string|array|object|int|float|bool|null
            {
                return $this->data[$key] ?? $defaultValue;
            }
            public function set(string $key, string|array|object|int|float|bool|null $value): void
            {
                $this->data[$key] = $value;
            }
            public function remove(string $key): void
            {
                unset($this->data[$key]);
            }
        };
    }

    #[Test]
    public function tokenIsStableUntilTheUserChanges(): void
    {
        $session = $this->makeSession($this->createApplication());

        self::assertFalse($session->verifyCsrfToken('anything'));
        $token = $session->getCsrfToken();
        self::assertSame(64, strlen($token));
        self::assertSame($token, $session->getCsrfToken());
        self::assertTrue($session->verifyCsrfToken($token));
        self::assertFalse($session->verifyCsrfToken(['value' => $token]));

        $session->setUser(7);
        self::assertFalse($session->verifyCsrfToken($token));
        $newToken = $session->getCsrfToken();
        self::assertNotSame($token, $newToken);
        $session->setUser(7);
        self::assertSame($newToken, $session->getCsrfToken());
    }

    #[Test]
    public function unsafeApiRequestWithoutValidTokenIsRejectedBeforeControllerExecution(): void
    {
        $app = $this->createApplication();
        $session = $this->makeSession($app);
        (new ReflectionProperty(Application::class, 'session'))->setValue($app, $session);
        $requestProperty = new ReflectionProperty(Application::class, 'request');
        $controller = new class($app) extends BaseController {
            public function action(): ApiResponse
            {
                throw new \LogicException('The controller must not run.');
            }
        };
        $runner = new ApplicationRouteRunner($app);

        foreach ([[], ['X-CSRF-Token' => 'invalid']] as $headers) {
            $requestProperty->setValue($app, new Request(RequestMethod::POST, '/api/test', headers: $headers));
            $route = new Route('Test', $controller, 'action', true, [], [], [], true, false, false, false, []);
            $response = $runner->run($route);

            self::assertInstanceOf(ApiResponse::class, $response);
            self::assertSame(403, $response->code);
            self::assertTrue(json_decode($response->body, true)['error']);
        }
    }

    #[Test]
    public function validTokenAllowsAnUnsafeApiRequest(): void
    {
        $app = $this->createApplication();
        $session = $this->makeSession($app);
        (new ReflectionProperty(Application::class, 'session'))->setValue($app, $session);
        (new ReflectionProperty(Application::class, 'request'))->setValue($app, new Request(
            RequestMethod::POST,
            '/api/test',
            headers: ['x-csrf-token' => $session->getCsrfToken()],
        ));
        $database = $this->createMock(Database::class);
        $database->expects(self::once())->method('execute')->with('COMMIT');
        (new ReflectionProperty(Application::class, 'database'))->setValue($app, $database);
        $controller = new class($app) extends BaseController {
            public function action(): ApiResponse
            {
                return new ApiResponse(['result' => 'ok']);
            }
        };
        $route = new Route('Test', $controller, 'action', true, [], [], [], true, false, false, false, []);

        $response = (new ApplicationRouteRunner($app))->run($route);

        self::assertSame(200, $response->code);
        self::assertSame('ok', json_decode($response->body, true)['result']);
    }

    #[Test]
    public function controllerOptOutIsCarriedToResolvedRoute(): void
    {
        $app = $this->createApplication();
        $router = new Router($app);
        $controller = new CsrfExemptController($app);
        $register = new \ReflectionMethod(Router::class, 'registerController');
        $register->invoke($router, $controller);

        $route = $router->resolve(new Request(RequestMethod::POST, '/csrf-exempt/write'));

        self::assertTrue($route->isApi);
        self::assertTrue($route->withoutCsrf);
    }
}

#[Controller(name: 'CsrfExempt', path: 'csrf-exempt', isApi: true, withoutCsrf: true)]
final class CsrfExemptController extends BaseController
{
    #[ControllerMethod(name: 'Write', path: 'write', methods: [RequestMethod::POST])]
    public function write(): ApiResponse
    {
        return new ApiResponse([]);
    }
}
