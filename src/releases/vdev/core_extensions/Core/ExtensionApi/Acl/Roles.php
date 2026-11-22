<?php

namespace YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl;


class Roles 
{
    public const ROLE_GEST = 1;
    public const ROLE_ROOT = 2;
    public const ROLE_USER = 3;
    public const ROLE_ADMIN_SPACE = 4;
    public const ROLE_MEMBER = 5;

    /**
     * Les rôles Invité et Utilisateur sont reçus uniquement par les liens système.
     * @return list<int>
     */
    public static function getImplicitIds(): array
    {
        return [self::ROLE_GEST, self::ROLE_USER];
    }

    public static function isImplicit(int $roleId): bool
    {
        return in_array($roleId, self::getImplicitIds(), true);
    }
}

