<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\BaseAutomationAction;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;
use YTassy\Tycms\CoreExts\Core\WF\WFMedia;

/**
 * Création d'un dossier dans les médias d'un Space.
 *
 * Le chemin est relatif à la racine du Space ; une chaîne vide désigne cette
 * racine. Le helper refuse un nom invalide ou un dossier parent introuvable.
 */
final class CreateMediaDirectoryAction extends BaseAutomationAction
{
    public function execute(ActionContext $context, array $params): mixed
    {
        $spaceId = ValueTools::assertInt($params['spaceId'] ?? null, $context->span);
        $path = ValueTools::assertString($params['path'] ?? '', $context->span);
        $name = ValueTools::assertString($params['name'] ?? null, $context->span);

        try {
            /** @var WFMedia $workflow */
            $workflow = $this->getSharedExtensionsClass(WFMedia::class);

            return ['created' => $workflow->createDirectory($spaceId, $path, $name)];
        } catch (\Throwable $exception) {
            throw $this->actionException($exception, $context);
        }
    }
}
