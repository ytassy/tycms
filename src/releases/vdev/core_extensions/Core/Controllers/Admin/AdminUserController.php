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

#[Controller(name: 'administrator_api_users_', isApi: true, path: 'administrator/api/users')]
class AdminUserController extends BaseController
{
    use TraitWF;

    #[ControllerMethod(name: 'directory', path: 'directory')]
    public function directory(): ApiResponse
    {
        return new ApiResponse($this->wfAdminUser->getDirectory());
    }

    #[ControllerMethod(name: 'list', path: '')]
    #[ControllerParam1(name: 'page', required: false, varType: VarType::INT)]
    #[ControllerParam2(name: 'perPage', required: false, varType: VarType::INT)]
    #[ControllerParam3(name: 'search', required: false, varType: VarType::STRING)]
    #[ControllerParam4(name: 'type', required: false, varType: VarType::STRING)]
    public function list(
        ?int $page = null,
        ?int $perPage = null,
        ?string $search = null,
        ?string $type = 'user',
    ): ApiResponse {
        // « all » (ou une valeur vide) demande tous les types de comptes.
        $accountType = ($type === null || $type === '' || $type === 'all') ? null : $type;

        return new ApiResponse($this->wfAdminUser->listUsers($page ?? 1, $perPage ?? 20, $search ?? '', $accountType));
    }

    #[ControllerMethod(name: 'read', path: 'read')]
    #[ControllerParam1(name: 'userId', required: true, varType: VarType::INT)]
    public function read(int $userId): ApiResponse
    {
        return new ApiResponse(['user' => $this->wfAdminUser->getUser($userId)]);
    }

    #[ControllerMethod(name: 'create', path: 'create', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'data', required: true, schemaFile: __DIR__ . '/Schemas/UserAccountCreate.php')]
    public function create(object $data): ApiResponse
    {
        return new ApiResponse(['user' => $this->wfAdminUser->createUser($data)]);
    }

    #[ControllerMethod(name: 'update', path: 'update', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'userId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'data', required: true, schemaFile: __DIR__ . '/Schemas/UserAccountData.php')]
    public function update(int $userId, object $data): ApiResponse
    {
        return new ApiResponse(['user' => $this->wfAdminUser->updateUser($userId, $data)]);
    }

    #[ControllerMethod(name: 'password', path: 'password', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'userId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'data', required: true, schemaFile: __DIR__ . '/Schemas/UserPassword.php')]
    public function password(int $userId, object $data): ApiResponse
    {
        return new ApiResponse(['updated' => $this->wfAdminUser->updatePassword($userId, $data->password)]);
    }

    #[ControllerMethod(name: 'role', path: 'role', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'userId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'roleId', required: true, varType: VarType::INT)]
    #[ControllerParam3(name: 'spaceId', required: true, varType: VarType::INT)]
    #[ControllerParam4(name: 'assigned', required: true, varType: VarType::BOOL)]
    public function role(int $userId, int $roleId, int $spaceId, bool $assigned): ApiResponse
    {
        return new ApiResponse(['user' => $this->wfAdminUser->setRole($userId, $roleId, $spaceId, $assigned)]);
    }

    #[ControllerMethod(name: 'group', path: 'group', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'userId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'groupId', required: true, varType: VarType::INT)]
    #[ControllerParam3(name: 'assigned', required: true, varType: VarType::BOOL)]
    public function group(int $userId, int $groupId, bool $assigned): ApiResponse
    {
        return new ApiResponse(['user' => $this->wfAdminUser->setGroup($userId, $groupId, $assigned)]);
    }

    #[ControllerMethod(name: 'groups_save', path: 'groups/save', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'userId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'data', required: true, schemaFile: __DIR__ . '/Schemas/UserAssignments.php')]
    public function saveGroups(int $userId, object $data): ApiResponse
    {
        return new ApiResponse(['user' => $this->wfAdminUser->replaceGroups($userId, $data->before, $data->after)]);
    }

    #[ControllerMethod(name: 'roles_save', path: 'roles/save', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'userId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'data', required: true, schemaFile: __DIR__ . '/Schemas/UserRoleAssignments.php')]
    public function saveRoles(int $userId, object $data): ApiResponse
    {
        return new ApiResponse(['user' => $this->wfAdminUser->replaceRoles($userId, $data->before, $data->after)]);
    }

    #[ControllerMethod(name: 'unlink', path: 'sso/unlink', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'userId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'linkId', required: true, varType: VarType::INT)]
    public function unlink(int $userId, int $linkId): ApiResponse
    {
        return new ApiResponse(['user' => $this->wfAdminUser->unlinkSso($userId, $linkId)]);
    }

    #[ControllerMethod(name: 'delete', path: 'delete', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'userId', required: true, varType: VarType::INT)]
    public function delete(int $userId): ApiResponse
    {
        return new ApiResponse(['deleted' => $this->wfAdminUser->deleteUser($userId)]);
    }
}
