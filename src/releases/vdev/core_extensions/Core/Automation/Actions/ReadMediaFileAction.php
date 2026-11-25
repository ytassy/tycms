<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\BaseAutomationAction;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;
use YTassy\Tycms\CoreExts\Core\WF\WFMedia;

/**
 * Lecture d'un fichier texte éditable (SCSS ou TXT).
 *
 * La réponse contient une `version` (hash du contenu). Elle doit être renvoyée
 * telle quelle à l'enregistrement : c'est le contrôle qui empêche d'écraser
 * une modification faite entre-temps par un humain.
 */
final class ReadMediaFileAction extends BaseAutomationAction
{
    public function execute(ActionContext $context, array $params): mixed
    {
        $spaceId = ValueTools::assertInt($params['spaceId'] ?? null, $context->span);
        $path = ValueTools::assertString($params['path'] ?? null, $context->span);

        try {
            /** @var WFMedia $workflow */
            $workflow = $this->getSharedExtensionsClass(WFMedia::class);

            return $workflow->readEditableFile($spaceId, $path);
        } catch (\Throwable $exception) {
            throw $this->actionException($exception, $context);
        }
    }
}
