<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\BaseAutomationAction;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;
use YTassy\Tycms\CoreExts\Core\WF\WFMenu;

/**
 * Création d'une entrée de menu, par type déclaré.
 *
 * Chaque type exposé par une extension possède son action nommée
 * (`aiMcpAction` dans `MenuItemDef`) : l'IA n'assemble donc jamais un couple
 * `extension`/`type`/`params` à l'aveugle. Cette classe porte le tronc commun
 * (lecture des champs, appel du WorkFlow) ; chaque type fixe son identifiant et
 * ses paramètres propres.
 */
abstract class AbstractCreateMenuItemAction extends BaseAutomationAction
{
    /** Extension propriétaire du type, par exemple `plg_core`. */
    abstract protected function extension(): string;

    /** Type de l'entrée, par exemple `page`. */
    abstract protected function type(): string;

    /**
     * Paramètres propres au type, à partir des valeurs du langage.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    abstract protected function typeParams(ActionContext $context, array $params): array;

    public function execute(ActionContext $context, array $params): mixed
    {
        $menuId = ValueTools::assertInt($params['menuId'] ?? null, $context->span);
        $title = ValueTools::assertString($params['title'] ?? null, $context->span);
        $parentId = isset($params['parentId']) ? ValueTools::assertInt($params['parentId'], $context->span) : null;
        $alias = ValueTools::assertString($params['alias'] ?? '', $context->span);

        try {
            /** @var WFMenu $workflow */
            $workflow = $this->getSharedExtensionsClass(WFMenu::class);

            $item = $workflow->createMenuItem($menuId, (object) [
                'title' => $title,
                'alias' => $alias,
                'parent_id' => $parentId,
                'params' => (object) $this->typeParams($context, $params),
                // `definitionKey` active la validation du WorkFlow : il fixe le
                // couple extension/type et fait contrôler les paramètres du
                // type. Sans lui, une page inexistante serait acceptée.
                'definitionKey' => $this->extension() . '__' . $this->type(),
                'status' => 1,
                'display_in_menu' => true,
                'meta_robots' => 'index, follow',
            ]);

            return ListMenusAction::item($item);
        } catch (\Throwable $exception) {
            throw $this->actionException($exception, $context);
        }
    }
}
