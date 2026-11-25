<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\BaseAutomationAction;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;
use YTassy\Tycms\CoreExts\Core\WF\WFPage;

/**
 * Versions conservées d'une page.
 *
 * L'agent choisit une version à partir de cette liste, puis demande son
 * contenu par `core::readPageVersion`. La liste ne transporte pas les
 * documents : seules les métadonnées et les dates sont renvoyées.
 */
final class ListPageHistoryAction extends BaseAutomationAction
{
    public function execute(ActionContext $context, array $params): mixed
    {
        $pageId = ValueTools::assertInt($params['pageId'] ?? null, $context->span);

        try {
            /** @var WFPage $workflow */
            $workflow = $this->getSharedExtensionsClass(WFPage::class);

            return $workflow->history($pageId);
        } catch (\Throwable $exception) {
            throw $this->actionException($exception, $context);
        }
    }
}
