<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Annotation;

use Attribute;

/**
 * Permission requise pour exécuter (et publier) l'action.
 *
 * Répétable : chaque occurrence ajoute une permission. Le contrôle s'effectue
 * sur le Space réel, comme pour les actions enregistrées impérativement.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class ActionPermission
{
    public function __construct(public string $permission)
    {
    }
}