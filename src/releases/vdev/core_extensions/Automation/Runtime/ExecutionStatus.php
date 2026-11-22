<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Runtime;

enum ExecutionStatus: string
{
    case SUCCESS = 'success';
    case STOPPED = 'stopped';
    case FAILED = 'failed';
}
