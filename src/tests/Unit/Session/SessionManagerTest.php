<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Session;

use YTassy\Tycms\Application;
use YTassy\Tycms\Session\Session;
use YTassy\Tycms\Session\SessionManager;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class SessionManagerTest extends FrameworkTestCase
{
    public function testPhpModeReportsItsLimitAndClosesTheCurrentUserSession(): void
    {
        $configuration = new \Configuration();
        $configuration->session_handler = 'php';
        $session = $this->createMock(Session::class);
        $session->method('getUserId')->willReturn(42);
        $session->expects(self::once())->method('destroy');
        $app = $this->createMock(Application::class);
        $app->method('getConf')->willReturn($configuration);
        $app->method('getSession')->willReturn($session);
        $app->expects(self::never())->method('getDatabase');

        self::assertFalse(new SessionManager($app)->deleteUserSessions(42));
    }

    public function testPhpModePreservesAnotherUsersCurrentSession(): void
    {
        $configuration = new \Configuration();
        $configuration->session_handler = 'php';
        $session = $this->createMock(Session::class);
        $session->method('getUserId')->willReturn(7);
        $session->expects(self::never())->method('destroy');
        $app = $this->createMock(Application::class);
        $app->method('getConf')->willReturn($configuration);
        $app->method('getSession')->willReturn($session);
        $app->expects(self::never())->method('getDatabase');

        self::assertFalse(new SessionManager($app)->deleteUserSessions(42));
    }
}
