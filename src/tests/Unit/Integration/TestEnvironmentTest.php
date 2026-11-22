<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use YTassy\Tycms\Tests\Support\Integration\TestEnvironment;

final class TestEnvironmentTest extends TestCase
{
    #[Test]
    #[DataProvider('unsafeTargets')]
    public function rejectsUnsafeDatabaseTargets(array $override): void
    {
        $this->expectException(RuntimeException::class);
        TestEnvironment::fromArray(array_replace(self::safeValues(), $override));
    }

    public static function unsafeTargets(): iterable
    {
        yield 'remote host' => [['host' => 'db.example.test']];
        yield 'remote MariaDB host' => [['host' => 'mariadb.example.test']];
        yield 'different Docker host' => [['host' => 'mariadb-prod']];
        yield 'development database' => [['database' => 'tycms_main']];
        yield 'development account' => [['user' => 'tycms_app']];
        yield 'empty password' => [['password' => '']];
    }

    #[Test]
    #[DataProvider('allowedHosts')]
    public function acceptsAnIsolatedDatabaseAndRuntime(string $host): void
    {
        $environment = TestEnvironment::fromArray(array_replace(self::safeValues(), ['host' => $host]));

        self::assertSame($host, $environment->host);

        self::assertSame('tycms_test_example', $environment->database);
        self::assertStringEndsWith('/tests/.runtime/tycms_test_example',
            str_replace('\\', '/', $environment->runtimeRoot()));
    }

    public static function allowedHosts(): iterable
    {
        yield 'IPv4' => ['127.0.0.1'];
        yield 'localhost' => ['localhost'];
        yield 'IPv6' => ['::1'];
        yield 'Docker' => ['mariadb'];
    }

    private static function safeValues(): array
    {
        return [
            'host' => '127.0.0.1',
            'database' => 'tycms_test_example',
            'user' => 'tycms_test_runner',
            'password' => 'test-only-placeholder',
        ];
    }
}
