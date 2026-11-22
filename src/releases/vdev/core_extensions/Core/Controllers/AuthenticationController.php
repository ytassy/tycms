<?php

namespace YTassy\Tycms\CoreExts\Core\Controllers;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseController;
use YTassy\Tycms\CoreExts\Core\WF\TraitWF;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Response\ApiResponse;
use YTassy\Tycms\Routing\Annotation\Doc\{Controller, ControllerMethod, ControllerParam1, ControllerParam2, ControllerParam3, VarType};

#[Controller(name: 'Authentication', isApi: true, path: 'auth/api')]
class AuthenticationController extends BaseController
{
    use TraitWF;

    #[ControllerMethod(name: 'Login', path: 'login', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'token', required: true, varType: VarType::STRING)]
    #[ControllerParam2(name: 'email', required: true, varType: VarType::STRING)]
    #[ControllerParam3(name: 'password', required: true, varType: VarType::STRING)]
    public function login(string $token, string $email, string $password): ApiResponse
    {
        return new ApiResponse($this->wfAuthentication->login($token, $email, $password));
    }

    #[ControllerMethod(name: 'Register', path: 'register', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'token', required: true, varType: VarType::STRING)]
    #[ControllerParam2(name: 'data', required: true, varType: VarType::JSON)]
    #[ControllerParam3(name: 'acceptedTerms', required: true, varType: VarType::BOOL)]
    public function register(string $token, mixed $data, bool $acceptedTerms): ApiResponse
    {
        return new ApiResponse($this->wfAuthentication->register($token, $data, $acceptedTerms));
    }

    #[ControllerMethod(name: 'Join', path: 'join', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'token', required: true, varType: VarType::STRING)]
    #[ControllerParam2(name: 'acceptedTerms', required: true, varType: VarType::BOOL)]
    public function join(string $token, bool $acceptedTerms): ApiResponse
    {
        return new ApiResponse($this->wfAuthentication->join($token, $acceptedTerms));
    }
}
