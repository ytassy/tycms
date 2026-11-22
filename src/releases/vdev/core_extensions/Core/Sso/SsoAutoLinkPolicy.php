<?php

namespace YTassy\Tycms\CoreExts\Core\Sso;

final class SsoAutoLinkPolicy
{
    public static function mode(mixed $value): int
    {
        return match ($value) {
            true, 1 => 1,
            2 => 2,
            default => 0,
        };
    }

    public static function allows(mixed $mode, mixed $emailVerified): bool
    {
        return match (self::mode($mode)) {
            1 => $emailVerified === true,
            2 => true,
            default => false,
        };
    }
}
