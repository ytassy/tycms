<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\Automation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\CoreExts\Automation\Schedule\ScheduleCalculator;

final class ScheduleCalculatorTest extends TestCase
{
    private const TZ = 'Europe/Paris';

    #[Test]
    public function everyFifteenMinutesIsDueOnQuarterHours(): void
    {
        $config = ['preset' => 'every_15_minutes', 'time' => '09:00', 'weekday' => 1];

        self::assertNull(ScheduleCalculator::dueSlot($config, self::TZ, $this->at('09:14:59')));
        self::assertSame(
            '2026-11-17T09:15',
            ScheduleCalculator::slotLabel(
                ScheduleCalculator::dueSlot($config, self::TZ, $this->at('09:15:00')),
            ),
        );
    }

    #[Test]
    public function nextFifteenMinuteSlotIsStrictlyAfterNow(): void
    {
        $config = ['preset' => 'every_15_minutes', 'time' => '09:00', 'weekday' => 1];

        self::assertSame(
            '2026-11-17T09:15',
            ScheduleCalculator::slotLabel(ScheduleCalculator::nextRun($config, self::TZ, $this->at('09:14:59'))),
        );
        self::assertSame(
            '2026-11-17T09:30',
            ScheduleCalculator::slotLabel(ScheduleCalculator::nextRun($config, self::TZ, $this->at('09:15:00'))),
        );
    }

    #[Test]
    public function hourlyRunsOnTheHour(): void
    {
        $config = ['preset' => 'hourly', 'time' => '09:00', 'weekday' => 1];

        self::assertNull(ScheduleCalculator::dueSlot($config, self::TZ, $this->at('10:01:00')));
        self::assertSame(
            '2026-11-17T10:00',
            ScheduleCalculator::slotLabel(ScheduleCalculator::dueSlot($config, self::TZ, $this->at('10:00:30'))),
        );
        self::assertSame(
            '2026-11-17T11:00',
            ScheduleCalculator::slotLabel(ScheduleCalculator::nextRun($config, self::TZ, $this->at('10:00:00'))),
        );
    }

    #[Test]
    public function dailyUsesTheSpaceTimezone(): void
    {
        // 08:00 UTC correspond à 09:00 à Paris en novembre.
        $now = new \DateTimeImmutable('2026-11-17 08:00:00', new \DateTimeZone('UTC'));
        $config = ['preset' => 'daily', 'time' => '09:00', 'weekday' => 1];

        self::assertSame(
            '2026-11-17T09:00',
            ScheduleCalculator::slotLabel(ScheduleCalculator::dueSlot($config, self::TZ, $now)),
        );
        self::assertSame(
            '2026-11-18T09:00',
            ScheduleCalculator::slotLabel(ScheduleCalculator::nextRun($config, self::TZ, $now)),
        );
    }

    #[Test]
    public function weeklyUsesIsoWeekdays(): void
    {
        // 17 novembre 2026 est un mardi ; le jour 1 est le lundi.
        $config = ['preset' => 'weekly', 'time' => '09:00', 'weekday' => 1];

        self::assertNull(ScheduleCalculator::dueSlot($config, self::TZ, $this->at('2026-11-17 09:00:00')));
        self::assertSame(
            '2026-11-23T09:00',
            ScheduleCalculator::slotLabel(ScheduleCalculator::nextRun($config, self::TZ, $this->at('2026-11-17 09:00:00'))),
        );
    }

    #[Test]
    public function weeklyIsDueOnTheConfiguredDay(): void
    {
        $config = ['preset' => 'weekly', 'time' => '09:00', 'weekday' => 2];

        self::assertSame(
            '2026-11-17T09:00',
            ScheduleCalculator::slotLabel(ScheduleCalculator::dueSlot($config, self::TZ, $this->at('2026-11-17 09:00:00'))),
        );
    }

    #[Test]
    public function nonexistentLocalTimeIsNotDueDuringSpringForward(): void
    {
        $config = ['preset' => 'daily', 'time' => '02:30', 'weekday' => 1];
        $now = new \DateTimeImmutable('2026-03-29 03:30:00', new \DateTimeZone(self::TZ));

        // 02:30 n'existe pas ce jour-là à Paris ; PHP normalise la date de
        // prochaine exécution, mais le créneau courant ne doit pas être dû.
        self::assertNull(ScheduleCalculator::dueSlot($config, self::TZ, $now));
    }

    #[Test]
    public function scheduledAtKeepsTheTimezoneOffset(): void
    {
        $slot = $this->at('2026-11-17 09:00:00');

        self::assertSame('2026-11-17T09:00:00+01:00', ScheduleCalculator::scheduledAt($slot));
    }

    private function at(string $value): \DateTimeImmutable
    {
        // Les cas sans date utilisent une date fixe pour rester déterministes.
        if (preg_match('/\A\d{4}-\d{2}-\d{2}/', $value) !== 1) {
            $value = '2026-11-17 ' . $value;
        }

        return new \DateTimeImmutable($value, new \DateTimeZone(self::TZ));
    }
}
