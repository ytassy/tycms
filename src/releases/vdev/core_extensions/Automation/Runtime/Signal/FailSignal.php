<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Runtime\Signal;

use YTassy\Tycms\CoreExts\Automation\Language\SourceSpan;

final class FailSignal extends \Exception
{
    public function __construct(
        public readonly string $reason,
        public readonly SourceSpan $span,
    ) {
        parent::__construct($reason);
    }
}
