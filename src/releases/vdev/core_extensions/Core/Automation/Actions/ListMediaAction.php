<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\BaseAutomationAction;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;
use YTassy\Tycms\CoreExts\Core\WF\WFMedia;

/**
 * Contenu d'un dossier de médias.
 *
 * Le chemin est relatif à la racine du Space ; une chaîne vide désigne cette
 * racine. La réponse contient le dossier demandé, son parent, ses entrées et
 * l'arborescence des sous-dossiers.
 */
final class ListMediaAction extends BaseAutomationAction
{
    public function execute(ActionContext $context, array $params): mixed
    {
        $spaceId = ValueTools::assertInt($params['spaceId'] ?? null, $context->span);
        $path = ValueTools::assertString($params['path'] ?? '', $context->span);

        try {
            /** @var WFMedia $workflow */
            $workflow = $this->getSharedExtensionsClass(WFMedia::class);

            return $workflow->listDirectory($spaceId, $path);
        } catch (\Throwable $exception) {
            throw $this->actionException($exception, $context);
        }
    }
}
