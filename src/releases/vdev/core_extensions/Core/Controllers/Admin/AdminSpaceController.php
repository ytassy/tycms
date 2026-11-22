<?php

namespace YTassy\Tycms\CoreExts\Core\Controllers\Admin;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseController;
use YTassy\Tycms\CoreExts\Core\WF\TraitWF;
use YTassy\Tycms\CoreExts\Security\ExtensionApi\AnnotationsController\SecurityDoNotCheckPatterns;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Response\ApiResponse;
use YTassy\Tycms\Routing\Annotation\Doc\Controller;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerMethod;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerParam1;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerParam2;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerParam3;
use YTassy\Tycms\Routing\Annotation\Doc\VarType;
use YTassy\Tycms\Routing\Annotation\NoTransaction;

#[Controller(name: 'administrator_api_spaces_', isApi: true, path: 'administrator/api/spaces')]
class AdminSpaceController extends BaseController
{
    use TraitWF;

    #[ControllerMethod(name: 'read', path: '')]
    public function read(): ApiResponse
    {
        return new ApiResponse(['spaces' => $this->wfSpace->getSpaces()]);
    }

    #[ControllerMethod(name: 'form', path: 'form')]
    #[ControllerParam1(name: 'spaceId', required: false, varType: VarType::INT)]
    public function form(?int $spaceId = null): ApiResponse
    {
        return new ApiResponse($this->wfSpace->getSpaceEditorForm($spaceId));
    }

    #[SecurityDoNotCheckPatterns]
    #[ControllerMethod(name: 'create', path: 'create', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'data', required: true, varType: VarType::JSON, schemaFile: __DIR__ . '/Schemas/SpaceCreate.php')]
    public function create(object $data): ApiResponse
    {
        return new ApiResponse(['space' => $this->wfSpace->createSpace($data)]);
    }

    #[SecurityDoNotCheckPatterns]
    #[ControllerMethod(name: 'update', path: 'update', methods: [RequestMethod::POST])]
    #[NoTransaction]
    #[ControllerParam1(name: 'spaceId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'data', required: true, varType: VarType::JSON, schemaFile: __DIR__ . '/Schemas/SpaceUpdate.php')]
    public function update(int $spaceId, object $data): ApiResponse
    {
        return new ApiResponse(['space' => $this->wfSpace->updateSpace($spaceId, $data)]);
    }

    #[ControllerMethod(name: 'languages', path: 'languages')]
    #[ControllerParam1(name: 'spaceId', required: true, varType: VarType::INT)]
    public function languages(int $spaceId): ApiResponse
    {
        return new ApiResponse($this->wfSpace->getSpaceLanguages($spaceId));
    }

    #[ControllerMethod(name: 'languages_save', path: 'languages/save', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'spaceId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'languages', required: true, varType: VarType::JSON,
        schemaFile: __DIR__ . '/Schemas/SpaceLanguages.php')]
    public function languagesSave(int $spaceId, array $languages): ApiResponse
    {
        return new ApiResponse($this->wfSpace->saveSpaceLanguages($spaceId, $languages));
    }

    #[ControllerMethod(name: 'navigation', path: 'navigation')]
    #[ControllerParam1(name: 'spaceId', required: true, varType: VarType::INT)]
    public function navigation(int $spaceId): ApiResponse
    {
        return new ApiResponse($this->wfSpace->getSpaceNavigation($spaceId));
    }

    #[ControllerMethod(name: 'navigation_save', path: 'navigation/save', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'spaceId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'navigation', required: true, varType: VarType::JSON,
        schemaFile: __DIR__ . '/Schemas/SpaceNavigation.php')]
    public function navigationSave(int $spaceId, object $navigation): ApiResponse
    {
        return new ApiResponse($this->wfSpace->saveSpaceNavigation($spaceId, $navigation));
    }

    #[ControllerMethod(name: 'mail_test', path: 'mail/test', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'spaceId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'recipient', required: true, varType: VarType::STRING)]
    #[ControllerParam3(name: 'settings', required: true, schemaFile: __DIR__ . '/Schemas/SpaceMailTest.php')]
    #[NoTransaction]
    public function mail_test(int $spaceId, string $recipient, object $settings): ApiResponse
    {
        return new ApiResponse(['sent' => $this->wfSpace->sendTestMail($spaceId, $recipient, $settings)]);
    }

    #[ControllerMethod(name: 'delete', path: 'delete', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'spaceId', required: true, varType: VarType::INT)]
    public function delete(int $spaceId): ApiResponse
    {
        return new ApiResponse(['deleted' => $this->wfSpace->deleteSpace($spaceId)]);
    }
}
