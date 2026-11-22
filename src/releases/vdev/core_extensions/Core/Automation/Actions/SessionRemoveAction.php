<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\BaseAutomationAction;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;

/**
 * Efface une donnée de la session du navigateur.
 *
 * Retourne `true` si la clé existait, `false` sinon : effacer une clé absente
 * est un succès, ce qui rend l'action rejouable sans condition.
 */
final class SessionRemoveAction extends BaseAutomationAction
{
    public function execute(ActionContext $context, array $params): mixed
    {
        $key = ValueTools::assertString($params['key'] ?? null, $context->span);

        try {
            return $this->coreBrowserSessionPropertyHelper->remove(
                $context->spaceId,
                $key,
                $context,
            );
        } catch (\Throwable $exception) {
            throw $this->actionException($exception, $context);
        }
    }
}
