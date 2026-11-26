<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Framework;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\Tools\DateTools;

final class DateToolsTest extends TestCase
{
    public function testInstantIsConvertedToUtc(): void
    {
        $date = new DateTimeImmutable('2026-06-15 20:30:00', new DateTimeZone('Europe/Paris'));

        self::assertSame('2026-06-15 18:30:00', DateTools::toSql($date));
    }

    public function testUtcInstantIsUnchanged(): void
    {
        $date = new DateTimeImmutable('2026-06-15 18:30:00', new DateTimeZone('UTC'));

        self::assertSame('2026-06-15 18:30:00', DateTools::toSql($date));
    }

    /**
     * Une colonne `date` porte un jour calendaire : la conversion ne doit pas
     * décaler le jour pour un fuseau en avance sur UTC.
     */
    public function testCalendarDateIsNotShifted(): void
    {
        $date = new DateTimeImmutable('2026-06-15 00:30:00', new DateTimeZone('Europe/Paris'));

        self::assertSame('2026-06-15', DateTools::toSqlDate($date));
    }

    public function testStringIsReadInThePhpTimezone(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('Europe/Paris');

        try {
            self::assertSame('2026-06-15 18:30:00', DateTools::parseToSql('2026-06-15 20:30:00'));
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public function testInvalidStringReturnsNull(): void
    {
        self::assertNull(DateTools::parseToSql('pas une date'));
    }

    public function testTimestampBasedDateKeepsItsInstant(): void
    {
        $date = new DateTimeImmutable('@1795716222');

        self::assertSame('2026-11-26 18:03:42', DateTools::toSql($date));
    }
}
