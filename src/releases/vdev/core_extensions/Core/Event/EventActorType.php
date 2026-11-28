<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Event;

/**
 * Nature de l'auteur d'un événement.
 *
 * `user` désigne un compte identifié ; `system` et `anonymous` n'ont pas de
 * compte. La colonne `event_log.actor_type` reprend exactement ces valeurs.
 */
enum EventActorType: string
{
    case USER = 'user';
    case SYSTEM = 'system';
    case ANONYMOUS = 'anonymous';
}
