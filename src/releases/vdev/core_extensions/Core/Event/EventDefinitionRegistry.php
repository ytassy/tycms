<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Event;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\Exceptions\TechException;

/**
 * Catalogue des types d'événements déclarés par les extensions.
 *
 * Un identifiant dupliqué est une erreur technique : deux extensions ne
 * peuvent pas revendiquer le même type. Le registre refuse la seconde
 * déclaration au démarrage plutôt que de choisir implicitement.
 */
final class EventDefinitionRegistry extends BaseHelper
{
    /** @var array<string, EventDefinition> */
    private array $definitions = [];

    /** @var array<string, string> */
    private array $owners = [];

    /** Extension en cours de déclaration, pour nommer l'auteur d'un doublon. */
    private string $currentOwner = '';

    /**
     * Exécute la déclaration d'une extension en lui attribuant les types qu'elle
     * enregistre. L'état précédent est restauré même en cas d'erreur.
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

    public function register(EventDefinition $definition): void
    {
        $id = $definition->id;
        if (isset($this->definitions[$id])) {
            throw new TechException(sprintf(
                'Type d’événement "%s" déjà enregistré par "%s" ; déclaration refusée pour "%s".',
                $id,
                $this->owners[$id] ?? 'inconnue',
                $this->currentOwner !== '' ? $this->currentOwner : 'inconnue',
            ));
        }

        $this->definitions[$id] = $definition;
        $this->owners[$id] = $this->currentOwner;
    }

    public function get(string $id): ?EventDefinition
    {
        return $this->definitions[$id] ?? null;
    }

    public function has(string $id): bool
    {
        return isset($this->definitions[$id]);
    }

    public function ownerOf(string $id): ?string
    {
        $owner = $this->owners[$id] ?? null;

        return $owner === null || $owner === '' ? null : $owner;
    }

    /** @return array<string, EventDefinition> */
    public function all(): array
    {
        return $this->definitions;
    }
}
