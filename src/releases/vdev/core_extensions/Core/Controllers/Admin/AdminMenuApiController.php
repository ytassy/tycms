<?php

namespace YTassy\Tycms\CoreExts\Core\Controllers\Admin;

use YTassy\Tycms\CoreExts\Core\Helper\TraitCoreAdditionalHelpers;
use YTassy\Tycms\Routing\Annotation\Doc\Controller;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerMethod;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerParam1;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerParam2;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerParam3;
use YTassy\Tycms\Routing\Annotation\Doc\VarType;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseController;
use YTassy\Tycms\CoreExts\Core\WF\TraitWF;
use YTassy\Tycms\Response\ApiResponse;

#[Controller(name: "administrator_api_", isApi: true, path: "administrator/api/menus")]
class AdminMenuApiController extends BaseController
{
    use TraitWF;
    use TraitCoreAdditionalHelpers;


    #[ControllerMethod(name: "menu_read", path: "")]
    public function menu_read()
    {
        $directory = $this->wfMenu->getMenuDirectory();
        
        return new ApiResponse([
            'menus' => $this->wfMenu->getMenus(),
            'definitions' => $this->coreMenuRouteHelper->getAllMenusDef(),
            'spaces' => $directory['spaces'],
            'roles' => $directory['roles'],
            'pages' => $this->corePageHelper->getPagesForSpace()
        ]);
    }

    #[ControllerMethod(name: 'menu_form', path: 'form')]
    #[ControllerParam1(name: 'menuId', required: false, varType: VarType::INT)]
    public function menu_form(?int $menuId = null)
    {
        return new ApiResponse($this->wfMenu->getMenuEditorForm($menuId));
    }

    #[ControllerMethod(name: 'menu_create', path: 'create', methods: [RequestMethod::POST])]
    #[ControllerParam1(
        name: 'data',
        required: true,
        varType: VarType::JSON,
        schemaFile: __DIR__ . '/Schemas/MenuCreate.php',
    )]
    public function menu_create(object $data)
    {
        return new ApiResponse(['menu' => $this->wfMenu->createMenu($data)]);
    }

    #[ControllerMethod(name: 'menu_update', path: 'update', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'menuId', required: true, varType: VarType::INT)]
    #[ControllerParam2(
        name: 'data',
        required: true,
        varType: VarType::JSON,
        schemaFile: __DIR__ . '/Schemas/MenuUpdate.php',
    )]
    public function menu_update(int $menuId, object $data)
    {
        return new ApiResponse(['menu' => $this->wfMenu->updateMenu($menuId, $data)]);
    }

    #[ControllerMethod(name: 'menu_delete', path: 'delete', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'menuId', required: true, varType: VarType::INT)]
    public function menu_delete(int $menuId)
    {
        $this->wfMenu->deleteMenu($menuId);
        return new ApiResponse(['success' => true]);
    }

    #[ControllerMethod(name: "menu_move_up", path: "move-up", methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'menuItemId', required: true, varType: VarType::INT)]
    public function menu_move_up(int $menuItemId)
    {
        return new ApiResponse([
            'moved' => $this->wfMenu->moveMenuItemUp($menuItemId),
        ]);
    }

    #[ControllerMethod(name: "menu_move_down", path: "move-down", methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'menuItemId', required: true, varType: VarType::INT)]
    public function menu_move_down(int $menuItemId)
    {
        return new ApiResponse([
            'moved' => $this->wfMenu->moveMenuItemDown($menuItemId),
        ]);
    }

    #[ControllerMethod(name: "menu_item_status", path: "item/status", methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'menuItemId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'status', required: true, varType: VarType::STRING)]
    public function menu_item_status(int $menuItemId, string $status)
    {
        return new ApiResponse([
            'updated' => $this->wfMenu->changeMenuItemStatus($menuItemId, $status),
        ]);
    }

    #[ControllerMethod(name: "menu_item_delete", path: "item/delete", methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'menuItemId', required: true, varType: VarType::INT)]
    public function menu_item_delete(int $menuItemId)
    {
        return new ApiResponse([
            'deleted' => $this->wfMenu->deleteMenuItem($menuItemId),
        ]);
    }

    #[ControllerMethod(name: "menu_item_form", path: "item/form")]
    #[ControllerParam1(name: 'menuId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'menuItemId', required: false, varType: VarType::INT)]
    #[ControllerParam3(name: 'definitionKey', required: false, varType: VarType::STRING)]
    public function menu_item_form(int $menuId, ?int $menuItemId = null, ?string $definitionKey = null)
    {
        return new ApiResponse($this->wfMenu->getMenuItemEditorForm($menuId, $menuItemId, $definitionKey));
    }

    #[ControllerMethod(name: "menu_item_create", path: "item/create", methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'menuId', required: true, varType: VarType::INT)]
    #[ControllerParam2(
        name: 'data',
        required: true,
        varType: VarType::JSON,
        schemaFile: __DIR__ . '/Schemas/MenuItemCreate.php',
    )]
    public function menu_item_create(int $menuId, object $data)
    {
        return new ApiResponse([
            'item' => $this->wfMenu->createMenuItem($menuId, $data),
        ]);
    }

    #[ControllerMethod(name: "menu_item_update", path: "item/update", methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'menuItemId', required: true, varType: VarType::INT)]
    #[ControllerParam2(
        name: 'data',
        required: true,
        varType: VarType::JSON,
        schemaFile: __DIR__ . '/Schemas/MenuItemUpdate.php',
    )]
    public function menu_item_update(int $menuItemId, object $data)
    {
        return new ApiResponse([
            'item' => $this->wfMenu->updateMenuItem($menuItemId, $data),
        ]);
    }

}
