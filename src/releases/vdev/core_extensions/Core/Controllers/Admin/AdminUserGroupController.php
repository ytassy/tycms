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

#[Controller(name: 'administrator_api_user_groups_', isApi: true, path: 'administrator/api/user-groups')]
class AdminUserGroupController extends BaseController
{
    use TraitWF;

    #[ControllerMethod(name: 'read', path: '')]
    public function read(): ApiResponse
    {
        return new ApiResponse($this->wfUserGroup->getDirectory());
    }

    #[ControllerMethod(name: 'create', path: 'create', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'data', required: true, schemaFile: __DIR__ . '/Schemas/UserGroupCreate.php')]
    public function create(object $data): ApiResponse
    {
        return new ApiResponse(['group' => $this->wfUserGroup->createGroup($data)]);
    }

    #[ControllerMethod(name: 'update', path: 'update', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'groupId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'data', required: true, schemaFile: __DIR__ . '/Schemas/UserGroupData.php')]
    public function update(int $groupId, object $data): ApiResponse
    {
        return new ApiResponse(['group' => $this->wfUserGroup->updateGroup($groupId, $data)]);
    }

    #[ControllerMethod(name: 'roles_update', path: 'roles/update', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'groupId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'roleIds', required: true, schemaFile: __DIR__ . '/Schemas/UserGroupRoles.php')]
    public function updateRoles(int $groupId, array $roleIds): ApiResponse
    {
        return new ApiResponse(['group' => $this->wfUserGroup->updateRoleAssignments($groupId, $roleIds)]);
    }

    #[ControllerMethod(name: 'delete', path: 'delete', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'groupId', required: true, varType: VarType::INT)]
    public function delete(int $groupId): ApiResponse
    {
        return new ApiResponse(['deleted' => $this->wfUserGroup->deleteGroup($groupId)]);
    }
}
