<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\CoreExts\Core\Cron\CronTick;

final class CronTickTest extends TestCase
{
    #[Test]
    public function hourTickIsAlsoAQuarterTick(): void
    {
        $tick = CronTick::fromDateTime(new \DateTimeImmutable('2026-11-18 09:00:05'));

        self::assertTrue($tick->isHour());
        self::assertTrue($tick->isQuarterHour());
        self::assertSame(0, $tick->minute());
        self::assertSame(9, $tick->hour());
    }

    #[Test]
    public function quarterTickIsNotAnHourTick(): void
    {
        $tick = CronTick::fromDateTime(new \DateTimeImmutable('2026-11-18 09:15:00'));

        self::assertFalse($tick->isHour());
        self::assertTrue($tick->isQuarterHour());
    }

    #[Test]
    public function ordinaryMinuteIsNotAQuarterOrHourTick(): void
    {
        $tick = CronTick::fromDateTime(new \DateTimeImmutable('2026-11-18 09:07:00'));

        self::assertFalse($tick->isHour());
        self::assertFalse($tick->isQuarterHour());
        self::assertSame('2026-11-18T09:07:00+01:00', $tick->iso());
    }
}
