<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Runtime\Environment;

final class UndefinedVariableException extends \RuntimeException
{
    public function __construct(public readonly string $variable)
    {
        parent::__construct('Variable non définie : $' . $variable);
    }
}
