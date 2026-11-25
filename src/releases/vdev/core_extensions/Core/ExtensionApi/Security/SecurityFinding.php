<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\ExtensionApi\Security;

/**
 * Constat d'une analyse de sécurité.
 *
 * Un constat décrit l'état observé d'un point de configuration ou de données,
 * avec la correction à appliquer quand il y en a une. Il n'est jamais persisté :
 * il est recalculé à chaque lecture du bilan, ce qui garantit qu'il reflète
 * l'état courant du site.
 *
 * La gravité est ordonnée : `CRITICAL` d'abord, puis `DANGER`, `INFO` et `OK`.
 * L'interface affiche les groupes dans l'ordre fourni et les constats triés par
 * gravité, sans avoir à interpréter leur texte.
 */
final readonly class SecurityFinding
{
    /** Un défaut qui expose directement le site ; à corriger en priorité. */
    public const CRITICAL = 'critical';

    /** Un défaut sérieux, sans exposition immédiate. */
    public const DANGER = 'danger';

    /** Un renseignement utile, sans défaut à corriger. */
    public const INFO = 'info';

    /** Le point est correctement configuré. */
    public const OK = 'ok';

    /** Ordre d'affichage, du plus grave au moins grave. */
    private const ORDER = [
        self::CRITICAL => 0,
        self::DANGER => 1,
        self::INFO => 2,
        self::OK => 3,
    ];

    /**
     * @param string $title Titre court du point analysé.
     * @param string $status Une des constantes de statut.
     * @param string $description Phrase lisible décrivant l'état observé.
     * @param string|null $fix Correction à appliquer, quand il y en a une.
     * @param string|null $location Fichier ou écran concerné par la correction.
     */
    public function __construct(
        public string $title,
        public string $status,
        public string $description,
        public ?string $fix = null,
        public ?string $location = null,
    ) {
        if (!isset(self::ORDER[$status])) {
            throw new \InvalidArgumentException('Statut de constat de sécurité inconnu : ' . $status);
        }
    }

    /** Rang de gravité, utilisé pour le tri. */
    public function rank(): int
    {
        return self::ORDER[$this->status];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'status' => $this->status,
            'description' => $this->description,
            'fix' => $this->fix,
            'location' => $this->location,
        ];
    }
}
