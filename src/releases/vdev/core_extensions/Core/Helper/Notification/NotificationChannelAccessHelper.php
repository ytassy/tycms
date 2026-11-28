<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Helper\Notification;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\CoreExts\Core\Notification\NotificationChannelDefinition;

/**
 * Contrôle d'accès aux types de canaux de notification.
 *
 * La permission d'un canal est vérifiée **quand l'utilisateur configure ses
 * préférences ou une connexion**, jamais à l'émission d'un événement ni à la
 * livraison. Un réglage déjà enregistré reste donc actif si le droit est retiré ;
 * l'ouverture des préférences purge ensuite les canaux devenus interdits.
 *
 * Le droit se lit sur le **Space principal du compte** (`users.space_id`), avec
 * l'héritage normal des Spaces. Les préférences et les connexions sont
 * personnelles : elles ne sont pas rattachées au Space d'un événement.
 */
final class NotificationChannelAccessHelper extends BaseHelper
{
    /** @var array<int, int> Space principal déjà résolu, 0 quand le compte est introuvable. */
    private array $principalSpaces = [];

    /** Un canal sans permission est ouvert à tous les comptes. */
    public function isAllowedForUser(int $userId, NotificationChannelDefinition $channel): bool
    {
        if ($userId <= 0) {
            return false;
        }

        if ($channel->isOpenToEveryone()) {
            return true;
        }

        $spaceId = $this->principalSpaceId($userId);
        if ($spaceId === null) {
            return false;
        }

        return $this->coreAclHelper->userHasGlobalPermission(
            $userId,
            (string) $channel->permission,
            $spaceId,
        );
    }

    /**
     * Identifiants des canaux autorisés pour un compte.
     *
     * Sert à construire l'écran de préférences et à purger les canaux devenus
     * interdits, sans répéter la lecture du compte.
     *
     * @param iterable<NotificationChannelDefinition> $channels
     * @return list<string>
     */
    public function allowedChannelIdsForUser(int $userId, iterable $channels): array
    {
        $allowed = [];

        foreach ($channels as $channel) {
            if ($this->isAllowedForUser($userId, $channel)) {
                $allowed[] = $channel->id;
            }
        }

        return $allowed;
    }

    private function principalSpaceId(int $userId): ?int
    {
        if (!array_key_exists($userId, $this->principalSpaces)) {
            $user = $this->coreUserHelper->getUser($userId);
            $this->principalSpaces[$userId] = $user === null ? 0 : (int) $user->space_id;
        }

        $spaceId = $this->principalSpaces[$userId];

        return $spaceId > 0 ? $spaceId : null;
    }
}
