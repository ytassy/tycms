<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use YTassy\Tycms\CoreExts\Core\Helper\Mail\MailHelper;
use YTassy\Tycms\CoreExts\Core\Models\Space;
use YTassy\Tycms\CoreExts\Core\WF\WFSpace;
use YTassy\Tycms\Exceptions\ApplicationException;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class LocalhostPortTest extends FrameworkTestCase
{
    #[DataProvider('hosts')]
    public function testSpaceStoresTheHostWithoutProtocol(bool $dev, string $host, ?string $expected): void
    {
        $app = $this->createApplication();
        $app->getConf()->dev = $dev;

        if ($expected === null) {
            $this->expectException(ExceptionResponse::class);
        }

        $actual = new ReflectionMethod(WFSpace::class, 'normalizeHost')->invoke(new WFSpace($app), $host);
        self::assertSame($expected, $actual);
    }

    #[DataProvider('hosts')]
    public function testMailUsesHttpForTheLocalDevelopmentHost(bool $dev, string $host, ?string $expected): void
    {
        $app = $this->createApplication();
        $app->getConf()->dev = $dev;
        $space = new Space($app);
        $space->params = ['main_host' => $host];

        if ($expected === null) {
            $this->expectException(ApplicationException::class);
        }

        $actual = new ReflectionMethod(MailHelper::class, 'siteUrlForEmail')->invoke(new MailHelper($app), $space);
        $scheme = str_starts_with($expected ?? '', 'localhost:') ? 'http://' : 'https://';
        self::assertSame($scheme . $expected, $actual);
    }

    public static function hosts(): iterable
    {
        yield 'Docker' => [true, 'localhost:8088', 'localhost:8088'];
        yield 'normalisation' => [true, ' LOCALHOST:8088 ', 'localhost:8088'];
        yield 'port minimum' => [true, 'localhost:1', 'localhost:1'];
        yield 'port maximum' => [true, 'localhost:65535', 'localhost:65535'];
        yield 'production' => [false, 'localhost:8088', null];
        yield 'port zéro' => [true, 'localhost:0', null];
        yield 'port trop grand' => [true, 'localhost:65536', null];
        yield 'port vide' => [true, 'localhost:', null];
        yield 'autre domaine avec port' => [true, 'example.test:8088', null];
        yield 'protocole dans le domaine' => [true, 'http://localhost:8088', null];
        yield 'chemin' => [true, 'localhost:8088/path', null];
        yield 'injection' => [true, 'localhost:8088@example.test', null];
        yield 'domaine de production' => [false, 'example.test', 'example.test'];
    }
}
