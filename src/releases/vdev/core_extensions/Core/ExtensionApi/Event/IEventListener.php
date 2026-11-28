<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\ExtensionApi\Event;

use YTassy\Tycms\CoreExts\Core\Event\Event;

/**
 * Écouteur d'un ou plusieurs types d'événements.
 *
 * La classe est instanciée par le dispatcher avec l'`Application` dans son
 * constructeur, comme un handler de job. Elle étend donc en pratique
 * `BaseApplicationItem` pour disposer des helpers partagés.
 *
 * Un écouteur critique s'exécute dans la transaction du métier : une exception
 * remonte et annule l'opération. Un écouteur déclaré `critical: false` voit son
 * erreur journalisée sans interrompre la chaîne.
 */
interface IEventListener
{
    public function handle(Event $event): void;
}
