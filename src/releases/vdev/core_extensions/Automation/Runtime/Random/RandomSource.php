<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Runtime\Random;

interface RandomSource
{
    public function int(int $min, int $max): int;

    public function float(): float;
}
