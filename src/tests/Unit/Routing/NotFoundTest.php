<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Routing;

use ReflectionProperty;
use YTassy\Tycms\Application;
use YTassy\Tycms\ApplicationRouteRunner;
use YTassy\Tycms\DB\Database;
use YTassy\Tycms\Exceptions\NotFoundException;
use YTassy\Tycms\Request\Request;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Response\ApiResponse;
use YTassy\Tycms\Routing\Controller\BaseController;
use YTassy\Tycms\Routing\Route;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class NotFoundTest extends FrameworkTestCase
{
    public function testApiNotFoundRollsBackAndReturnsGenericJson404(): void
    {
        $app = $this->createApplication();
        (new ReflectionProperty(Application::class, 'request'))->setValue($app, new Request(RequestMethod::GET, '/api/missing'));
        $database = $this->createMock(Database::class);
        $calls = [];
        $database->expects(self::exactly(2))->method('execute')->willReturnCallback(
            static function (string $sql) use (&$calls): int { $calls[] = $sql; return 1; }
        );
        (new ReflectionProperty(Application::class, 'database'))->setValue($app, $database);
        $controller = new class($app) extends BaseController {
            public function action(): ApiResponse { throw new NotFoundException('détail interne'); }
        };
        $route = new Route('Test', $controller, 'action', true, [], [], [], false, false, false, false, []);

        $response = (new ApplicationRouteRunner($app))->run($route);

        self::assertSame(['START TRANSACTION', 'ROLLBACK'], $calls);
        self::assertInstanceOf(ApiResponse::class, $response);
        self::assertSame(404, $response->code);
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($body['error']);
        self::assertSame('Cette page est introuvable sur le site.', $body['message']);
        self::assertStringNotContainsString('détail interne', $response->body);
    }
}
