<?php

namespace YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl;


class UserGroups 
{
    public const GROUP_GEST = 1;
    public const GROUP_USERS = 2;
    public const GROUP_ROOT = 3;

    /**
     * Ces deux groupes sont sélectionnés par le système selon l'état de connexion.
     * @return list<int>
     */
    public static function getImplicitIds(): array
    {
        return [self::GROUP_GEST, self::GROUP_USERS];
    }

    public static function isImplicit(int $groupId): bool
    {
        return in_array($groupId, self::getImplicitIds(), true);
    }

    public static function getAllIds(){
        return [
            self::GROUP_GEST,
            self::GROUP_USERS,
            self::GROUP_ROOT,
        ];
    }

}

