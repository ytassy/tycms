<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Notification;

final class NotificationSecrets
{
    public const PURPOSE = 'automation:notification:v1';
    public const MASK = '********';

    /** Canaux automatiques des runs en échec. */
    public const FAILURE_FIELDS = ['telegram_token', 'slack_webhook', 'discord_webhook'];

    /** Canaux utilisés par l'action core::notify. */
    public const SCENARIO_FIELDS = [
        'scenario_telegram_token',
        'scenario_slack_webhook',
        'scenario_discord_webhook',
    ];

    /** Champ JSON chiffré des profils de notification nommés. */
    public const TARGETS_FIELD = 'scenario_targets';
    public const TARGETS_PURPOSE = 'automation:notification:targets:v1';

    /** Tous les secrets de notification, échec et scénario. */
    public const FIELDS = [...self::FAILURE_FIELDS, ...self::SCENARIO_FIELDS];

    private function __construct()
    {
    }
}
