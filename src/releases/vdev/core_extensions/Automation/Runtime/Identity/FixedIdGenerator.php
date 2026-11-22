<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Runtime\Identity;

final readonly class FixedIdGenerator implements IdGenerator
{
    public function __construct(private string $value = '00000000-0000-4000-8000-000000000000')
    {
    }

    public function uuid(): string
    {
        return $this->value;
    }
}
