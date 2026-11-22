<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\Automation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\CoreExts\Automation\Webhook\WebhookConfig;

final class WebhookConfigTest extends TestCase
{
    #[Test]
    public function invalidOrListJsonIsIgnored(): void
    {
        self::assertSame([], WebhookConfig::decode(null));
        self::assertSame([], WebhookConfig::decode('{'));
        self::assertSame([], WebhookConfig::decode('["bearer"]'));
    }

    #[Test]
    public function unknownAuthModeFallsBackToBearer(): void
    {
        self::assertSame('bearer', WebhookConfig::authMode([]));
        self::assertSame('bearer', WebhookConfig::authMode(['auth_mode' => 'inconnu']));
        self::assertSame('query', WebhookConfig::authMode(['auth_mode' => 'query']));
    }

    #[Test]
    public function responseModeDefaultsToAccepted(): void
    {
        self::assertSame('accepted', WebhookConfig::responseMode([]));
        self::assertSame('accepted', WebhookConfig::responseMode(['response_mode' => 'inconnu']));
        self::assertSame('result', WebhookConfig::responseMode(['response_mode' => 'result']));
    }

    #[Test]
    public function mergingKeepsOnlyTheSecretNeededByTheMode(): void
    {
        $existing = ['auth_mode' => 'hmac', 'hmac_secret' => 'chiffré'];

        self::assertSame(
            ['auth_mode' => 'hmac', 'response_mode' => 'accepted', 'hmac_secret' => 'chiffré'],
            WebhookConfig::merge((object) ['auth_mode' => 'hmac'], $existing),
        );
        self::assertSame(
            ['auth_mode' => 'bearer', 'response_mode' => 'accepted'],
            WebhookConfig::merge((object) ['auth_mode' => 'bearer'], $existing),
        );
    }

    #[Test]
    public function mergingKeepsTheConfiguredResponseMode(): void
    {
        $existing = ['auth_mode' => 'bearer', 'response_mode' => 'result'];

        self::assertSame(
            ['auth_mode' => 'bearer', 'response_mode' => 'result'],
            WebhookConfig::merge(null, $existing),
        );
        self::assertSame(
            ['auth_mode' => 'bearer', 'response_mode' => 'accepted'],
            WebhookConfig::merge((object) ['response_mode' => 'accepted'], $existing),
        );
    }

    #[Test]
    public function secretConfiguredDependsOnTheMode(): void
    {
        self::assertTrue(WebhookConfig::secretConfigured(
            (object) ['secret_hash' => 'hash'],
            ['auth_mode' => 'bearer'],
        ));
        self::assertFalse(WebhookConfig::secretConfigured(
            (object) ['secret_hash' => ''],
            ['auth_mode' => 'query'],
        ));
        self::assertTrue(WebhookConfig::secretConfigured(
            (object) ['secret_hash' => null],
            ['auth_mode' => 'hmac', 'hmac_secret' => 'chiffré'],
        ));
        self::assertFalse(WebhookConfig::secretConfigured(
            (object) ['secret_hash' => null],
            ['auth_mode' => 'hmac'],
        ));
    }
}
