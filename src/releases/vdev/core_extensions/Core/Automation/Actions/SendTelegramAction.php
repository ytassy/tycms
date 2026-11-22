<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

final class SendTelegramAction extends SendProviderNotificationAction
{
    protected function provider(): string
    {
        return 'telegram';
    }
}
