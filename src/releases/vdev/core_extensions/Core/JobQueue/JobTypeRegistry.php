<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\JobQueue;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Job\JobType;
use YTassy\Tycms\Exceptions\TechException;

/**
 * Catalogue des types de jobs déclarés par les extensions.
 *
 * Un identifiant dupliqué est une erreur technique : deux extensions ne
 * peuvent pas revendiquer le même type, et le registre refuse la seconde
 * déclaration au démarrage plutôt que de choisir un handler implicitement.
 */
final class JobTypeRegistry extends BaseHelper
{
    /** @var array<string, JobType> */
    private array $types = [];

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

    public function register(JobType $type): void
    {
        if (isset($this->types[$type->id])) {
            throw new TechException(sprintf(
                'Type de job "%s" déjà enregistré par "%s" ; déclaration refusée pour "%s".',
                $type->id,
                $this->currentOwner !== '' ? $this->currentOwner : 'inconnue',
                $type->producer(),
            ));
        }

        $this->types[$type->id] = $type;
    }

    public function get(string $id): ?JobType
    {
        return $this->types[$id] ?? null;
    }

    /** @return array<string, JobType> */
    public function all(): array
    {
        return $this->types;
    }
}
