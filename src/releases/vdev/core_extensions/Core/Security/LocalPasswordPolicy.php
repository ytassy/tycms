<?php

namespace YTassy\Tycms\CoreExts\Core\Security;

/** La borne en octets évite toute troncature silencieuse par bcrypt. */
final class LocalPasswordPolicy
{
    public const MAX_BYTES = 72;
    public const MESSAGE = 'Le mot de passe doit contenir de 12 à 72 octets, sans caractère nul.';

    public static function acceptsNewPassword(mixed $password): bool
    {
        return is_string($password) && strlen($password) >= 12 && strlen($password) <= self::MAX_BYTES
            && !str_contains($password, "\0");
    }
}
