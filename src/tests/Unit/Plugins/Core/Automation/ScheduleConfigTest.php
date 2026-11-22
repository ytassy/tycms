<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\Automation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\CoreExts\Automation\Schedule\ScheduleConfig;

final class ScheduleConfigTest extends TestCase
{
    #[Test]
    public function defaultsAreAppliedToAnEmptyConfiguration(): void
    {
        self::assertSame(
            ['preset' => 'daily', 'time' => '09:00', 'weekday' => 1],
            ScheduleConfig::normalize([]),
        );
    }

    #[Test]
    public function numericStringsAreAcceptedForTheWeekday(): void
    {
        self::assertSame(3, ScheduleConfig::normalize(['weekday' => '3'])['weekday']);
    }

    #[Test]
    public function missingValuesKeepTheExistingConfiguration(): void
    {
        $existing = ['preset' => 'weekly', 'time' => '18:30', 'weekday' => 5];

        self::assertSame($existing, ScheduleConfig::normalize([], $existing));
    }

    #[DataProvider('invalidConfigurations')]
    #[Test]
    public function invalidConfigurationIsRejected(array $configuration): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ScheduleConfig::normalize($configuration);
    }

    public static function invalidConfigurations(): iterable
    {
        yield 'preset inconnu' => [['preset' => 'monthly']];
        yield 'heure invalide' => [['time' => '24:00']];
        yield 'jour invalide' => [['weekday' => 8]];
    }

    #[Test]
    public function jsonListsAndInvalidJsonAreIgnored(): void
    {
        self::assertSame([], ScheduleConfig::decode('["daily"]'));
        self::assertSame([], ScheduleConfig::decode('{'));
    }
}
