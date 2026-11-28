<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\ExtensionApi\Event;

use YTassy\Tycms\CoreExts\Core\Event\EventDefinitionRegistry;

/**
 * Fournisseur de types d'événements d'une extension.
 *
 * L'enregistrement a lieu pendant `onPrepareExtensions`, avant toute route et
 * pour tout parcours d'initialisation, comme les autres registres de
 * capacités.
 */
interface IEventProvider
{
    public function coreOnRegisterEventTypes(EventDefinitionRegistry $registry): void;
}
