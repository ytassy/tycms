<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

final class SendSlackAction extends SendProviderNotificationAction
{
    protected function provider(): string
    {
        return 'slack';
    }
}
