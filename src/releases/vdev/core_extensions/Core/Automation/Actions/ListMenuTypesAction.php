<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\BaseAutomationAction;
use YTassy\Tycms\CoreExts\Core\Automation\Support\MenuTypeCatalog;
use YTassy\Tycms\CoreExts\Core\Helper\TraitCoreAdditionalHelpers;

/**
 * Types d'entrée de menu exposés à l'IA.
 *
 * Chaque type marqué `ai: true` par son extension devient une action de
 * création nommée. Le schéma des paramètres est dérivé du formulaire déclaré
 * par l'extension, pas recopié : un agent qui crée une entrée vers une page
 * sait donc qu'il doit fournir `page_id`, et rien d'autre.
 */
final class ListMenuTypesAction extends BaseAutomationAction
{
    // Le catalogue des types de menu vit dans les helpers additionnels.
    use TraitCoreAdditionalHelpers;

    public function execute(ActionContext $context, array $params): mixed
    {
        try {
            // Le registre est rempli par `onPrepareExtensions`, donc avant toute
            // route et pour tout parcours, y compris l'exécution MCP isolée.
            $types = MenuTypeCatalog::exposed($this->coreMenuRouteHelper->getAllMenusDef());

            return [
                'types' => $types,
                'count' => count($types),
            ];
        } catch (\Throwable $exception) {
            throw $this->actionException($exception, $context);
        }
    }
}
