<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Notification;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\Exceptions\TechException;

/**
 * Catalogue des types de canaux de notification.
 *
 * Le registre porte les déclarations, pas les envois : le handler de livraison
 * reste unique et résout le canal à l'exécution à partir de l'identifiant
 * stocké. Un identifiant dupliqué est refusé au démarrage.
 */
final class NotificationChannelRegistry extends BaseHelper
{
    /** @var array<string, NotificationChannelDefinition> */
    private array $channels = [];

    /** @var array<string, string> */
    private array $owners = [];

    /** Extension en cours de déclaration, pour nommer l'auteur d'un doublon. */
    private string $currentOwner = '';

    /**
     * Exécute la déclaration d'une extension en lui attribuant les canaux
     * qu'elle enregistre. L'état précédent est restauré même en cas d'erreur.
     */
    public function withOwner(string $owner, callable $callback): void
    {
        $previous = $this->currentOwner;
        $this->currentOwner = $owner;

        try {
            $callback();
        } finally {
            $this->currentOwner = $previous;
        }
    }

    public function register(NotificationChannelDefinition $channel): void
    {
        $id = $channel->id;

        // Un canal intégré ne peut pas être revendiqué par une extension : sa
        // permission et son transport appartiennent à Core.
        if (in_array($id, NotificationChannelDefinition::BUILT_IN_IDS, true) && $this->currentOwner !== 'plg_core') {
            throw new TechException(sprintf(
                'Le canal intégré "%s" est réservé à Core ; déclaration refusée pour "%s".',
                $id,
                $this->currentOwner !== '' ? $this->currentOwner : 'inconnue',
            ));
        }

        if (isset($this->channels[$id])) {
            throw new TechException(sprintf(
                'Canal de notification "%s" déjà enregistré par "%s" ; déclaration refusée pour "%s".',
                $id,
                $this->owners[$id] ?? 'inconnue',
                $this->currentOwner !== '' ? $this->currentOwner : 'inconnue',
            ));
        }

        $this->channels[$id] = $channel;
        $this->owners[$id] = $this->currentOwner;
    }

    public function get(string $id): ?NotificationChannelDefinition
    {
        return $this->channels[$id] ?? null;
    }

    public function has(string $id): bool
    {
        return isset($this->channels[$id]);
    }

    public function ownerOf(string $id): ?string
    {
        $owner = $this->owners[$id] ?? null;

        return $owner === null || $owner === '' ? null : $owner;
    }

    /** @return array<string, NotificationChannelDefinition> */
    public function all(): array
    {
        return $this->channels;
    }
}
