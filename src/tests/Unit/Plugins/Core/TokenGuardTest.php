<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use PHPUnit\Framework\TestCase;
use YTassy\Tycms\CoreExts\Core\Cron\TokenGuard;
use YTassy\Tycms\Request\Request;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

/**
 * Contrat du garde de jeton partagé par `/cron` et `/queue-worker`.
 *
 * La matrice est celle vérifiée à la main sur l'hébergement ; ce test la fige.
 */
final class TokenGuardTest extends FrameworkTestCase
{
    private const TOKEN = 'jeton-de-test-long-et-aleatoire';

    private const FIELD = 'cron_token';

    public function testMissingConfigurationFailsClosed(): void
    {
        $this->assertRefused(503, new Request(), '');
    }

    public function testBearerTokenIsAccepted(): void
    {
        self::expectNotToPerformAssertions();

        $this->guard()->assert(
            new Request(headers: ['Authorization' => 'Bearer ' . self::TOKEN]),
            self::TOKEN,
            self::FIELD,
            'cron',
            'CRON',
        );
    }

    public function testQueryTokenIsAccepted(): void
    {
        self::expectNotToPerformAssertions();

        $this->guard()->assert(
            new Request(method: RequestMethod::GET, query: [self::FIELD => self::TOKEN]),
            self::TOKEN,
            self::FIELD,
            'cron',
            'CRON',
        );
    }

    public function testBodyTokenIsAccepted(): void
    {
        self::expectNotToPerformAssertions();

        $this->guard()->assert(
            new Request(method: RequestMethod::POST, body: [self::FIELD => self::TOKEN]),
            self::TOKEN,
            self::FIELD,
            'cron',
            'CRON',
        );
    }

    public function testBasicPasswordEqualToTheTokenIsAccepted(): void
    {
        self::expectNotToPerformAssertions();

        $this->guard()->assert(
            new Request(headers: ['Authorization' => 'Basic ' . base64_encode('nimporte:' . self::TOKEN)]),
            self::TOKEN,
            self::FIELD,
            'cron',
            'CRON',
        );
    }

    public function testWrongTokenIsRefused(): void
    {
        $this->assertRefused(
            401,
            new Request(query: [self::FIELD => 'faux']),
            self::TOKEN,
        );
    }

    public function testMissingTokenIsRefused(): void
    {
        $this->assertRefused(401, new Request(), self::TOKEN);
    }

    public function testTwoTokenSourcesAreRefused(): void
    {
        $this->assertRefused(
            400,
            new Request(
                query: [self::FIELD => self::TOKEN],
                headers: ['Authorization' => 'Bearer ' . self::TOKEN],
            ),
            self::TOKEN,
        );
    }

    public function testWrongBasicPasswordIsRefused(): void
    {
        $this->assertRefused(
            401,
            new Request(headers: ['Authorization' => 'Basic ' . base64_encode('user:faux')]),
            self::TOKEN,
        );
    }

    public function testDigestWithoutApplicationTokenIsRefused(): void
    {
        $this->assertRefused(
            401,
            new Request(headers: ['Authorization' => 'Digest username="test"']),
            self::TOKEN,
        );
    }

    private function guard(): TokenGuard
    {
        return new TokenGuard($this->createApplication());
    }

    private function assertRefused(int $status, Request $request, string $expected): void
    {
        try {
            $this->guard()->assert($request, $expected, self::FIELD, 'cron', 'CRON');
            self::fail('Le garde devait refuser la requête.');
        } catch (ExceptionResponse $exception) {
            self::assertSame($status, $exception->getResponse()->code);
        }
    }
}
