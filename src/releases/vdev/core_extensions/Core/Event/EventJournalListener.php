<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Event;

use Throwable;
use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Event\IEventListener;
use YTassy\Tycms\CoreExts\Core\Helper\TraitAllHelpers;

/**
 * Écrit chaque occurrence dans `event_log`.
 *
 * L’écouteur est critique : la ligne de journal appartient à la transaction
 * métier. Une erreur d’écriture remonte donc à l’appelant et annule le métier,
 * au lieu de laisser une action réussie sans trace.
 *
 * Il affecte `Event::$logId`, que le listener de fan-out utilisera pour
 * adosser les notifications à la ligne de journal.
 */
final class EventJournalListener extends BaseApplicationItem implements IEventListener
{
    use TraitAllHelpers;

    public function handle(Event $event): void
    {
        // Un événement `log = false` reste dispatché aux autres écouteurs : il
        // n’écrit simplement rien au journal.
        if (!$event->definition->log) {
            return;
        }

        try {
            $event->logId = $this->coreEventLogHelper->write($event);
        } catch (Throwable $exception) {
            // Le runner capture déjà l’exception qui annule le métier ; on
            // ajoute seulement le contexte métier et le code stable.
            $this->logError(
                'Écriture du journal d’événements impossible.',
                ['space_id' => $event->spaceId, 'event_type' => $event->type],
                'EVENT_LOG_WRITE_FAILED',
                'event',
            );

            throw $exception;
        }
    }
}
