<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Controllers\Admin;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseController;
use YTassy\Tycms\CoreExts\Core\WF\TraitWF;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Response\ApiResponse;
use YTassy\Tycms\Routing\Annotation\Doc\Controller;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerMethod;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerParam1;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerParam2;
use YTassy\Tycms\Routing\Annotation\Doc\VarType;
use YTassy\Tycms\Routing\Annotation\NoTransaction;

#[Controller(name: 'AdminFonts', isApi: true, path: '/administrator/api/fonts')]
class AdminFontController extends BaseController
{
    use TraitWF;

    #[ControllerMethod(name: 'library', path: '', methods: [RequestMethod::GET])]
    public function library(): ApiResponse
    {
        return new ApiResponse($this->wfFont->library());
    }

    #[ControllerMethod(name: 'install', path: 'install', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'spaceId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'id', required: true, varType: VarType::STRING)]
    #[NoTransaction]
    public function install(int $spaceId, string $id): ApiResponse
    {
        return new ApiResponse(['font' => $this->wfFont->install($spaceId, $id)]);
    }
    #[ControllerMethod(name: 'enabled', path: 'enabled', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'id', required: true, varType: VarType::STRING)]
    #[ControllerParam2(name: 'enabled', required: true, varType: VarType::BOOL)]
    #[NoTransaction]
    public function enabled(string $id, bool $enabled): ApiResponse
    {
        $this->wfFont->setEnabled($id, $enabled);
        return new ApiResponse(['updated' => true]);
    }

    #[ControllerMethod(name: 'refreshCatalog', path: 'catalog/refresh', methods: [RequestMethod::POST])]
    #[NoTransaction]
    public function refreshCatalog(): ApiResponse
    {
        return new ApiResponse(['refreshed' => true, 'catalog' => $this->wfFont->refreshCatalog()]);
    }

    #[ControllerMethod(name: 'delete', path: 'delete', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'id', required: true, varType: VarType::STRING)]
    #[NoTransaction]
    public function delete(string $id): ApiResponse
    {
        $this->wfFont->delete($id);
        return new ApiResponse(['deleted' => true]);
    }
}
