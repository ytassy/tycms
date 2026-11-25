<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;

/**
 * Entrée de menu ouvrant une page.
 *
 * `pageId` doit désigner une page du même Space que le menu ; le WorkFlow
 * vérifie cette appartenance et refuse une page absente.
 */
final class CreatePageMenuItemAction extends AbstractCreateMenuItemAction
{
    protected function extension(): string
    {
        return 'plg_core';
    }

    protected function type(): string
    {
        return 'page';
    }

    protected function typeParams(ActionContext $context, array $params): array
    {
        return ['page_id' => ValueTools::assertInt($params['pageId'] ?? null, $context->span)];
    }
}
