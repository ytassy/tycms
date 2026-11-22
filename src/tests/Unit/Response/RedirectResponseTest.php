<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Response;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\Response\RedirectResponse;

final class RedirectResponseTest extends TestCase
{
    public function testInternalPathsKeepTheirQueryAndStatusWithoutChoosingAHost(): void
    {
        $target = '/fr/formulaire?retour=%2Fcompte&label=deux%20mots#message';
        foreach ([301, 302, 303, 307, 308] as $code) {
            $response = new RedirectResponse($target, $code);
            self::assertSame($target, $response->httpHeaders['Location']);
            self::assertSame($code, $response->code);
        }
        self::assertSame('/', RedirectResponse::default('/')->httpHeaders['Location']);
    }

    #[DataProvider('unsafeInternalTargets')]
    public function testInternalRedirectsRejectAnotherAuthorityAndAmbiguousCharacters(string $target): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RedirectResponse($target);
    }

    public static function unsafeInternalTargets(): iterable
    {
        yield ['//offsite.example/path'];
        yield ['///offsite.example/path'];
        yield ['/\\offsite.example/path'];
        yield ['\\offsite.example/path'];
        yield ['https://offsite.example/path'];
        yield ['javascript:alert(1)'];
        yield ['relative/path'];
        yield [''];
        yield [' /path'];
        yield ["/\t/offsite.example"];
        yield ["/path\r\nLocation: https://offsite.example"];
        yield ["/path\0"];
        yield ["/path\x7f"];
    }

    public function testExternalOAuthDestinationRequiresAnExplicitCall(): void
    {
        $target = 'https://accounts.example/authorize?state=factice&redirect_uri=https%3A%2F%2Fsite.example%2Fsso%2Fcallback';
        self::assertSame($target, RedirectResponse::external($target)->httpHeaders['Location']);
        self::assertSame('http://localhost:8080/authorize', RedirectResponse::external('http://localhost:8080/authorize')->httpHeaders['Location']);
    }

    #[DataProvider('unsafeExternalTargets')]
    public function testExternalRedirectsStillRejectUnsafeFormats(string $target): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RedirectResponse::external($target);
    }

    public static function unsafeExternalTargets(): iterable
    {
        yield ['//offsite.example/path'];
        yield ['/path'];
        yield ['javascript:alert(1)'];
        yield ['ftp://offsite.example/file'];
        yield ['http://offsite.example/path'];
        yield ['https://user:password@offsite.example/path'];
        yield ['https://offsite.example/\\path'];
        yield ["https://offsite.example/path\n"];
    }
}
