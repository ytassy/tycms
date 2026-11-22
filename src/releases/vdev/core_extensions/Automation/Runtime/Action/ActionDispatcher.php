<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Runtime\Action;

use YTassy\Tycms\CoreExts\Automation\Language\SourceSpan;
use YTassy\Tycms\CoreExts\Automation\Runtime\RuntimeServices;

interface ActionDispatcher
{
    /** @param array<mixed> $arguments */
    public function dispatch(
        string $identifier,
        array $arguments,
        SourceSpan $span,
        RuntimeServices $services,
    ): mixed;
}
