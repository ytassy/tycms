<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Runtime\Signal;

final class ReturnSignal extends \Exception
{
    public function __construct(public readonly mixed $value)
    {
        parent::__construct('Retour de fonction.');
    }
}
