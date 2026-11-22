<?php

declare(strict_types=1);

// Sondes de constat : les assertions décrivent l'état audité, parfois vulnérable.
// Ne pas les confondre avec les tests de non-régression d'un futur correctif.
use YTassy\Tycms\Application;
use YTassy\Tycms\ApplicationRouteRunner;
use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\CoreExts\Core\Controllers\PasswordResetController;
use YTassy\Tycms\CoreExts\Core\Controllers\SpaceController;
use YTassy\Tycms\CoreExts\Core\Controllers\UserController;
use YTassy\Tycms\CoreExts\Core\Helper\Language\PublicLocaleHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Space\SpaceHelper;
use YTassy\Tycms\CoreExts\Core\Models\Space;
use YTassy\Tycms\DB\Database;
use YTassy\Tycms\Request\Request;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Request\WebRequest;
use YTassy\Tycms\Routing\Router;
use YTassy\Tycms\Session\Session;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;
use YTassy\Tycms\User\PendingMessageQueue;

final class HttpAuditTest extends FrameworkTestCase
{
    private static array $observations = [];

    private function set(Application $app, string $property, mixed $value): void
    {
        (new ReflectionProperty(Application::class, $property))->setValue($app, $value);
    }

    private function fixture(Request $request): array
    {
        $app = $this->createApplication();
        $app->getConf()->dev = false;
        $app->getConf()->debug = false;
        $app->getConf()->log_path = sys_get_temp_dir() . '/tyframework-security-lot03-fixture-logs';
        $session = new AuditMemorySession($app);
        $session->setUser(7);
        $session->setSpaceId(1);
        $this->set($app, 'session', $session);
        $this->set($app, 'request', $request);
        // Aucune connexion SQL : les commandes du runner vont à un double.
        $this->set($app, 'database', $this->createStub(Database::class));
        $this->set($app, 'pendingMessageQueue', new PendingMessageQueue($app));
        $router = new Router($app);
        $this->set($app, 'router', $router);
        return [$app, $session, $router];
    }

    private function register(Router $router, object $controller): void
    {
        (new ReflectionMethod(Router::class, 'registerController'))->invoke($router, $controller);
    }

    public function testProxyHeadersAlterContextFromAnUntrustedRemoteAddress(): void
    {
        $saved = [$_SERVER, $_GET, $_POST, $_COOKIE, $_FILES];
        try {
            $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/',
                'HTTP_HOST' => 'site.example.test', 'SERVER_PORT' => '80',
                'REMOTE_ADDR' => '198.51.100.42',
                'HTTP_X_FORWARDED_HOST' => 'other.example.test',
                'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_PORT' => '443'];
            $_GET = $_POST = $_COOKIE = $_FILES = [];
            $request = new WebRequest();
            self::assertSame('other.example.test', $request->host);
            self::assertTrue($request->behind_proxy);
            self::assertTrue($request->is_https);

            [$app] = $this->fixture($request);
            $space = new Space($app);
            $space->id = 22;
            $space->hosts = ['other.example.test'];
            $helper = (new ReflectionClass(SpaceHelper::class))->newInstanceWithoutConstructor();
            (new ReflectionMethod(BaseApplicationItem::class, '__construct'))->invoke($helper, $app);
            (new ReflectionProperty(SpaceHelper::class, 'spaces'))->setValue($helper, [22 => $space]);
            self::assertSame([22], array_map(static fn ($s) => $s->id, $helper->getSpacesMatchingRequestHost()));
            self::$observations['proxy'] = ['host' => $request->host, 'https' => $request->is_https,
                'remote_addr' => $_SERVER['REMOTE_ADDR'], 'space_correspondant' => 22,
                'limite' => 'Protection du serveur web non reproduite ; aucun contournement ACL prouvé.'];
        } finally {
            [$_SERVER, $_GET, $_POST, $_COOKIE, $_FILES] = $saved;
        }
    }

    public function testRejectedCsrfCanProduceAnOffsiteRedirectFromADoubleSlashUri(): void
    {
        foreach (['/forgot-password', '//offsite.example/forgot-password'] as $uri) {
            $request = new Request(RequestMethod::POST, $uri, body: [
                'email' => 'nobody@example.invalid', 'csrf_token' => 'factice-invalide',
            ]);
            [$app, , $router] = $this->fixture($request);
            $this->register($router, new PasswordResetController($app));
            $route = $router->resolve($request);
            self::assertNotNull($route);
            self::assertFalse($route->hasErrors());
            $response = (new ApplicationRouteRunner($app))->run($route);
            self::assertSame(303, $response->code);
            self::assertSame($uri, $response->httpHeaders['Location']);
            self::$observations['redirections'][$uri] = ['path_routeur' => $request->path,
                'code' => $response->code, 'location' => $response->httpHeaders['Location']];
        }
    }

    public function testNonApiSpacePostChangesSessionWithoutCsrfOrOriginCheck(): void
    {
        $request = new Request(RequestMethod::POST, '/space/choose',
            headers: ['Origin' => 'https://unrelated.example'], body: ['id' => '22']);
        [$app, $session, $router] = $this->fixture($request);
        $space = new Space($app);
        $space->id = 22;
        $helper = $this->getMockBuilder(SpaceHelper::class)->disableOriginalConstructor()->onlyMethods(['getSpace'])->getMock();
        $helper->expects(self::once())->method('getSpace')->willReturn($space);
        $this->set($app, 'sharedExtensionsClasses', [SpaceHelper::class => $helper]);
        $this->register($router, new SpaceController($app));
        $route = $router->resolve($request);
        self::assertFalse($route->isApi);
        self::assertFalse($route->hasErrors());
        $response = (new ApplicationRouteRunner($app))->run($route);
        self::assertSame(22, $session->getSpaceId());
        self::assertSame(303, $response->code);
        self::$observations['space_choose'] = ['csrf_fourni' => false, 'origin_externe' => true,
            'space_initial' => 1, 'space_final' => $session->getSpaceId(),
            'limite' => 'Session mémoire injectée ; politique SameSite du navigateur non reproduite.'];
    }

    public function testDynamicGetLogoutDestroysTheSessionWithoutAnIntentionToken(): void
    {
        $request = new Request(RequestMethod::GET, '/audit-logout', headers: ['Sec-Fetch-Site' => 'cross-site']);
        [$app, $session, $router] = $this->fixture($request);
        $this->set($app, 'sharedExtensionsClasses', [PublicLocaleHelper::class => new PublicLocaleHelper($app)]);
        // URL factice : les URL réelles sont construites depuis les menus SQL.
        $router->registerRoute('audit_logout', RequestMethod::GET, '/audit-logout', new UserController($app), 'logout', null, false);
        $response = (new ApplicationRouteRunner($app))->run($router->resolve($request));
        self::assertNull($session->getUserId());
        self::assertTrue($session->destroyed);
        self::assertSame('/', $response->httpHeaders['Location']);
        self::$observations['logout_get'] = ['user_initial' => 7, 'user_final' => $session->getUserId(), 'session_detruite' => true];
    }

    public function testAnArrayForAnIntegerEscapesTheNormalParameterErrorList(): void
    {
        $request = new Request(RequestMethod::POST, '/space/choose', body: ['id' => ['22']]);
        [$app, , $router] = $this->fixture($request);
        $this->register($router, new SpaceController($app));
        try {
            $router->resolve($request);
            self::fail('Un TypeError est attendu dans la révision auditée.');
        } catch (TypeError $error) {
            self::assertStringContainsString('preg_match', $error->getMessage());
            self::$observations['parametre_tableau'] = ['exception' => get_class($error), 'avant_runner' => true];
        }
        $valid = $router->resolve(new Request(RequestMethod::POST, '/space/choose', body: ['id' => '22']));
        self::assertFalse($valid->hasErrors());
        $invalid = $router->resolve(new Request(RequestMethod::POST, '/space/choose', body: ['id' => 'abc']));
        self::assertTrue($invalid->hasErrors());
    }

    public function testInvalidJsonBecomesAnEmptyBodyAtTheEnvelopeLevel(): void
    {
        $method = new ReflectionMethod(WebRequest::class, 'bodyFromRequest');
        self::assertSame([], $method->invoke(null, ['Content-Type' => 'application/json'], '{invalide'));
        self::$observations['json_invalide'] = ['corps_normalise' => [], 'limite' => 'Les paramètres requis sont contrôlés ensuite ; aucune injection démontrée.'];
    }

    public static function tearDownAfterClass(): void
    {
        $output = getenv('TY_SECURITY_OUTPUT');
        if (is_string($output) && $output !== '') {
            file_put_contents($output, json_encode(self::$observations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        }
    }
}

final class AuditMemorySession extends Session
{
    private array $data = [];
    public bool $destroyed = false;
    public function initialize(): void {}
    public function rotateId(): void {}
    public function destroy(): void { $this->data = []; $this->destroyed = true; }
    public function get(string $key, string|array|object|int|float|bool|null $defaultValue = null): string|array|object|int|float|bool|null { return $this->data[$key] ?? $defaultValue; }
    public function set(string $key, string|array|object|int|float|bool|null $value): void { $this->data[$key] = $value; }
    public function remove(string $key): void { unset($this->data[$key]); }
}
