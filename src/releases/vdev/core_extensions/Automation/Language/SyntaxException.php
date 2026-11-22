<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Language;

final class SyntaxException extends \RuntimeException
{
    public function __construct(public readonly Diagnostic $diagnostic)
    {
        parent::__construct((string) $diagnostic);
    }
}
