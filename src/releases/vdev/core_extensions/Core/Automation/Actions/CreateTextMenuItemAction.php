<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;

/**
 * Entrée de texte sans destination de navigation.
 *
 * Sert de libellé dans un sous-menu ; aucun paramètre propre au type.
 */
final class CreateTextMenuItemAction extends AbstractCreateMenuItemAction
{
    protected function extension(): string
    {
        return 'plg_core';
    }

    protected function type(): string
    {
        return 'text';
    }

    protected function typeParams(ActionContext $context, array $params): array
    {
        return [];
    }
}
