<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Event;

use YTassy\Tycms\Application;
use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\Exceptions\TechException;

/**
 * Point d'émission des événements métier.
 *
 * Un workflow appelle `emit()` après ses écritures et avant le commit. Si un
 * écouteur critique échoue, l'exception remonte et la transaction métier est
 * annulée : le journal et les notifications sont aussi durables que le métier.
 */
final class EventEmitter extends BaseHelper
{
    private readonly EventDefinitionRegistry $definitions;

    private readonly EventDispatcher $dispatcher;

    public function __construct(Application|BaseApplicationItem $app)
    {
        parent::__construct($app);

        $definitions = $this->app->getOrAddSharedExtensionsClass(EventDefinitionRegistry::class);
        if (!$definitions instanceof EventDefinitionRegistry) {
            throw new TechException('Le registre des définitions d’événements est introuvable.');
        }

        $this->definitions = $definitions;

        $dispatcher = $this->app->getOrAddSharedExtensionsClass(EventDispatcher::class);
        if (!$dispatcher instanceof EventDispatcher) {
            throw new TechException('Le dispatcher d’événements est introuvable.');
        }

        $this->dispatcher = $dispatcher;
    }

    /**
     * Émet un événement déclaré.
     *
     * L'audience est déjà résolue par l'appelant : liste directe du métier ou
     * résultat d'`AclUserResolver`. Le listener de notification applique
     * ensuite les filtres de compte et les préférences.
     *
     * @param array<string, mixed> $data
     * @param list<int> $recipientUserIds
     */
    public function emit(
        string $type,
        int $spaceId,
        array $data = [],
        array $recipientUserIds = [],
        ?int $actorId = null,
        EventActorType $actorType = EventActorType::USER,
        ?string $subjectType = null,
        ?int $subjectId = null,
    ): Event {
        $definition = $this->definitions->get($type);
        if ($definition === null) {
            throw new TechException(sprintf('Type d’événement non déclaré : "%s".', $type));
        }

        $event = new Event(
            $definition,
            $spaceId,
            $data,
            $recipientUserIds,
            $actorId,
            $actorType,
            $subjectType,
            $subjectId,
        );

        // Le lien dépend de la définition et de la charge utile. Il est figé ici
        // car l'Application d'un job n'a pas de routes.
        if ($definition->link !== null) {
            $event->setUrlPath($this->resolveLink($definition->link, $event));
        }

        $this->dispatcher->dispatch($event, $this->dispatcher->nextDepth());

        return $event;
    }

    private function resolveLink(\Closure $link, Event $event): ?string
    {
        $path = $link($event);

        if ($path !== null && !is_string($path)) {
            throw new TechException(sprintf(
                'Le lien de l’événement "%s" doit retourner une chaîne ou null.',
                $event->type,
            ));
        }

        return $path;
    }
}
