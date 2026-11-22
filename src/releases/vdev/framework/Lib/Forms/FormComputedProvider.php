<?php

declare(strict_types=1);

namespace YTassy\Tycms\Lib\Forms;

interface FormComputedProvider
{
    /** Returns the trusted value to persist for one core.computed field. */
    public function compute(FormComputedContext $context): mixed;
}
