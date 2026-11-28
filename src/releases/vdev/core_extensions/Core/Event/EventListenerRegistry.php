<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Event;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\Exceptions\TechException;

/**
 * Catalogue des écouteurs d'événements.
 *
 * Les écouteurs sont indexés par type d'événement et triés par priorité
 * croissante. À priorité égale, l'ordre d'enregistrement est conservé : la
 * déclaration reste lisible sans dépendre d'un tri instable.
 */
final class EventListenerRegistry extends BaseHelper
{
    /** @var array<string, EventListenerDefinition> */
    private array $definitions = [];

    /** @var array<string, list<EventListenerDefinition>> */
    private array $byEventType = [];

    /** @var array<string, string> */
    private array $owners = [];

    /** Extension en cours de déclaration, pour nommer l'auteur d'un doublon. */
    private string $currentOwner = '';

    /**
     * Exécute la déclaration d'une extension en lui attribuant les écouteurs
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

    public function register(EventListenerDefinition $definition): void
    {
        $id = $definition->id;
        if (isset($this->definitions[$id])) {
            throw new TechException(sprintf(
                'Écouteur "%s" déjà enregistré par "%s" ; déclaration refusée pour "%s".',
                $id,
                $this->owners[$id] ?? 'inconnue',
                $this->currentOwner !== '' ? $this->currentOwner : 'inconnue',
            ));
        }

        $this->definitions[$id] = $definition;
        $this->owners[$id] = $this->currentOwner;

        foreach ($definition->eventTypes as $eventType) {
            $this->byEventType[$eventType][] = $definition;
        }
    }

    /**
     * Écouteurs d'un type, triés par priorité croissante puis par ordre
     * d'enregistrement.
     *
     * @return list<EventListenerDefinition>
     */
    public function forEvent(string $eventType): array
    {
        $listeners = $this->byEventType[$eventType] ?? [];

        // La liste est petite et l'indexation garde l'ordre d'arrivée : un tri
        // stable suffit, sans clé secondaire à maintenir.
        usort($listeners, static fn (EventListenerDefinition $a, EventListenerDefinition $b): int
            => $a->priority <=> $b->priority);

        return $listeners;
    }

    /**
     * Refuse un écouteur qui vise un type d'événement non déclaré.
     *
     * À appeler après l'enregistrement de toutes les définitions, une fois le
     * graphe complet connu.
     */
    public function assertEventTypesKnown(EventDefinitionRegistry $registry): void
    {
        $unknown = [];

        foreach ($this->definitions as $definition) {
            foreach ($definition->eventTypes as $eventType) {
                if (!$registry->has($eventType)) {
                    $unknown[sprintf('%s → %s', $definition->id, $eventType)] = true;
                }
            }
        }

        if ($unknown === []) {
            return;
        }

        throw new TechException(sprintf(
            'Écouteur(s) branché(s) sur un type d’événement inconnu : %s.',
            implode(', ', array_keys($unknown)),
        ));
    }

    public function get(string $id): ?EventListenerDefinition
    {
        return $this->definitions[$id] ?? null;
    }

    /** @return array<string, EventListenerDefinition> */
    public function all(): array
    {
        return $this->definitions;
    }
}
