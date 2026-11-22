<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Core\Cron\CronDispatcher;
use YTassy\Tycms\CoreExts\Core\Cron\CronTick;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\ICronProvider;
use YTassy\Tycms\Request\Request;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class CronDispatcherTest extends FrameworkTestCase
{
    #[Test]
    public function dueHooksAreCalledWithTheSameTick(): void
    {
        $app = $this->createApplication();
        $app->getConf()->cron_token = 'secret';
        $extension = new RecordingCronExtension();
        $this->setExtensions($app, ['plg_test' => $extension]);

        $result = (new CronDispatcher($app))->run(
            $this->request('secret'),
            CronTick::fromDateTime(new \DateTimeImmutable('2026-11-18 09:00:00')),
        );

        self::assertTrue($result['ok']);
        self::assertSame(['minute', 'quarter', 'hour'], $result['hooks']);
        self::assertSame(['minute', 'quarter', 'hour'], $extension->calls);
        self::assertSame(9, $result['results']['minute']['plg_test']['hour']);
    }

    #[Test]
    public function onlyTheMinuteHookRunsOutsideAQuarterHour(): void
    {
        $app = $this->createApplication();
        $app->getConf()->cron_token = 'secret';
        $extension = new RecordingCronExtension();
        $this->setExtensions($app, ['plg_test' => $extension]);

        $result = (new CronDispatcher($app))->run(
            $this->request('secret'),
            CronTick::fromDateTime(new \DateTimeImmutable('2026-11-18 09:07:00')),
        );

        self::assertSame(['minute'], $result['hooks']);
        self::assertSame(['minute'], $extension->calls);
    }

    #[Test]
    public function oneFailingExtensionDoesNotBlockTheOthers(): void
    {
        $app = $this->createApplication();
        $app->getConf()->cron_token = 'secret';
        $failing = new RecordingCronExtension(true);
        $healthy = new RecordingCronExtension();
        $this->setExtensions($app, ['plg_failing' => $failing, 'plg_healthy' => $healthy]);

        $result = (new CronDispatcher($app))->run(
            $this->request('secret'),
            CronTick::fromDateTime(new \DateTimeImmutable('2026-11-18 09:07:00')),
        );

        self::assertFalse($result['ok']);
        self::assertCount(1, $result['errors']);
        self::assertSame('plg_failing', $result['errors'][0]['extension']);
        self::assertSame(['minute'], $healthy->calls);
    }

    #[Test]
    public function getTokenCanComeFromTheQueryString(): void
    {
        $app = $this->createApplication();
        $app->getConf()->cron_token = 'secret';
        $extension = new RecordingCronExtension();
        $this->setExtensions($app, ['plg_test' => $extension]);

        $result = (new CronDispatcher($app))->run(
            new Request(method: RequestMethod::GET, query: ['cron_token' => 'secret']),
            CronTick::fromDateTime(new \DateTimeImmutable('2026-11-18 09:07:00')),
        );

        self::assertTrue($result['ok']);
        self::assertSame(['minute'], $extension->calls);
    }

    #[Test]
    public function postTokenCanComeFromTheBody(): void
    {
        $app = $this->createApplication();
        $app->getConf()->cron_token = 'secret';
        $extension = new RecordingCronExtension();
        $this->setExtensions($app, ['plg_test' => $extension]);

        $result = (new CronDispatcher($app))->run(
            new Request(method: RequestMethod::POST, body: ['cron_token' => 'secret']),
            CronTick::fromDateTime(new \DateTimeImmutable('2026-11-18 09:07:00')),
        );

        self::assertTrue($result['ok']);
        self::assertSame(['minute'], $extension->calls);
    }

    #[Test]
    public function extensionWithoutTheContractIsIgnored(): void
    {
        $app = $this->createApplication();
        $app->getConf()->cron_token = 'secret';
        $ignored = new ExtensionWithCronMethodsButNoContract();
        $this->setExtensions($app, ['plg_ignored' => $ignored]);

        $result = (new CronDispatcher($app))->run(
            $this->request('secret'),
            CronTick::fromDateTime(new \DateTimeImmutable('2026-11-18 09:07:00')),
        );

        self::assertTrue($result['ok']);
        self::assertSame([], $result['results']['minute']);
        self::assertSame([], $ignored->calls);
    }

    #[Test]
    public function twoTokenSourcesAreRejected(): void
    {
        $app = $this->createApplication();
        $app->getConf()->cron_token = 'secret';
        $this->setExtensions($app, []);

        $this->expectException(ExceptionResponse::class);

        (new CronDispatcher($app))->run(
            new Request(
                method: RequestMethod::POST,
                query: ['cron_token' => 'secret'],
                headers: ['Authorization' => 'Bearer secret'],
            ),
        );
    }

    #[Test]
    public function httpBasicCanUseTheCronTokenAsPassword(): void
    {
        $app = $this->createApplication();
        $app->getConf()->cron_token = 'secret';
        $extension = new RecordingCronExtension();
        $this->setExtensions($app, ['plg_test' => $extension]);

        $result = (new CronDispatcher($app))->run(
            new Request(
                method: RequestMethod::GET,
                headers: ['Authorization' => 'Basic ' . base64_encode('cron:secret')],
            ),
            CronTick::fromDateTime(new \DateTimeImmutable('2026-11-18 09:07:00')),
        );

        self::assertTrue($result['ok']);
        self::assertSame(['minute'], $extension->calls);
    }

    #[Test]
    public function httpBasicCanComeFromThePhpAuthServerVariables(): void
    {
        $app = $this->createApplication();
        $app->getConf()->cron_token = 'secret';
        $extension = new RecordingCronExtension();
        $this->setExtensions($app, ['plg_test' => $extension]);

        $result = (new CronDispatcher($app))->run(
            new Request(
                method: RequestMethod::GET,
                server: ['PHP_AUTH_USER' => 'cron', 'PHP_AUTH_PW' => 'secret'],
            ),
            CronTick::fromDateTime(new \DateTimeImmutable('2026-11-18 09:07:00')),
        );

        self::assertTrue($result['ok']);
        self::assertSame(['minute'], $extension->calls);
    }

    #[Test]
    public function invalidHttpBasicIsRefused(): void
    {
        $app = $this->createApplication();
        $app->getConf()->cron_token = 'secret';
        $this->setExtensions($app, []);

        $this->expectException(ExceptionResponse::class);

        (new CronDispatcher($app))->run(
            new Request(
                method: RequestMethod::GET,
                headers: ['Authorization' => 'Basic ' . base64_encode('cron:wrong')],
            ),
        );
    }

    #[Test]
    public function missingTokenIsRefused(): void
    {
        $app = $this->createApplication();
        $this->setExtensions($app, []);

        $this->expectException(ExceptionResponse::class);

        (new CronDispatcher($app))->run($this->request('secret'));
    }

    #[Test]
    public function invalidTokenIsRefused(): void
    {
        $app = $this->createApplication();
        $app->getConf()->cron_token = 'secret';
        $this->setExtensions($app, []);

        $this->expectException(ExceptionResponse::class);

        (new CronDispatcher($app))->run($this->request('wrong'));
    }

    private function request(string $token): Request
    {
        return new Request(
            method: RequestMethod::POST,
            headers: ['Authorization' => 'Bearer ' . $token],
        );
    }

    /** @param array<string, object> $extensions */
    private function setExtensions(Application $app, array $extensions): void
    {
        (new ReflectionProperty(Application::class, 'extensions'))->setValue($app, $extensions);
    }
}

final class ExtensionWithCronMethodsButNoContract
{
    /** @var list<string> */
    public array $calls = [];

    /** @return array<string, mixed> */
    public function onCronMinute(CronTick $tick): array
    {
        $this->calls[] = 'minute';

        return ['called' => true];
    }
}

final class RecordingCronExtension implements ICronProvider
{
    /** @var list<string> */
    public array $calls = [];

    public function __construct(private readonly bool $fail = false)
    {
    }

    /** @return array<string, mixed> */
    public function onCronMinute(CronTick $tick): array
    {
        return $this->record('minute', $tick);
    }

    /** @return array<string, mixed> */
    public function onCron15Min(CronTick $tick): array
    {
        return $this->record('quarter', $tick);
    }

    /** @return array<string, mixed> */
    public function onCronHour(CronTick $tick): array
    {
        return $this->record('hour', $tick);
    }

    /** @return array<string, mixed> */
    private function record(string $hook, CronTick $tick): array
    {
        $this->calls[] = $hook;

        if ($this->fail) {
            throw new \RuntimeException('Hook en échec de test.');
        }

        return ['hook' => $hook, 'hour' => $tick->hour()];
    }
}
