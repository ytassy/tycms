<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\Notification;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\CoreExts\Core\Notification\NotificationChannelDefinition;

final class NotificationChannelDefinitionTest extends TestCase
{
    #[Test]
    public function nullPermissionMeansEveryone(): void
    {
        $channel = new NotificationChannelDefinition('telegram', 'Telegram', 4096);

        self::assertNull($channel->permission);
        self::assertTrue($channel->isOpenToEveryone());
    }

    #[Test]
    public function permissionIsStoredAsAFullAclName(): void
    {
        $channel = new NotificationChannelDefinition(
            'webhook',
            'Webhook',
            2000,
            ' plg_webhook__USE_NOTIF_WEBHOOK_CHANNEL ',
        );

        self::assertSame('plg_webhook__USE_NOTIF_WEBHOOK_CHANNEL', $channel->permission);
        self::assertFalse($channel->isOpenToEveryone());
    }

    #[Test]
    public function malformedPermissionIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new NotificationChannelDefinition('webhook', 'Webhook', 2000, 'USE_NOTIF_WEBHOOK_CHANNEL');
    }

    #[Test]
    public function emptyPermissionIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new NotificationChannelDefinition('webhook', 'Webhook', 2000, '');
    }

    #[Test]
    public function identifierMustFitTheDatabaseColumn(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new NotificationChannelDefinition(str_repeat('a', 33), 'Webhook', 2000);
    }

    #[Test]
    public function messageLengthIsBounded(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new NotificationChannelDefinition('webhook', 'Webhook', 0);
    }

    #[Test]
    public function labelIsRequired(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new NotificationChannelDefinition('webhook', '  ', 2000);
    }
}
