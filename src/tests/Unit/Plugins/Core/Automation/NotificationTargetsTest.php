<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\Automation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\CoreExts\Automation\Notification\NotificationSecrets;
use YTassy\Tycms\CoreExts\Automation\Notification\NotificationTargets;

final class NotificationTargetsTest extends TestCase
{
    #[Test]
    public function validProfilesAreParsed(): void
    {
        $targets = NotificationTargets::parse(<<<'JSON'
{
    "support": {"type": "telegram", "token": "123:ABC", "chat_id": "456"},
    "alertes": {"type": "slack", "webhook": "https://hooks.slack.com/services/1/2/3"},
    "equipe": {"type": "discord", "webhook": "https://discord.com/api/webhooks/1/2"}
}
JSON);

        self::assertSame(['support', 'alertes', 'equipe'], array_keys($targets));
        self::assertSame('telegram', $targets['support']['type']);
        self::assertSame('https://hooks.slack.com/services/1/2/3', $targets['alertes']['webhook']);
    }

    #[Test]
    public function emptyProfileMapIsAccepted(): void
    {
        self::assertSame([], NotificationTargets::parse('{}'));
    }

    #[Test]
    public function malformedProfilesAreRejected(): void
    {
        $cases = [
            '[]',
            '{"Support":{"type":"telegram","token":"x","chat_id":"1"}}',
            '{"support":{"type":"email","webhook":"https://example.test"}}',
            '{"support":{"type":"telegram","token":"x"}}',
            '{"support":{"type":"telegram","token":"x","chat_id":"1","extra":"y"}}',
            '{"support":{"type":"slack","webhook":"http://hooks.slack.com/services/1"}}',
            '{"support":{"type":"telegram","token":"","chat_id":"1"}}',
        ];

        foreach ($cases as $json) {
            try {
                NotificationTargets::parse($json);
                self::fail('Un profil invalide doit être refusé : ' . $json);
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    #[Test]
    public function secretsListSeparatesFailureAndScenarioChannels(): void
    {
        self::assertContains('telegram_token', NotificationSecrets::FIELDS);
        self::assertContains('scenario_telegram_token', NotificationSecrets::FIELDS);
        self::assertContains('scenario_slack_webhook', NotificationSecrets::FIELDS);
        self::assertNotContains(NotificationSecrets::TARGETS_FIELD, NotificationSecrets::FIELDS);
    }
}
