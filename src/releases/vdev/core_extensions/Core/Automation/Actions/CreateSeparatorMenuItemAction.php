<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;

/**
 * Séparateur visuel entre deux groupes d'entrées.
 *
 * N'a pas de destination et ne sert pas de parent ; aucun paramètre propre au type.
 */
final class CreateSeparatorMenuItemAction extends AbstractCreateMenuItemAction
{
    protected function extension(): string
    {
        return 'plg_core';
    }

    protected function type(): string
    {
        return 'separator';
    }

    protected function typeParams(ActionContext $context, array $params): array
    {
        return [];
    }
}
