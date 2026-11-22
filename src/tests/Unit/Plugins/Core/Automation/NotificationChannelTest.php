<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\Automation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\CoreExts\Automation\Notification\DiscordChannel;
use YTassy\Tycms\CoreExts\Automation\Notification\SlackChannel;
use YTassy\Tycms\CoreExts\Automation\Notification\TelegramChannel;

final class NotificationChannelTest extends TestCase
{
    #[Test]
    public function slackOnlyAcceptsTheOfficialWebhookHost(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SlackChannel('https://example.test/services/1/2/3');
    }

    #[Test]
    public function discordOnlyAcceptsTheOfficialWebhookPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DiscordChannel('https://example.test/api/webhooks/1/2');
    }

    #[Test]
    public function validTelegramAndDiscordConfigurationsCanBeBuiltWithoutSending(): void
    {
        self::assertInstanceOf(TelegramChannel::class, new TelegramChannel('token', '123'));
        self::assertInstanceOf(
            DiscordChannel::class,
            new DiscordChannel('https://discord.com/api/webhooks/123/abc'),
        );
        self::assertInstanceOf(
            SlackChannel::class,
            new SlackChannel('https://hooks.slack.com/services/1/2/3'),
        );
    }

    #[Test]
    public function telegramRequiresTokenAndChatId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TelegramChannel('', '123');
    }
}
