<?php

declare(strict_types=1);

namespace YTassy\Tycms\Lib\Forms;

interface FormOptionsProvider
{
    /**
     * @return array<int|string,mixed>
     */
    public function resolve(FormOptionContext $context): array;
}
