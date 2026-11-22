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
use YTassy\Tycms\Routing\Annotation\Doc\ControllerParam3;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerParam4;
use YTassy\Tycms\Routing\Annotation\Doc\VarType;

#[Controller(name: 'administrator_api_user_assignments_', isApi: true, path: 'administrator/api/user-assignments')]
final class AdminUserAssignmentsController extends BaseController
{
    use TraitWF;

    #[ControllerMethod(name: 'directory', path: 'directory')]
    public function directory(): ApiResponse
    {
        return new ApiResponse($this->wfAdminUser->getAssignmentsDirectory());
    }

    #[ControllerMethod(name: 'list', path: '')]
    #[ControllerParam1(name: 'spaceId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'page', required: false, varType: VarType::INT)]
    #[ControllerParam3(name: 'perPage', required: false, varType: VarType::INT)]
    #[ControllerParam4(name: 'search', required: false, varType: VarType::STRING)]
    public function list(int $spaceId, ?int $page = null, ?int $perPage = null, ?string $search = null): ApiResponse
    {
        return new ApiResponse($this->wfAdminUser->listAssignments($spaceId, $page ?? 1, $perPage ?? 20, $search ?? ''));
    }

    #[ControllerMethod(name: 'save', path: 'save', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'data', required: true, schemaFile: __DIR__ . '/Schemas/UserAssignmentCell.php')]
    public function save(object $data): ApiResponse
    {
        return new ApiResponse($this->wfAdminUser->saveAssignmentCell($data));
    }
}
