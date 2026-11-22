<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Notification;

final class DiscordChannel implements NotificationChannel
{
    public function __construct(private readonly string $webhookUrl)
    {
        $parts = parse_url($webhookUrl);
        $host = is_array($parts) ? ($parts['host'] ?? '') : '';
        $path = is_array($parts) ? ($parts['path'] ?? '') : '';

        if (!is_array($parts)
            || ($parts['scheme'] ?? '') !== 'https'
            || !in_array($host, ['discord.com', 'discordapp.com'], true)
            || !str_starts_with($path, '/api/webhooks/')) {
            throw new \InvalidArgumentException('URL de webhook Discord invalide.');
        }
    }

    public function send(string $title, string $message): void
    {
        CurlNotificationTransport::postJson($this->webhookUrl, [
            'content' => mb_substr($title . "\n\n" . $message, 0, 2000, 'UTF-8'),
        ]);
    }
}
