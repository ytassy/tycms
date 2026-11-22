<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Notification;

final class TelegramChannel implements NotificationChannel
{
    public function __construct(
        private readonly string $token,
        private readonly string $chatId,
    ) {
        if ($token === '' || $chatId === '') {
            throw new \InvalidArgumentException('Configuration Telegram incomplète.');
        }
    }

    public function send(string $title, string $message): void
    {
        $text = mb_substr($title . "\n\n" . $message, 0, 4096, 'UTF-8');

        CurlNotificationTransport::postForm(
            'https://api.telegram.org/bot' . $this->token . '/sendMessage',
            [
                'chat_id' => $this->chatId,
                'text' => $text,
                'disable_web_page_preview' => true,
            ],
        );
    }
}
