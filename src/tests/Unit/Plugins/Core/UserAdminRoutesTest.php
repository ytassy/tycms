<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use YTassy\Tycms\CoreExts\Core\Controllers\Admin\AdminUserController;
use YTassy\Tycms\CoreExts\Core\Controllers\Admin\AdminUserAssignmentsController;
use YTassy\Tycms\Request\Request;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Routing\Router;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class UserAdminRoutesTest extends FrameworkTestCase
{
    #[Test]
    public function assignmentMatrixHasDedicatedReadAndPostOnlyWriteRoutes(): void
    {
        $app = $this->createApplication();
        $router = new Router($app);
        new ReflectionMethod(Router::class, 'registerController')->invoke($router, new AdminUserAssignmentsController($app));

        foreach (['', '/directory'] as $path) {
            self::assertNotNull($router->resolve(new Request(RequestMethod::GET,
                '/administrator/api/user-assignments' . $path)));
        }
        self::assertNotNull($router->resolve(new Request(RequestMethod::POST, '/administrator/api/user-assignments/save')));
        self::assertNull($router->resolve(new Request(RequestMethod::GET, '/administrator/api/user-assignments/save')));
    }

    #[Test]
    public function membershipCannotBeEditedThroughDedicatedSpaceRoutes(): void
    {
        $app = $this->createApplication();
        $router = new Router($app);
        new ReflectionMethod(Router::class, 'registerController')->invoke($router, new AdminUserController($app));

        // Les anciens endpoints disparaissent ; seules les attributions restent disponibles.
        foreach (['space', 'spaces/save'] as $path) {
            self::assertNull($router->resolve(new Request(
                RequestMethod::POST, '/administrator/api/users/' . $path)));
        }
        foreach (['role', 'group', 'roles/save', 'groups/save'] as $path) {
            self::assertNotNull($router->resolve(new Request(
                RequestMethod::POST, '/administrator/api/users/' . $path)));
        }
    }
}
