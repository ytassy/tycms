<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Routing;

use PHPUnit\Framework\Attributes\DataProvider;
use YTassy\Tycms\Application;
use YTassy\Tycms\ApplicationRouteRunner;
use YTassy\Tycms\CoreExts\Core\Controllers\PasswordResetController;
use YTassy\Tycms\CoreExts\Core\Controllers\SpaceController;
use YTassy\Tycms\CoreExts\Core\Helper\Space\SpaceHelper;
use YTassy\Tycms\CoreExts\Core\Models\Space;
use YTassy\Tycms\CoreExts\Core\Views\SpaceChoose;
use YTassy\Tycms\DB\Database;
use YTassy\Tycms\Request\Request;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Response\ApiResponse;
use YTassy\Tycms\Routing\Annotation\Doc\Controller;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerMethod;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerParam1;
use YTassy\Tycms\Routing\Annotation\Doc\VarType;
use YTassy\Tycms\Routing\Controller\BaseController;
use YTassy\Tycms\Routing\Router;
use YTassy\Tycms\Session\Session;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;
use YTassy\Tycms\User\PendingMessageQueue;

final class HttpSecurityTest extends FrameworkTestCase
{
    private function set(Application $app, string $property, mixed $value): void
    {
        (new \ReflectionProperty(Application::class, $property))->setValue($app, $value);
    }

    private function fixture(Request $request): array
    {
        $app = $this->createApplication();
        $app->getConf()->log_path = sys_get_temp_dir() . '/tycms-http-security-tests';
        $session = new HttpSecurityMemorySession($app);
        $session->setSpaceId(1);
        $this->set($app, 'session', $session);
        $this->set($app, 'request', $request);
        $this->set($app, 'database', $this->createStub(Database::class));
        $this->set($app, 'pendingMessageQueue', new PendingMessageQueue($app));
        $router = new Router($app);
        $this->set($app, 'router', $router);
        return [$app, $session, $router];
    }

    private function register(Router $router, BaseController $controller): void
    {
        (new \ReflectionMethod(Router::class, 'registerController'))->invoke($router, $controller);
    }

    #[DataProvider('formReturnTargets')]
    public function testRejectedFormNeverRedirectsToAnUntrustedAuthority(string $uri, string $expected): void
    {
        $request = new Request(RequestMethod::POST, $uri, body: ['email' => 'nobody@example.invalid', 'csrf_token' => 'invalide']);
        [$app, , $router] = $this->fixture($request);
        $this->register($router, new PasswordResetController($app));
        $route = $router->resolve($request);
        self::assertNotNull($route);
        self::assertFalse($route->hasErrors());

        $response = (new ApplicationRouteRunner($app))->run($route);

        self::assertSame(303, $response->code);
        self::assertSame($expected, $response->httpHeaders['Location']);
    }

    public static function formReturnTargets(): iterable
    {
        yield ['/forgot-password?from=%2Ffr%2Fcompte', '/forgot-password?from=%2Ffr%2Fcompte'];
        yield ['//offsite.example/forgot-password', '/'];
        yield ['https://offsite.example/forgot-password', '/'];
    }

    public function testSpaceChoiceRejectsMissingOrInvalidCsrfBeforeLoadingTheSpace(): void
    {
        foreach ([null, 'invalide'] as $token) {
            $body = ['id' => '22'];
            if ($token !== null) $body['csrf_token'] = $token;
            $request = new Request(RequestMethod::POST, '/space/choose', body: $body);
            [$app, $session, $router] = $this->fixture($request);
            $helper = $this->getMockBuilder(SpaceHelper::class)->disableOriginalConstructor()->onlyMethods(['getSpace'])->getMock();
            $helper->expects(self::never())->method('getSpace');
            $this->set($app, 'sharedExtensionsClasses', [SpaceHelper::class => $helper]);
            $this->register($router, new SpaceController($app));
            $route = $router->resolve($request);

            if ($token === null) {
                self::assertTrue($route->hasErrors());
            } else {
                $response = (new ApplicationRouteRunner($app))->run($route);
                self::assertSame(303, $response->code);
                self::assertSame('/space/choose', $response->httpHeaders['Location']);
            }
            self::assertSame(1, $session->getSpaceId());
        }
    }

    public function testSpaceChoiceAcceptsTheSessionTokenAndRendersItInTheForm(): void
    {
        $request = new Request(RequestMethod::POST, '/space/choose', body: ['id' => '22']);
        [$app, $session, $router] = $this->fixture($request);
        $token = $session->getCsrfToken();
        $request->body['csrf_token'] = $token;
        $space = new Space($app);
        $space->id = 22;
        $space->title = 'Space de test';
        $space->params = (object) [];
        $helper = $this->getMockBuilder(SpaceHelper::class)->disableOriginalConstructor()
            ->onlyMethods(['getSpace', 'getDefaultSpacesFromContext'])->getMock();
        $helper->expects(self::once())->method('getSpace')->willReturn($space);
        $helper->expects(self::once())->method('getDefaultSpacesFromContext')->willReturn([$space]);
        $this->set($app, 'sharedExtensionsClasses', [SpaceHelper::class => $helper]);
        $controller = new SpaceController($app);
        $this->register($router, $controller);

        $html = (new SpaceChoose($controller))->render();
        self::assertStringContainsString('name="csrf_token" value="' . $token . '"', $html);
        $response = (new ApplicationRouteRunner($app))->run($router->resolve($request));
        self::assertSame(22, $session->getSpaceId());
        self::assertSame('/', $response->httpHeaders['Location']);
    }

    public function testInvalidApiParametersReturn400WithoutControllerOrSqlExecution(): void
    {
        $request = new Request(RequestMethod::POST, '/api/test', body: ['id' => ['22']]);
        [$app, $session, $router] = $this->fixture($request);
        $request->headers['X-CSRF-Token'] = $session->getCsrfToken();
        $database = $this->createMock(Database::class);
        $database->expects(self::never())->method('execute');
        $this->set($app, 'database', $database);
        $this->register($router, new HttpSecurityApiController($app));
        $route = $router->resolve($request);
        self::assertNotNull($route);
        self::assertTrue($route->hasErrors());

        $response = (new ApplicationRouteRunner($app))->run($route);

        self::assertSame(400, $response->code);
        self::assertTrue(json_decode($response->body, true)['error']);
        self::assertSame('Paramètre invalide.', json_decode($response->body, true)['message']);
    }
}

#[Controller(name: 'HttpSecurityTest', path: 'api', isApi: true)]
final class HttpSecurityApiController extends BaseController
{
    #[ControllerMethod(name: 'InvalidInput', path: 'test', methods: ['POST'])]
    #[ControllerParam1(name: 'id', required: true, varType: VarType::INT, errorUserMessage: 'Paramètre invalide.')]
    public function action(int $id): ApiResponse
    {
        throw new \LogicException('Contrôleur interdit.');
    }
}

final class HttpSecurityMemorySession extends Session
{
    private array $data = [];
    public function initialize(): void {}
    public function destroy(): void { $this->data = []; }
    public function rotateId(): void {}
    public function get(string $key, string|array|object|int|float|bool|null $defaultValue = null): string|array|object|int|float|bool|null { return $this->data[$key] ?? $defaultValue; }
    public function set(string $key, string|array|object|int|float|bool|null $value): void { $this->data[$key] = $value; }
    public function remove(string $key): void { unset($this->data[$key]); }
}
