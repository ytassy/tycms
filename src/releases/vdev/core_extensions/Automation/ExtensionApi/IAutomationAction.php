<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\ExtensionApi;

interface IAutomationAction
{
    /** @param array<string, mixed> $params */
    public function execute(ActionContext $context, array $params): mixed;
}
