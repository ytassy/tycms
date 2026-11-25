<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\BaseAutomationAction;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;
use YTassy\Tycms\CoreExts\Core\WF\WFMedia;

/**
 * Enregistrement d'un fichier texte éditable (SCSS ou TXT).
 *
 * `version` doit correspondre à celle reçue à la lecture : si un humain a
 * modifié le fichier entre-temps, le helper refuse l'écriture plutôt que de
 * l'écraser silencieusement.
 */
final class SaveMediaFileAction extends BaseAutomationAction
{
    public function execute(ActionContext $context, array $params): mixed
    {
        $spaceId = ValueTools::assertInt($params['spaceId'] ?? null, $context->span);
        $path = ValueTools::assertString($params['path'] ?? null, $context->span);
        $content = ValueTools::assertString($params['content'] ?? '', $context->span);
        $version = ValueTools::assertString($params['version'] ?? null, $context->span);

        try {
            /** @var WFMedia $workflow */
            $workflow = $this->getSharedExtensionsClass(WFMedia::class);

            return ['version' => $workflow->saveEditableFile($spaceId, $path, $content, $version)];
        } catch (\Throwable $exception) {
            throw $this->actionException($exception, $context);
        }
    }
}
