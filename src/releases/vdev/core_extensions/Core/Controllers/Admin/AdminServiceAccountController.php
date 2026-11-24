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
use YTassy\Tycms\Routing\Annotation\Doc\ControllerParam3;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerParam4;
use YTassy\Tycms\Routing\Annotation\Doc\VarType;

#[Controller(name: 'administrator_api_service_accounts_', isApi: true, path: 'administrator/api/service-accounts')]
class AdminServiceAccountController extends BaseController
{
    use TraitWF;

    #[ControllerMethod(name: 'list', path: '')]
    public function list(): ApiResponse
    {
        return new ApiResponse(['accounts' => $this->wfServiceAccount->list()]);
    }

    #[ControllerMethod(name: 'create', path: 'create', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'name', required: true, varType: VarType::STRING)]
    #[ControllerParam2(name: 'email', required: true, varType: VarType::STRING)]
    #[ControllerParam3(name: 'spaceId', required: true, varType: VarType::INT)]
    public function create(string $name, string $email, int $spaceId): ApiResponse
    {
        return new ApiResponse(['account' => $this->wfServiceAccount->create($name, $email, $spaceId)]);
    }

    #[ControllerMethod(name: 'tokens', path: 'tokens')]
    #[ControllerParam1(name: 'userId', required: true, varType: VarType::INT)]
    public function tokens(int $userId): ApiResponse
    {
        return new ApiResponse(['tokens' => $this->wfServiceAccount->tokens($userId)]);
    }

    #[ControllerMethod(name: 'token_create', path: 'token/create', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'userId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'name', required: true, varType: VarType::STRING)]
    #[ControllerParam3(name: 'spaceId', required: false, varType: VarType::INT)]
    #[ControllerParam4(name: 'expiresAt', required: false, varType: VarType::STRING)]
    public function createToken(int $userId, string $name, ?int $spaceId = null, ?string $expiresAt = null): ApiResponse
    {
        return new ApiResponse($this->wfServiceAccount->createToken($userId, $name, $spaceId, $expiresAt));
    }

    #[ControllerMethod(name: 'token_revoke', path: 'token/revoke', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'tokenId', required: true, varType: VarType::INT)]
    public function revokeToken(int $tokenId): ApiResponse
    {
        return new ApiResponse(['revoked' => $this->wfServiceAccount->revokeToken($tokenId)]);
    }

    #[ControllerMethod(name: 'delete', path: 'delete', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'userId', required: true, varType: VarType::INT)]
    public function delete(int $userId): ApiResponse
    {
        return new ApiResponse(['deleted' => $this->wfServiceAccount->delete($userId)]);
    }
}