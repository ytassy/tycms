<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Webhook;

/** Modes d'authentification d'un webhook de scénario. */
final class WebhookAuthMode
{
    public const BEARER = 'bearer';
    public const QUERY = 'query';
    public const HMAC = 'hmac';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::BEARER, self::QUERY, self::HMAC];
    }

    public static function isValid(string $mode): bool
    {
        return in_array($mode, self::all(), true);
    }

    private function __construct()
    {
    }
}
