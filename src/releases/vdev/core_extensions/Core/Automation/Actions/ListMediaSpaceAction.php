<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\BaseAutomationAction;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;
use YTassy\Tycms\CoreExts\Core\WF\WFMedia;

/**
 * Catalogue des médias d'un Space.
 *
 * Indique les extensions autorisées, celles réellement éditables (SCSS et TXT)
 * et les tailles maximales. À lire avant toute écriture : un agent qui tente
 * d'écrire un autre format se verra refuser par le helper.
 */
final class ListMediaSpaceAction extends BaseAutomationAction
{
    public function execute(ActionContext $context, array $params): mixed
    {
        try {
            /** @var WFMedia $workflow */
            $workflow = $this->getSharedExtensionsClass(WFMedia::class);

            return $workflow->getMediaDirectory();
        } catch (\Throwable $exception) {
            throw $this->actionException($exception, $context);
        }
    }
}
