<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use YTassy\Tycms\Session\RunSession;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class RunSessionTest extends FrameworkTestCase
{
    #[Test]
    public function carriesPrincipalAndSpaceInMemory(): void
    {
        $session = new RunSession($this->createApplication(), 42, 7);

        self::assertSame(42, $session->getUserId());
        self::assertSame(7, $session->getSpaceId());

        $session->set('key', 'value');
        self::assertSame('value', $session->get('key'));

        $session->remove('key');
        self::assertNull($session->get('key'));

        $session->initialize();
        $session->rotateId();
        self::assertSame(42, $session->getUserId());

        $session->destroy();
        self::assertNull($session->getUserId());
        self::assertNull($session->getSpaceId());
    }
}
