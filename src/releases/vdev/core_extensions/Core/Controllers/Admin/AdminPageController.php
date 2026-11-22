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
use YTassy\Tycms\Routing\Annotation\Doc\VarType;

#[Controller(name: 'administrator_api_pages_', isApi: true, path: 'administrator/api/pages')]
#[SecurityDoNotCheckPatterns]
class AdminPageController extends BaseController
{
    use TraitWF;

    #[ControllerMethod(name: 'directory', path: '')]
    public function directory(): ApiResponse
    {
        return new ApiResponse($this->wfPage->directory());
    }

    #[ControllerMethod(name: 'link_menu_items', path: 'link-menu-items')]
    #[ControllerParam1(name: 'spaceId', required: true, varType: VarType::INT)]
    public function link_menu_items(int $spaceId): ApiResponse
    {
        $response = new ApiResponse($this->wfPage->linkMenuItems($spaceId));
        return $response;
    }

    #[ControllerMethod(name: 'editor_context', path: 'editor-context')]
    #[ControllerParam1(name: 'spaceId', required: true, varType: VarType::INT)]
    public function editor_context(int $spaceId): ApiResponse
    {
        return new ApiResponse($this->wfPage->editorContext($spaceId));
    }

    #[ControllerMethod(name: 'canvas', path: 'canvas', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'data', required: true, varType: VarType::JSON)]
    public function canvas(object $data): ApiResponse
    {
        return new ApiResponse($this->wfPage->canvas($data));
    }

    #[ControllerMethod(name: 'read', path: 'read')]
    #[ControllerParam1(name: 'pageId', required: true, varType: VarType::INT)]
    public function read(int $pageId): ApiResponse
    {
        return new ApiResponse($this->wfPage->read($pageId));
    }

    #[ControllerMethod(name: 'create', path: 'create', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'data', required: true, varType: VarType::JSON)]
    public function create(object $data): ApiResponse
    {
        return new ApiResponse($this->wfPage->save(null, $data));
    }

    #[ControllerMethod(name: 'update', path: 'update', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'pageId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'data', required: true, varType: VarType::JSON)]
    public function update(int $pageId, object $data): ApiResponse
    {
        return new ApiResponse($this->wfPage->save($pageId, $data));
    }

    #[ControllerMethod(name: 'status', path: 'status', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'pageId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'status', required: true, varType: VarType::STRING)]
    public function status(int $pageId, string $status): ApiResponse
    {
        $this->wfPage->changeStatus($pageId, $status);
        return new ApiResponse(['success' => true]);
    }

    #[ControllerMethod(name: 'delete', path: 'delete', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'pageId', required: true, varType: VarType::INT)]
    public function delete(int $pageId): ApiResponse
    {
        $this->wfPage->delete($pageId);
        return new ApiResponse(['success' => true]);
    }

    #[ControllerMethod(name: 'preview', path: 'preview', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'data', required: true, varType: VarType::JSON)]
    public function preview(object $data): ApiResponse
    {
        $response = new ApiResponse($this->wfPage->preview($data));
        return $response;
    }

    #[ControllerMethod(name: 'validate_draft', path: 'validate-draft', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'data', required: true, varType: VarType::JSON)]
    public function validate_draft(object $data): ApiResponse
    {
        $response = new ApiResponse($this->wfPage->validateDraft($data));
        return $response;
    }

    #[ControllerMethod(name: 'ai_sanitize_html', path: 'ai/sanitize-html', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'data', required: true, varType: VarType::JSON)]
    public function ai_sanitize_html(object $data): ApiResponse
    {
        return new ApiResponse($this->wfPage->sanitizeAiHtml($data));
    }

    #[ControllerMethod(name: 'ai_validate_draft', path: 'ai/validate-draft', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'data', required: true, varType: VarType::JSON)]
    public function ai_validate_draft(object $data): ApiResponse
    {
        return new ApiResponse($this->wfPage->validateAiDraft($data));
    }
}
