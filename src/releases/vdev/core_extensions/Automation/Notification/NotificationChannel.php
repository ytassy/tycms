<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Notification;

interface NotificationChannel
{
    public function send(string $title, string $message): void;
}
