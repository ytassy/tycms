<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Event;

use InvalidArgumentException;

/**
 * Déclaration d'un type d'événement métier.
 *
 * La définition vit dans le code de l'extension, comme un type de job ou un
 * type de composant de page. Elle est immuable et validée à la construction :
 * une définition incomplète doit échouer au démarrage, pas au moment où un
 * workflow l'émet.
 *
 * L'audience n'est pas décrite ici. Le workflow émetteur en décrit seulement
 * la source dans `EventAudience`, et `EventEmitter::emit()` la résout ; la
 * définition ne porte que le catalogue et les textes.
 */
final class EventDefinition
{
    /** Identifiant stable : 2 à 5 segments, namespace en premier. */
    public const ID_PATTERN = '/\A[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*){1,4}\z/';

    public const CATEGORY_USER = 'user';
    public const CATEGORY_TECHNICAL = 'technical';

    public const SEVERITY_INFO = 'info';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_ERROR = 'error';

    public const CATEGORIES = [self::CATEGORY_USER, self::CATEGORY_TECHNICAL];
    public const SEVERITIES = [self::SEVERITY_INFO, self::SEVERITY_WARNING, self::SEVERITY_ERROR];

    public const LABEL_MAX_LENGTH = 100;
    public const DESCRIPTION_MAX_LENGTH = 1000;
    public const TITLE_MAX_LENGTH = 255;
    public const BODY_MAX_LENGTH = 65535;

    /** Un canal par défaut reprend l'identifiant court d'un canal. */
    public const DEFAULT_CHANNEL_PATTERN = '/\A[a-z][a-z0-9_]{0,31}\z/';

    public readonly string $id;
    public readonly string $label;
    public readonly string $description;
    public readonly string $category;
    public readonly string $severity;
    public readonly bool $log;
    public readonly bool $notifiable;
    public readonly bool $notifyActor;
    public readonly ?string $title;
    public readonly ?string $bodySelf;
    public readonly ?string $bodyOthers;

    /** @var list<string> */
    public readonly array $defaultChannels;

    /** Callable qui retourne un chemin interne, ou null. */
    public readonly ?\Closure $link;

    /**
     * @param list<string> $defaultChannels
     * @param callable(Event): ?string|null $link
     */
    public function __construct(
        string $id,
        string $label,
        string $description,
        string $category = self::CATEGORY_USER,
        string $severity = self::SEVERITY_INFO,
        bool $log = true,
        bool $notifiable = false,
        bool $notifyActor = true,
        array $defaultChannels = [],
        ?string $title = null,
        ?string $bodySelf = null,
        ?string $bodyOthers = null,
        ?callable $link = null,
    ) {
        $this->assertIdentifier($id);

        $this->id = $id;
        $this->label = self::assertText($label, 'libellé', self::LABEL_MAX_LENGTH);
        $this->description = self::assertText($description, 'description', self::DESCRIPTION_MAX_LENGTH);

        if (!in_array($category, self::CATEGORIES, true)) {
            throw new InvalidArgumentException(sprintf(
                'Catégorie inconnue pour l’événement "%s" : "%s".',
                $id,
                $category,
            ));
        }
        if (!in_array($severity, self::SEVERITIES, true)) {
            throw new InvalidArgumentException(sprintf(
                'Sévérité inconnue pour l’événement "%s" : "%s".',
                $id,
                $severity,
            ));
        }

        $this->category = $category;
        $this->severity = $severity;
        $this->log = $log;
        $this->notifiable = $notifiable;
        $this->notifyActor = $notifyActor;

        // Une notification est toujours adossée à une ligne de journal.
        if ($notifiable && !$log) {
            throw new InvalidArgumentException(sprintf(
                'L’événement "%s" ne peut pas être notifiable sans écriture au journal.',
                $id,
            ));
        }

        $this->title = self::assertOptionalText($title, 'titre', self::TITLE_MAX_LENGTH);
        $this->bodySelf = self::assertOptionalText($bodySelf, 'corps self', self::BODY_MAX_LENGTH);
        $this->bodyOthers = self::assertOptionalText($bodyOthers, 'corps others', self::BODY_MAX_LENGTH);

        // Une définition notifiable doit porter tout ce qui produit la notification.
        if ($notifiable) {
            foreach ([
                'title' => $this->title,
                'bodySelf' => $this->bodySelf,
                'bodyOthers' => $this->bodyOthers,
            ] as $field => $value) {
                if ($value === null) {
                    throw new InvalidArgumentException(sprintf(
                        'L’événement "%s" est notifiable mais son champ "%s" est absent.',
                        $id,
                        $field,
                    ));
                }
            }

            if ($link === null) {
                throw new InvalidArgumentException(sprintf(
                    'L’événement "%s" est notifiable mais ne déclare aucun lien.',
                    $id,
                ));
            }
        }

        $this->link = $link === null ? null : \Closure::fromCallable($link);
        $this->defaultChannels = self::normaliseChannels($id, $defaultChannels);
    }

    private function assertIdentifier(string $id): void
    {
        if (strlen($id) > 100 || preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Identifiant d’événement invalide : "%s". Format attendu : 2 à 5 segments en minuscules.',
                $id,
            ));
        }
    }

    private static function assertText(string $value, string $field, int $maxLength): string
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException(sprintf('Le %s d’un événement est obligatoire.', $field));
        }
        if (mb_strlen($value) > $maxLength) {
            throw new InvalidArgumentException(sprintf(
                'Le %s d’un événement dépasse %d caractères.',
                $field,
                $maxLength,
            ));
        }

        return $value;
    }

    private static function assertOptionalText(?string $value, string $field, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        return self::assertText($value, $field, $maxLength);
    }

    /**
     * @param list<string> $channels
     * @return list<string>
     */
    private static function normaliseChannels(string $eventId, array $channels): array
    {
        if (!array_is_list($channels)) {
            throw new InvalidArgumentException(sprintf(
                'Les canaux par défaut de l’événement "%s" doivent former une liste.',
                $eventId,
            ));
        }

        $result = [];
        foreach ($channels as $channel) {
            if (!is_string($channel) || preg_match(self::DEFAULT_CHANNEL_PATTERN, $channel) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    'Canal par défaut invalide pour l’événement "%s".',
                    $eventId,
                ));
            }
            if (isset($result[$channel])) {
                throw new InvalidArgumentException(sprintf(
                    'Canal par défaut "%s" déclaré deux fois pour l’événement "%s".',
                    $channel,
                    $eventId,
                ));
            }

            $result[$channel] = true;
        }

        return array_keys($result);
    }
}
