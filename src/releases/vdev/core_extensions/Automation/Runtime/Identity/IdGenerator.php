<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Runtime\Identity;

interface IdGenerator
{
    public function uuid(): string;
}
