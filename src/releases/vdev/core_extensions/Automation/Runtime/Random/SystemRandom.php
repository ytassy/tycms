<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Runtime\Random;

final class SystemRandom implements RandomSource
{
    public function int(int $min, int $max): int
    {
        return random_int($min, $max);
    }

    public function float(): float
    {
        return random_int(0, 1_000_000) / 1_000_000;
    }
}
