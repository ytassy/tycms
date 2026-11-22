<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Support;

/** Projection stable d'un utilisateur pour le langage de scénarios. */
final class UserPayload
{
    /** @param array<string, mixed> $user @return array<string, mixed> */
    public static function map(array $user): array
    {
        return [
            'id' => (int) ($user['id'] ?? 0),
            'spaceId' => (int) ($user['space_id'] ?? 0),
            'firstName' => (string) ($user['first_name'] ?? ''),
            'lastName' => (string) ($user['last_name'] ?? ''),
            'email' => (string) ($user['email'] ?? ''),
            'timezone' => (string) ($user['timezone'] ?? ''),
            'blocked' => (bool) ($user['blocked'] ?? false),
            'groups' => $user['groups'] ?? [],
            'roles' => $user['roles'] ?? [],
        ];
    }
}
