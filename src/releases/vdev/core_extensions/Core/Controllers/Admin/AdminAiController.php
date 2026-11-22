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

#[Controller(name: 'administrator_api_ai_', isApi: true, path: 'administrator/api/ai')]
final class AdminAiController extends BaseController
{
    use TraitWF;

    #[ControllerMethod(name: 'configuration', path: 'configuration')]
    #[ControllerParam1(name: 'spaceId', required: true, varType: VarType::INT)]
    public function configuration(int $spaceId): ApiResponse
    {
        return new ApiResponse($this->wfAi->getConfiguration($spaceId));
    }

    #[ControllerMethod(name: 'provider_create', path: 'providers/create', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'spaceId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'data', required: true, varType: VarType::JSON, schemaFile: __DIR__ . '/Schemas/AiProviderData.php')]
    public function providerCreate(int $spaceId, object $data): ApiResponse
    {
        return new ApiResponse(['id' => $this->wfAi->createProvider($spaceId, $data)]);
    }

    #[ControllerMethod(name: 'provider_update', path: 'providers/update', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'providerId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'data', required: true, varType: VarType::JSON, schemaFile: __DIR__ . '/Schemas/AiProviderData.php')]
    public function providerUpdate(int $providerId, object $data): ApiResponse
    {
        $this->wfAi->updateProvider($providerId, $data);
        return new ApiResponse(['updated' => true]);
    }

    #[ControllerMethod(name: 'provider_delete', path: 'providers/delete', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'providerId', required: true, varType: VarType::INT)]
    public function providerDelete(int $providerId): ApiResponse
    {
        $this->wfAi->deleteProvider($providerId);
        return new ApiResponse(['deleted' => true]);
    }

    #[ControllerMethod(name: 'models_save', path: 'models/save', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'spaceId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'models', required: true, varType: VarType::JSON, schemaFile: __DIR__ . '/Schemas/AiModels.php')]
    public function modelsSave(int $spaceId, object $models): ApiResponse
    {
        $this->wfAi->saveModels($spaceId, $models);
        return new ApiResponse(['saved' => true]);
    }
}
