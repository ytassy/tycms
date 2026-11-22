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

#[Controller(name: 'administrator_api_roles_', isApi: true, path: 'administrator/api/roles')]
class AdminRoleController extends BaseController
{
    use TraitWF;

    #[ControllerMethod(name: 'read', path: '')]
    public function read(): ApiResponse
    {
        return new ApiResponse($this->wfRole->getDirectory());
    }

    #[ControllerMethod(name: 'create', path: 'create', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'data', required: true, varType: VarType::JSON,
        schemaFile: __DIR__ . '/Schemas/RoleCreate.php')]
    public function create(object $data): ApiResponse
    {
        return new ApiResponse(['role' => $this->wfRole->createRole($data)]);
    }

    #[ControllerMethod(name: 'update', path: 'update', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'roleId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'data', required: true, varType: VarType::JSON,
        schemaFile: __DIR__ . '/Schemas/RoleData.php')]
    public function update(int $roleId, object $data): ApiResponse
    {
        return new ApiResponse(['role' => $this->wfRole->updateRole($roleId, $data)]);
    }

    #[ControllerMethod(name: 'delete', path: 'delete', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'roleId', required: true, varType: VarType::INT)]
    public function delete(int $roleId): ApiResponse
    {
        return new ApiResponse(['deleted' => $this->wfRole->deleteRole($roleId)]);
    }
}
