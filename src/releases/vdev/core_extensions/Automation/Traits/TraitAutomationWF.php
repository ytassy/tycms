<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Traits;

use YTassy\Tycms\CoreExts\Automation\WF\WFAutomation;
use YTassy\Tycms\CoreExts\Automation\WF\WFAutomationMcp;

trait TraitAutomationWF
{
    protected WFAutomation $wfAutomation {
        get => $this->getSharedExtensionsClass(WFAutomation::class);
    }

    protected WFAutomationMcp $wfAutomationMcp {
        get => $this->getSharedExtensionsClass(WFAutomationMcp::class);
    }
}
