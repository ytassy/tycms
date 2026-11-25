<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\BaseAutomationAction;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;
use YTassy\Tycms\CoreExts\Core\WF\WFMedia;

/**
 * Création d'un fichier texte éditable dans les médias.
 *
 * Seules les extensions éditables (.scss et .txt) sont acceptées ; les autres
 * formats demanderaient un envoi binaire, hors du langage de scénario.
 */
final class CreateMediaFileAction extends BaseAutomationAction
{
    public function execute(ActionContext $context, array $params): mixed
    {
        $spaceId = ValueTools::assertInt($params['spaceId'] ?? null, $context->span);
        $path = ValueTools::assertString($params['path'] ?? '', $context->span);
        $name = ValueTools::assertString($params['name'] ?? null, $context->span);
        $content = ValueTools::assertString($params['content'] ?? '', $context->span);

        try {
            /** @var WFMedia $workflow */
            $workflow = $this->getSharedExtensionsClass(WFMedia::class);

            return ['path' => $workflow->createEditableFile($spaceId, $path, $name, $content)];
        } catch (\Throwable $exception) {
            throw $this->actionException($exception, $context);
        }
    }
}
