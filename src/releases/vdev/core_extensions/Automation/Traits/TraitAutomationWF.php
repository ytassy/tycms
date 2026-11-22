<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Traits;

use YTassy\Tycms\CoreExts\Automation\WF\WFAutomation;

trait TraitAutomationWF
{
    protected WFAutomation $wfAutomation {
        get => $this->getSharedExtensionsClass(WFAutomation::class);
    }
}
