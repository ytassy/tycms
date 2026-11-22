<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Runtime;

use YTassy\Tycms\CoreExts\Automation\Language\Diagnostic;

final class ExecutionException extends \RuntimeException
{
    public function __construct(public readonly Diagnostic $diagnostic)
    {
        parent::__construct((string) $diagnostic);
    }
}
