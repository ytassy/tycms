<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use ReflectionMethod;
use ReflectionProperty;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Core\Controllers\Admin\AdminLogsController;
use YTassy\Tycms\CoreExts\Core\Helper\Acl\AclHelper;
use YTassy\Tycms\CoreExts\Core\WF\WFLogs;
use YTassy\Tycms\CoreExts\Core\WF\WFSso;
use YTassy\Tycms\CoreExts\Core\Models\Space;
use YTassy\Tycms\Exceptions\AccessDeniedException;
use YTassy\Tycms\Logging\LogEventException;
use YTassy\Tycms\Request\Request;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Routing\Router;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class LogsTest extends FrameworkTestCase
{
    public function testNonRootCannotReachFileHelperForEitherOperation(): void
    {
        foreach (['status', 'read'] as $method) {
            $app = $this->createApplication();
            $acl = $this->createStub(AclHelper::class);
            $acl->method('isRoot')->willReturn(false);
            $app->getConf()->log_path = sys_get_temp_dir() . '/tycms-denied-log-' . bin2hex(random_bytes(8));
            new ReflectionProperty(Application::class, 'sharedExtensionsClasses')->setValue($app,
                [AclHelper::class => $acl]);
            try {
                $workflow = new WFLogs($app);
                $method === 'status' ? $workflow->status() : $workflow->read('invalid-input');
                self::fail('ROOT doit être exigé avant tout accès aux fichiers.');
            } catch (AccessDeniedException) { self::assertTrue(true); }
            self::assertDirectoryDoesNotExist($app->getConf()->log_path);
        }
    }

    public function testRootCanReadWithoutSessionOrCurrentSpaceLookup(): void
    {
        $app = $this->createApplication();
        $acl = $this->createStub(AclHelper::class);
        $acl->method('isRoot')->willReturn(true);
        $path = sys_get_temp_dir() . '/tycms-root-log-' . bin2hex(random_bytes(8));
        $app->getConf()->log_path = $path;
        new ReflectionProperty(Application::class, 'sharedExtensionsClasses')->setValue($app,
            [AclHelper::class => $acl]);
        $workflow = new WFLogs($app);
        try {
            self::assertTrue($workflow->status()['available']);
            self::assertSame([], $workflow->read('{}')['entries']);
        } finally {
            foreach (['info', 'warning', 'error'] as $directory) rmdir($path . '/tycms/' . $directory);
            unlink($path . '/tycms/.lock');
            rmdir($path . '/tycms');
            rmdir($path);
        }
    }

    public function testRoutesKeepReadCursorOutOfUrlAndDoNotOpenATransaction(): void
    {
        $app = $this->createApplication();
        $router = new Router($app);
        new ReflectionMethod(Router::class, 'registerController')->invoke($router, new AdminLogsController($app));
        $status = $router->resolve(new Request(uri: '/administrator/api/logs/status'));
        $read = $router->resolve(new Request(RequestMethod::POST, '/administrator/api/logs/read', body: ['filters' => '{}']));
        self::assertNotNull($status);
        self::assertNotNull($read);
        self::assertTrue($status->noTransaction);
        self::assertTrue($read->noTransaction);
        self::assertFalse($read->withoutCsrf);
        self::assertNull($router->resolve(new Request(uri: '/administrator/api/logs/read')));
    }

    public function testMemberRefusalHasAnExplicitCodeWithoutChangingAuthorization(): void
    {
        $app = $this->createApplication();
        $acl = $this->createStub(AclHelper::class);
        $acl->method('userHasGlobalPermission')->willReturn(false);
        new ReflectionProperty(Application::class, 'sharedExtensionsClasses')->setValue($app, [AclHelper::class => $acl]);
        $space = new Space($app);
        $space->id = 2;
        try {
            new ReflectionMethod(WFSso::class, 'ensureEligible')->invoke(new WFSso($app), (object) ['id' => 12, 'blocked' => 0], $space, false);
            self::fail('Le compte sans MEMBER doit rester refusé.');
        } catch (LogEventException $error) {
            self::assertSame('SSO_MEMBER_REQUIRED', $error->eventCode);
            self::assertSame('Compte non autorisé dans cet espace.', $error->getMessage());
        }
    }
}
