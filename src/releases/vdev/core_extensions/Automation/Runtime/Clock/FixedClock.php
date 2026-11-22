<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Runtime\Clock;

use YTassy\Tycms\CoreExts\Automation\Runtime\DateValue;

final readonly class FixedClock implements RuntimeClock
{
    public function __construct(private DateValue $value)
    {
    }

    public function now(): DateValue
    {
        return $this->value;
    }
}
