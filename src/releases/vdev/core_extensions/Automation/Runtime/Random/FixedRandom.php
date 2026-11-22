<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Runtime\Random;

final class FixedRandom implements RandomSource
{
    public function __construct(
        private readonly int $intValue = 0,
        private readonly float $floatValue = 0.0,
    ) {
    }

    public function int(int $min, int $max): int
    {
        return max($min, min($max, $this->intValue));
    }

    public function float(): float
    {
        return $this->floatValue;
    }
}
