<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Event;

use Throwable;
use YTassy\Tycms\Application;
use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Event\IEventListener;
use YTassy\Tycms\Exceptions\TechException;

/**
 * Exécution synchrone des écouteurs d'un événement.
 *
 * Le dispatcher reste minimal : ordre par priorité, liste explicite des types,
 * profondeur bornée, aucune file. Un écouteur qui veut un effet différé enfile
 * lui-même un job.
 *
 * Les écouteurs sont critiques par défaut : leur exception remonte et annule la
 * transaction métier. Un écouteur déclaré `critical: false` est journalisé et
 * n'interrompt pas les suivants.
 */
final class EventDispatcher extends BaseHelper
{
    /**
     * Longueur maximale d'une chaîne d'événements émis par des écouteurs.
     *
     * La profondeur compte la chaîne, pas le nombre d'écouteurs : un écouteur
     * qui émet crée un enfant de profondeur +1.
     */
    public const MAX_DEPTH = 8;

    private readonly EventListenerRegistry $registry;

    /** @var array<class-string<IEventListener>, IEventListener> */
    private array $listeners = [];

    /** Profondeur de la chaîne en cours, 0 hors dispatch. */
    private int $currentDepth = 0;

    public function __construct(Application|BaseApplicationItem $app)
    {
        parent::__construct($app);

        $registry = $this->app->getOrAddSharedExtensionsClass(EventListenerRegistry::class);
        if (!$registry instanceof EventListenerRegistry) {
            throw new TechException('Le registre des écouteurs d’événements est introuvable.');
        }

        $this->registry = $registry;
    }

    /** Profondeur que prendrait un événement émis maintenant. */
    public function nextDepth(): int
    {
        return $this->currentDepth + 1;
    }

    /** Profondeur de la chaîne en cours, 0 hors dispatch. */
    public function currentDepth(): int
    {
        return $this->currentDepth;
    }

    /**
     * Exécute les écouteurs du type de l'événement.
     *
     * @param int|null $depth Profondeur de la chaîne ; 1 pour une émission métier.
     */
    public function dispatch(Event $event, ?int $depth = null): void
    {
        $depth ??= 1;

        // Au-delà de la borne, l'émission enfant est refusée : on journalise et
        // on n'exécute rien, sans annuler la transaction déjà en cours.
        if ($depth > self::MAX_DEPTH) {
            $this->logWarning(
                'Profondeur maximale d’événements atteinte ; émission enfant ignorée.',
                ['space_id' => $event->spaceId, 'event_type' => $event->type],
                'EVENT_DEPTH_EXCEEDED',
                'event',
            );

            return;
        }

        $previousDepth = $this->currentDepth;
        $this->currentDepth = $depth;

        try {
            foreach ($this->registry->forEvent($event->type) as $definition) {
                $this->execute($definition, $event);
            }
        } finally {
            // Restaurer la profondeur précédente, même si un écouteur critique
            // a fait remonter son exception.
            $this->currentDepth = $previousDepth;
        }
    }

    private function execute(EventListenerDefinition $definition, Event $event): void
    {
        try {
            $this->listener($definition->listenerClass)->handle($event);
        } catch (Throwable $exception) {
            if ($definition->critical) {
                // Le runner journalise déjà l'exception qui annule le métier ;
                // ne pas dupliquer la cause ici.
                throw $exception;
            }

            $this->logError(
                'Un écouteur d’événement non critique a échoué ; la chaîne continue.',
                [
                    'space_id' => $event->spaceId,
                    'event_type' => $event->type,
                    'listener' => $definition->id,
                ],
                'EVENT_LISTENER_FAILED',
                'event',
            );
            $this->logException($exception);
        }
    }

    /**
     * Instancie l'écouteur une seule fois par dispatch. Le constructeur reçoit
     * l'Application, comme un handler de job.
     *
     * @param class-string<IEventListener> $listenerClass
     */
    private function listener(string $listenerClass): IEventListener
    {
        if (!isset($this->listeners[$listenerClass])) {
            $this->listeners[$listenerClass] = new $listenerClass($this->app);
        }

        return $this->listeners[$listenerClass];
    }
}
