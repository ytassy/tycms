<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\BaseAutomationAction;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;

/**
 * Écrit une donnée dans la session du navigateur : la dernière valeur écrite.
 *
 * Le scénario doit s'exécuter dans sa propre requête HTTP. Un run lancé après
 * la réponse (clic sur un bouton de page) est refusé par la session elle-même,
 * qui ne les accepte plus à ce stade.
 */
final class SessionWriteAction extends BaseAutomationAction
{
    public function execute(ActionContext $context, array $params): mixed
    {
        $key = ValueTools::assertString($params['key'] ?? null, $context->span);
        $value = $params['value'] ?? null;

        try {
            $this->coreBrowserSessionPropertyHelper->write(
                $context->spaceId,
                $key,
                $value,
                $context,
            );
        } catch (\Throwable $exception) {
            throw $this->actionException($exception, $context);
        }

        // Le succès est signalé par `true` : le contenu reste hors du journal
        // des étapes, qui n'a pas à conserver de données de session.
        return true;
    }
}
