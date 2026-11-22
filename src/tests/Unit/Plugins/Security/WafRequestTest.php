<?php

namespace YTassy\Tycms\Tests\Unit\Plugins\Security;

use ReflectionProperty;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseController;
use YTassy\Tycms\CoreExts\Core\Helper\Acl\AclHelper;
use YTassy\Tycms\CoreExts\Core\Controllers\Admin\AdminExtensionsController;
use YTassy\Tycms\CoreExts\Security\Configuration;
use YTassy\Tycms\CoreExts\Security\Plugin;
use YTassy\Tycms\CoreExts\Security\Helper\SecurityPolicyHelper;
use YTassy\Tycms\CoreExts\Security\Helper\WafHelper;
use YTassy\Tycms\CoreExts\Security\ExtensionApi\AnnotationsController\SecurityDoNotCheckPatterns;
use YTassy\Tycms\Request\Request;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Routing\Router;
use YTassy\Tycms\DB\Database;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Routing\Route;
use YTassy\Tycms\Session\Session;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class WafRequestTest extends FrameworkTestCase
{
    private function environment(array $configs = [], ?Request $request = null, ?int $userId = null, bool $root = false): array
    {
        $app = $this->createApplication();
        $session = $this->createStub(Session::class);
        $session->method('getSpaceId')->willReturn(2);
        $session->method('getUserId')->willReturn($userId);
        $acl = $this->createStub(AclHelper::class);
        $acl->method('isRoot')->willReturn($root);
        $policies = $this->createStub(SecurityPolicyHelper::class);
        $policies->method('configuration')->willReturnCallback(static fn (int $spaceId): array => $configs[$spaceId] ?? Configuration::defaults());
        $helper = new class($app) extends WafHelper {
            public array $events = [];
            protected function logEvent(array $event): void { $this->events[] = $event; }
        };
        (new ReflectionProperty(Application::class, 'request'))->setValue($app, $request ?? new Request(body: ['value' => '<?PHP secret']));
        (new ReflectionProperty(Application::class, 'session'))->setValue($app, $session);
        (new ReflectionProperty(Application::class, 'sharedExtensionsClasses'))->setValue($app, [
            SecurityPolicyHelper::class => $policies, AclHelper::class => $acl, WafHelper::class => $helper,
        ]);
        return [$app, $helper];
    }

    private function route(Application $app, bool $api = false, ?object $controller = null, string $method = 'normal'): Route
    {
        return new Route('waf_test', $controller ?? new WafTestController($app), $method, $api, [], [], [], false, false, false, false, []);
    }

    public function testDefaultIsInactiveAndObservationNeverBlocks(): void
    {
        [$app, $helper] = $this->environment();
        $helper->check($this->route($app));
        self::assertSame([], $helper->events);
        [$app, $helper] = $this->environment([2 => [...Configuration::defaults(), 'waf_mode' => 'observe']]);
        $helper->check($this->route($app));
        self::assertCount(1, $helper->events);
        self::assertSame('observe', $helper->events[0]['mode']);
        self::assertStringNotContainsString('secret', json_encode($helper->events));
    }

    public function testApiAndAcceptJsonReceiveTheCore403Envelope(): void
    {
        foreach ([true, false] as $api) {
            $request = new Request(body: ['value' => '<?php sensitive'], accept: $api ? 'text/html' : 'application/json');
            [$app, $helper] = $this->environment([2 => [...Configuration::defaults(), 'waf_mode' => 'block', 'waf_message' => 'Message choisi.']], $request);
            try {
                // Passer par le point d’entrée réel du plugin plutôt que seulement le moteur.
                (new Plugin($app))->onAfterResolveRoute($this->route($app, $api));
                self::fail('La requête dangereuse doit être bloquée.');
            } catch (ExceptionResponse $exception) {
                $response = $exception->getResponse();
                self::assertSame(403, $response->code);
                self::assertSame('application/json', $response->httpHeaders['Content-Type']);
                self::assertSame('WAF', $response->httpHeaders['X-Tycms-Blocked-By']);
                $data = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
                self::assertTrue($data['error']);
                self::assertTrue($data['is_message']);
                self::assertSame('DANGER', $data['message_level']);
                self::assertSame('[WAF] Message choisi.', $data['message']);
                self::assertSame('waf', $data['source']);
                self::assertSame('WAF_BLOCKED', $data['error_code']);
                self::assertStringNotContainsString('sensitive', $response->body);
                self::assertCount(1, $helper->events);
            }
        }
    }

    public function testResolvedNonApiRouteReturnsJson403WithoutRedirectOrTransactionWhenAcceptRequestsJson(): void
    {
        [$app, $helper] = $this->environment([2 => [...Configuration::defaults(), 'waf_mode' => 'block']],
            new Request(uri: '/waf-test', query: ['query' => '<?php'], accept: 'application/json'));
        $router = new Router($app);
        $router->registerRoute('waf_test', RequestMethod::GET, '/waf-test', new WafTestController($app), 'normal', null, false);
        (new ReflectionProperty(Application::class, 'router'))->setValue($app, $router);
        (new ReflectionProperty(Application::class, 'extensions'))->setValue($app, ['plg_security' => new Plugin($app)]);
        $db = $this->createMock(Database::class);
        $db->expects(self::never())->method('execute');
        (new ReflectionProperty(Application::class, 'database'))->setValue($app, $db);
        WafTestController::$called = false;

        $response = $app->executeAndGetResponse();
        self::assertSame(403, $response->code);
        self::assertSame('application/json', $response->httpHeaders['Content-Type']);
        self::assertArrayNotHasKey('Location', $response->httpHeaders);
        self::assertFalse(WafTestController::$called);
        self::assertCount(1, $helper->events);
    }

    public function testExemptRouteStillRequiresCsrfBeforeControllerExecution(): void
    {
        [$app] = $this->environment([2 => [...Configuration::defaults(), 'waf_mode' => 'block']],
            new Request(method: RequestMethod::POST, uri: '/waf-exempt', body: ['query' => '<?php']));
        $router = new Router($app);
        $router->registerRoute('waf_exempt', RequestMethod::POST, '/waf-exempt', new WafTestController($app), 'exempt', null, true);
        (new ReflectionProperty(Application::class, 'router'))->setValue($app, $router);
        (new ReflectionProperty(Application::class, 'extensions'))->setValue($app, ['plg_security' => new Plugin($app)]);
        $db = $this->createMock(Database::class);
        $db->expects(self::never())->method('execute');
        (new ReflectionProperty(Application::class, 'database'))->setValue($app, $db);
        WafTestController::$called = false;

        $response = $app->executeAndGetResponse();
        self::assertSame(403, $response->code);
        self::assertStringContainsString('CSRF', $response->body);
        self::assertFalse(WafTestController::$called);
        self::assertArrayNotHasKey('X-Tycms-Blocked-By', $response->httpHeaders);
    }

    public function testHtmlMessageIsEscapedAndAlwaysIdentifiesTheWaf(): void
    {
        [$app, $helper] = $this->environment([2 => [...Configuration::defaults(), 'waf_mode' => 'block', 'waf_message' => '<script>alert(1)</script>']], new Request(body: ['value' => '<?php'], accept: 'text/html'));
        try { $helper->check($this->route($app)); self::fail('Un 403 est attendu.'); }
        catch (ExceptionResponse $exception) {
            $response = $exception->getResponse();
            self::assertSame(403, $response->code);
            self::assertStringContainsString('text/html', $response->httpHeaders['Content-Type']);
            self::assertStringContainsString('[WAF] &lt;script&gt;', $response->body);
            self::assertStringNotContainsString('<script>', $response->body);
        }
    }

    public function testOnlyAnAuthenticatedRootIsExemptWhenTheOptionIsEnabled(): void
    {
        foreach ([[null, false], [null, true], [7, false], [7, true]] as [$userId, $root]) {
            [$app, $helper] = $this->environment([2 => [...Configuration::defaults(), 'waf_mode' => 'observe']], userId: $userId, root: $root);
            $helper->check($this->route($app));
            self::assertCount($userId !== null && $root ? 0 : 1, $helper->events);
        }
        [$app, $helper] = $this->environment([2 => [...Configuration::defaults(), 'waf_mode' => 'observe', 'waf_skip_root' => false]], userId: 7, root: true);
        $helper->check($this->route($app));
        self::assertCount(1, $helper->events);
    }

    public function testControllerAndMethodAttributesOnlyExemptTheIntendedRoutes(): void
    {
        [$app, $helper] = $this->environment([2 => [...Configuration::defaults(), 'waf_mode' => 'block']]);
        foreach ([$this->route($app, controller: new WafExemptController($app)),
            $this->route($app, method: 'exempt'),
            $this->route($app, controller: new AdminExtensionsController($app), method: 'save')] as $route) {
            self::assertTrue($helper->isExempt($route));
            $helper->check($route);
        }
        self::assertFalse($helper->isExempt($this->route($app)));
        self::assertFalse($helper->isExempt($this->route($app, controller: new WafTestController($app), method: 'fakeExempt')));
        self::assertSame([], $helper->events);
    }

    public function testRootInheritanceDoesNotCopyOrChangeLocalCsp(): void
    {
        $root = [...Configuration::defaults(), 'waf_mode' => 'observe', 'waf_message' => 'Root message.'];
        $local = [...Configuration::defaults(), 'waf_use_root' => true, 'waf_mode' => 'off', 'iframe_mode' => 'self'];
        [$app, $helper] = $this->environment([1 => $root, 2 => $local]);
        self::assertSame(1, $helper->effectiveConfiguration(2)['space_id']);
        self::assertSame('observe', $helper->effectiveConfiguration(2)['values']['waf_mode']);
        $helper->check($this->route($app));
        self::assertSame(2, $helper->events[0]['space_id']);
        self::assertSame(1, $helper->events[0]['policy_space_id']);
        self::assertArrayNotHasKey('iframe_mode', $helper->effectiveConfiguration(2)['values']);
        // Un autre Space non héritier conserve son propre WAF désactivé.
        self::assertSame('off', $helper->effectiveConfiguration(3)['values']['waf_mode']);
    }

    public function testScanErrorsAreObservedOrBlockedAccordingToMode(): void
    {
        foreach (['observe', 'block'] as $mode) {
            $config = [...Configuration::defaults(), 'waf_mode' => $mode,
                'waf_patterns' => '{"strings":[],"regexes":{"unicode":"~test~u"}}'];
            [$app, $helper] = $this->environment([2 => $config], new Request(body: ['value' => "\xff"]));
            try {
                $helper->check($this->route($app));
                self::assertSame('observe', $mode);
            } catch (ExceptionResponse $exception) {
                self::assertSame('block', $mode);
                self::assertSame(403, $exception->getResponse()->code);
            }
            self::assertSame('regex_error', $helper->events[0]['kind']);
        }
    }
}

class WafTestController extends BaseController
{
    public static bool $called = false;
    public function normal(): void { self::$called = true; }
    #[SecurityDoNotCheckPatterns]
    public function exempt(): void { self::$called = true; }
    #[\YTassy\Tycms\Tests\Unit\Plugins\Security\Fake\SecurityDoNotCheckPatterns]
    public function fakeExempt(): void {}
}

#[SecurityDoNotCheckPatterns]
class WafExemptController extends WafTestController {}

namespace YTassy\Tycms\Tests\Unit\Plugins\Security\Fake;

#[\Attribute(\Attribute::TARGET_METHOD)]
class SecurityDoNotCheckPatterns {}
