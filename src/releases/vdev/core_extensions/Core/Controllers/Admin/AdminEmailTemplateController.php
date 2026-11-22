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

#[Controller(name: 'administrator_api_email_templates_', isApi: true, path: 'administrator/api/email-templates')]
#[SecurityDoNotCheckPatterns]
final class AdminEmailTemplateController extends BaseController
{
    use TraitWF;

    #[ControllerMethod(name: 'spaces', path: 'spaces')]
    public function spaces(): ApiResponse
    {
        return $this->privateResponse($this->wfEmailTemplate->spaces());
    }

    #[ControllerMethod(name: 'entries', path: '')]
    #[ControllerParam1(name: 'spaceId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'locale', required: true, varType: VarType::STRING)]
    public function entries(int $spaceId, string $locale): ApiResponse
    {
        return $this->privateResponse($this->wfEmailTemplate->entries($spaceId, $locale));
    }

    #[ControllerMethod(name: 'detail', path: 'detail')]
    #[ControllerParam1(name: 'spaceId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'locale', required: true, varType: VarType::STRING)]
    #[ControllerParam3(name: 'key', required: true, varType: VarType::STRING)]
    public function detail(int $spaceId, string $locale, string $key): ApiResponse
    {
        return $this->privateResponse($this->wfEmailTemplate->describe($spaceId, $locale, $key));
    }

    #[ControllerMethod(name: 'save', path: 'save', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'spaceId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'data', required: true, schemaFile: __DIR__ . '/Schemas/EmailTemplateWrite.php')]
    public function save(int $spaceId, object $data): ApiResponse
    {
        $this->wfEmailTemplate->save($spaceId, $data);
        return new ApiResponse(['saved' => true]);
    }

    #[ControllerMethod(name: 'restore', path: 'restore', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'spaceId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'data', required: true, schemaFile: __DIR__ . '/Schemas/EmailTemplateRestore.php')]
    public function restore(int $spaceId, object $data): ApiResponse
    {
        $this->wfEmailTemplate->restore($spaceId, $data);
        return new ApiResponse(['restored' => true]);
    }

    #[ControllerMethod(name: 'preview', path: 'preview', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'spaceId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'data', required: true, schemaFile: __DIR__ . '/Schemas/EmailTemplateWrite.php')]
    public function preview(int $spaceId, object $data): ApiResponse
    {
        return $this->privateResponse($this->wfEmailTemplate->preview($spaceId, $data));
    }

    private function privateResponse(array $data): ApiResponse
    {
        $response = new ApiResponse($data);
        return $response;
    }
}
