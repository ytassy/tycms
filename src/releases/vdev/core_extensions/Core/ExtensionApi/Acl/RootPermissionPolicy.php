<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl;

/** Invariants de stockage et de transmission de ROOT, indépendants du rôle système 2. */
final class RootPermissionPolicy
{
    /** @param list<string> $permissions */
    public static function allowsRole(array $permissions, int $spaceId, int $type, ?int $roleId = null): bool
    {
        if (!in_array(Permissions::ROOT, $permissions, true)) return true;

        // Les rôles reçus automatiquement ne doivent jamais porter ROOT.
        return $spaceId === 1 && $type === 0
            && !in_array($roleId, [Roles::ROLE_GEST, Roles::ROLE_USER, Roles::ROLE_MEMBER], true);
    }

    /** @param list<string> $permissions */
    public static function allowsAssignment(array $permissions, int $spaceId, int $groupId,
        bool $actorIsRoot, bool $automatic = false, bool $onObject = false): bool
    {
        if (!in_array(Permissions::ROOT, $permissions, true)) return true;

        return $actorIsRoot && !$automatic && !$onObject && $spaceId === 1
            && !UserGroups::isImplicit($groupId);
    }
}
