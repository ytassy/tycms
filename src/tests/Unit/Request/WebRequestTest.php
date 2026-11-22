<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Request;

use PHPUnit\Framework\TestCase;
use YTassy\Tycms\Request\Request;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Request\WebRequest;

final class WebRequestTest extends TestCase
{
    private array $server;
    private array $get;
    private array $post;
    private array $cookies;
    private array $files;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $this->get = $_GET;
        $this->post = $_POST;
        $this->cookies = $_COOKIE;
        $this->files = $_FILES;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $_GET = $this->get;
        $_POST = $this->post;
        $_COOKIE = $this->cookies;
        $_FILES = $this->files;
    }

    public function testExtractsHostWithoutPort(): void
    {
        $this->setServer('WWW.TOTO.FR:8443', '/offers?language=fr', 8443, true);

        $request = new WebRequest();

        self::assertSame('www.toto.fr', $request->host);
        self::assertSame(8443, $request->port);
        self::assertSame('https://www.toto.fr:8443/offers?language=fr', $request->url);
    }

    public function testKeepsIpAddressesAsHost(): void
    {
        $this->setServer('[2001:db8::1]:8080', '/', 8080);

        $request = new WebRequest();

        self::assertSame('2001:db8::1', $request->host);
        self::assertSame('http://[2001:db8::1]:8080/', $request->url);
    }

    public function testRequestAcceptsHostInTheFormerDomainPosition(): void
    {
        $request = new Request(
            RequestMethod::GET,
            '/',
            [],
            [],
            [],
            [],
            [],
            [],
            'www.toto.fr',
        );

        self::assertSame('www.toto.fr', $request->host);
    }

    private function setServer(string $host, string $uri = '/', int $port = 80, bool $https = false): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => $uri,
            'HTTP_HOST' => $host,
            'SERVER_PORT' => (string) $port,
        ];
        if ($https) {
            $_SERVER['HTTPS'] = 'on';
        }

        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $_FILES = [];
    }
}
