<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Runtime\Clock;

use DateTimeImmutable;
use DateTimeZone;
use YTassy\Tycms\CoreExts\Automation\Runtime\DateValue;

final class SystemClock implements RuntimeClock
{
    public function __construct(private readonly string $timezone = 'UTC')
    {
    }

    public function now(): DateValue
    {
        return new DateValue(new DateTimeImmutable('now', new DateTimeZone($this->timezone)));
    }
}
