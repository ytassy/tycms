<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\Notification;

use PHPUnit\Framework\Attributes\Test;
use YTassy\Tycms\CoreExts\Core\Notification\NotificationChannelDefinition;
use YTassy\Tycms\CoreExts\Core\Notification\NotificationChannelRegistry;
use YTassy\Tycms\Exceptions\TechException;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class NotificationChannelRegistryTest extends FrameworkTestCase
{
    #[Test]
    public function channelIsIndexedWithItsOwner(): void
    {
        $registry = new NotificationChannelRegistry($this->createApplication());
        $registry->withOwner('plg_core', function () use ($registry): void {
            $registry->register($this->channel());
        });

        self::assertTrue($registry->has('webhook'));
        self::assertSame('plg_core', $registry->ownerOf('webhook'));
        self::assertSame(2000, $registry->get('webhook')?->maxMessageLength);
    }

    #[Test]
    public function duplicateChannelIsRefused(): void
    {
        $registry = new NotificationChannelRegistry($this->createApplication());
        $registry->withOwner('plg_core', fn () => $registry->register($this->channel()));

        $this->expectException(TechException::class);
        $this->expectExceptionMessageMatches('/plg_core.*plg_webhook/');

        $registry->withOwner('plg_webhook', fn () => $registry->register($this->channel()));
    }

    #[Test]
    public function builtInIdentifierIsReservedToCore(): void
    {
        $registry = new NotificationChannelRegistry($this->createApplication());

        $this->expectException(TechException::class);
        $this->expectExceptionMessageMatches('/réservé à Core/');

        $registry->withOwner('plg_webhook', fn () => $registry->register(
            new NotificationChannelDefinition('email', 'Email', 65535),
        ));
    }

    #[Test]
    public function coreCanDeclareItsBuiltInChannels(): void
    {
        $registry = new NotificationChannelRegistry($this->createApplication());
        $registry->withOwner('plg_core', fn () => $registry->register(
            new NotificationChannelDefinition('email', 'Email', 65535),
        ));

        self::assertTrue($registry->has('email'));
        self::assertSame('plg_core', $registry->ownerOf('email'));
    }

    private function channel(): NotificationChannelDefinition
    {
        return new NotificationChannelDefinition(
            'webhook',
            'Webhook',
            2000,
            'plg_webhook__USE_NOTIF_WEBHOOK_CHANNEL',
        );
    }
}
