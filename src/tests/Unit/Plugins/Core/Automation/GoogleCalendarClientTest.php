<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\Automation;

use PHPUnit\Framework\Attributes\Test;
use YTassy\Tycms\CoreExts\Core\Automation\Support\GoogleCalendarClient;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class GoogleCalendarClientTest extends FrameworkTestCase
{
    #[Test]
    public function calendarIdentifiersAreValidated(): void
    {
        $client = new GoogleCalendarClient($this->createApplication());

        self::assertSame('primary', $client->calendarId('primary'));
        self::assertSame('agenda@group.calendar.google.com', $client->calendarId('agenda@group.calendar.google.com'));

        $this->expectException(ExceptionResponse::class);
        $client->calendarId('../agenda');
    }

    #[Test]
    public function allDayAndTimedDatesAreMapped(): void
    {
        $client = new GoogleCalendarClient($this->createApplication());
        $allDay = $client->eventDateTime('2026-11-19', null);
        self::assertSame('2026-11-19', $allDay->getDate());
        self::assertNull($allDay->getDateTime());

        $timed = $client->eventDateTime('2026-11-19T10:00:00+01:00', 'Europe/Paris');
        self::assertSame('2026-11-19T10:00:00+01:00', $timed->getDateTime());
        self::assertSame('Europe/Paris', $timed->getTimeZone());
    }

    #[Test]
    public function timezoneAndSendUpdatesAreValidated(): void
    {
        $client = new GoogleCalendarClient($this->createApplication());

        self::assertSame('Europe/Paris', $client->timezone('Europe/Paris'));
        self::assertSame('none', $client->sendUpdates(null));
        self::assertSame('all', $client->sendUpdates('all'));

        $this->expectException(ExceptionResponse::class);
        $client->timezone('Mars/Olympus');
    }
}
