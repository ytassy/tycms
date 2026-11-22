<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Notification;

final class SlackChannel implements NotificationChannel
{
    public function __construct(private readonly string $webhookUrl)
    {
        $parts = parse_url($webhookUrl);

        if (!is_array($parts)
            || ($parts['scheme'] ?? '') !== 'https'
            || ($parts['host'] ?? '') !== 'hooks.slack.com') {
            throw new \InvalidArgumentException('URL de webhook Slack invalide.');
        }
    }

    public function send(string $title, string $message): void
    {
        CurlNotificationTransport::postJson($this->webhookUrl, [
            'text' => mb_substr($title . "\n\n" . $message, 0, 4000, 'UTF-8'),
        ]);
    }
}
