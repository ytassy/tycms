<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\ExtensionApi\Event;

use YTassy\Tycms\CoreExts\Core\Event\EventListenerRegistry;

/**
 * Fournisseur d'écouteurs d'événements d'une extension.
 *
 * Les types écoutés sont vérifiés après l'enregistrement de toutes les
 * définitions : un écouteur ne peut pas viser un type qui n'existe pas.
 */
interface IEventListenerProvider
{
    public function coreOnRegisterEventListeners(EventListenerRegistry $registry): void;
}
