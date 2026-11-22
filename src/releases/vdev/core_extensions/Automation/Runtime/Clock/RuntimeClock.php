<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Runtime\Clock;

use YTassy\Tycms\CoreExts\Automation\Runtime\DateValue;

interface RuntimeClock
{
    public function now(): DateValue;
}
