<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Event;

use InvalidArgumentException;
use ReflectionClass;
use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Event\IEventListener;

/**
 * Déclaration d'un écouteur d'événements.
 *
 * L'écouteur est désigné par sa classe. Le dispatcher l'instancie avec
 * l'`Application`, comme un handler de job : la classe doit donc étendre
 * `BaseApplicationItem` et implémenter `IEventListener`.
 */
final class EventListenerDefinition
{
    /** Identifiant stable : 2 à 5 segments, namespace en premier. */
    public const ID_PATTERN = '/\A[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*){1,4}\z/';

    public const PRIORITY_MIN = 0;
    public const PRIORITY_MAX = 1000;

    public readonly string $id;

    /** @var list<string> */
    public readonly array $eventTypes;

    /** @var class-string<IEventListener> */
    public readonly string $listenerClass;

    public readonly int $priority;

    public readonly bool $critical;

    /**
     * @param list<string> $eventTypes
     * @param class-string<IEventListener> $listenerClass
     */
    public function __construct(
        string $id,
        array $eventTypes,
        string $listenerClass,
        int $priority = 50,
        bool $critical = true,
    ) {
        $this->assertIdentifier($id);

        $this->id = $id;
        $this->eventTypes = self::normaliseEventTypes($id, $eventTypes);
        $this->listenerClass = self::assertListenerClass($id, $listenerClass);

        if ($priority < self::PRIORITY_MIN || $priority > self::PRIORITY_MAX) {
            throw new InvalidArgumentException(sprintf(
                'Priorité hors limites pour l’écouteur "%s" (%d-%d).',
                $id,
                self::PRIORITY_MIN,
                self::PRIORITY_MAX,
            ));
        }

        $this->priority = $priority;
        $this->critical = $critical;
    }

    private function assertIdentifier(string $id): void
    {
        if (strlen($id) > 100 || preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Identifiant d’écouteur invalide : "%s". Format attendu : 2 à 5 segments en minuscules.',
                $id,
            ));
        }
    }

    /**
     * @param list<string> $eventTypes
     * @return list<string>
     */
    private static function normaliseEventTypes(string $listenerId, array $eventTypes): array
    {
        if (!array_is_list($eventTypes) || $eventTypes === []) {
            throw new InvalidArgumentException(sprintf(
                'L’écouteur "%s" doit déclarer au moins un type d’événement.',
                $listenerId,
            ));
        }

        $result = [];
        foreach ($eventTypes as $eventType) {
            if (!is_string($eventType) || preg_match(EventDefinition::ID_PATTERN, $eventType) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    'Type d’événement invalide pour l’écouteur "%s".',
                    $listenerId,
                ));
            }
            if (isset($result[$eventType])) {
                throw new InvalidArgumentException(sprintf(
                    'L’écouteur "%s" déclare deux fois le type "%s".',
                    $listenerId,
                    $eventType,
                ));
            }

            $result[$eventType] = true;
        }

        return array_keys($result);
    }

    /**
     * @param class-string<IEventListener> $listenerClass
     * @return class-string<IEventListener>
     */
    private static function assertListenerClass(string $listenerId, string $listenerClass): string
    {
        if (!class_exists($listenerClass)) {
            throw new InvalidArgumentException(sprintf(
                'La classe de l’écouteur "%s" est introuvable : "%s".',
                $listenerId,
                $listenerClass,
            ));
        }

        $reflection = new ReflectionClass($listenerClass);

        if (!$reflection->implementsInterface(IEventListener::class)) {
            throw new InvalidArgumentException(sprintf(
                'La classe "%s" de l’écouteur "%s" doit implémenter %s.',
                $listenerClass,
                $listenerId,
                IEventListener::class,
            ));
        }

        // Le dispatcher passe l'Application au constructeur, comme pour un job.
        if (!$reflection->isSubclassOf(BaseApplicationItem::class)) {
            throw new InvalidArgumentException(sprintf(
                'La classe "%s" de l’écouteur "%s" doit étendre %s.',
                $listenerClass,
                $listenerId,
                BaseApplicationItem::class,
            ));
        }

        if (!$reflection->isInstantiable()) {
            throw new InvalidArgumentException(sprintf(
                'La classe "%s" de l’écouteur "%s" n’est pas instanciable.',
                $listenerClass,
                $listenerId,
            ));
        }

        return $listenerClass;
    }
}
