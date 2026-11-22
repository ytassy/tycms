<?php

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

#[Controller(name: 'administrator_api_sso_providers_', isApi: true, path: 'administrator/api/sso-providers')]
final class AdminSsoProviderController extends BaseController
{
    use TraitWF;

    #[ControllerMethod(name: 'read', path: '')]
    public function read(): ApiResponse
    {
        return new ApiResponse($this->wfSsoProvider->listForAdministration());
    }

    #[ControllerMethod(name: 'form', path: 'form')]
    #[ControllerParam1(name: 'providerId', required: false, varType: VarType::INT)]
    public function form(?int $providerId = null): ApiResponse
    {
        return new ApiResponse($this->wfSsoProvider->getEditorForm($providerId));
    }

    #[ControllerMethod(name: 'create', path: 'create', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'data', required: true, schemaFile: __DIR__ . '/Schemas/SsoProviderData.php')]
    public function create(object $data): ApiResponse
    {
        return new ApiResponse(['id' => $this->wfSsoProvider->create($data)]);
    }

    #[ControllerMethod(name: 'update', path: 'update', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'providerId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'data', required: true, schemaFile: __DIR__ . '/Schemas/SsoProviderData.php')]
    public function update(int $providerId, object $data): ApiResponse
    {
        return new ApiResponse(['id' => $this->wfSsoProvider->update($providerId, $data)]);
    }
}
