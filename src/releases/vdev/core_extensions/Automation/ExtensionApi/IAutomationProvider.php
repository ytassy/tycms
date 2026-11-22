<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\ExtensionApi;

interface IAutomationProvider
{
    public function coreOnRegisterAutomationActions(AutomationActionRegistry $registry): void;

    public function coreOnRegisterAutomationTriggers(AutomationTriggerRegistry $registry): void;
}
