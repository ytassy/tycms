<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Runtime\Signal;

final class StopSignal extends \Exception
{
    public function __construct(public readonly ?string $reason = null)
    {
        parent::__construct('Arrêt du scénario.');
    }
}
