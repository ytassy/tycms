<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Runtime\Environment;

final class ReadOnlyVariableException extends \RuntimeException
{
    public function __construct(public readonly string $variable)
    {
        parent::__construct('Variable en lecture seule : $' . $variable);
    }
}
