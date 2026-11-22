<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Language;

enum Severity: string
{
    case ERROR = 'error';
    case WARNING = 'warning';
}
