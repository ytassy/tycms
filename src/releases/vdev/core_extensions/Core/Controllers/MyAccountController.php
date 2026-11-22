<?php

namespace YTassy\Tycms\CoreExts\Core\Controllers;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseController;
use YTassy\Tycms\CoreExts\Core\WF\TraitWF;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Response\ApiResponse;
use YTassy\Tycms\Routing\Annotation\Doc\{Controller, ControllerMethod, ControllerParam1, ControllerParam2, ControllerParam3, VarType};

#[Controller(name: 'MyAccount', isApi: true, path: 'account/api')]
class MyAccountController extends BaseController
{
    use TraitWF;

    #[ControllerMethod(name: 'Read', path: '')]
    public function read(): ApiResponse
    {
        return $this->privateResponse($this->wfMyAccount->read());
    }

    #[ControllerMethod(name: 'Save', path: 'save', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'data', required: true, varType: VarType::JSON)]
    public function save(mixed $data): ApiResponse
    {
        return $this->privateResponse($this->wfMyAccount->save($data));
    }

    #[ControllerMethod(name: 'Password', path: 'password', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'current', required: true, varType: VarType::STRING)]
    #[ControllerParam2(name: 'password', required: true, varType: VarType::STRING)]
    #[ControllerParam3(name: 'confirmation', required: true, varType: VarType::STRING)]
    public function password(string $current, string $password, string $confirmation): ApiResponse
    {
        return $this->privateResponse($this->wfMyAccount->changePassword($current, $password, $confirmation));
    }

    #[ControllerMethod(name: 'Unlink', path: 'sso/unlink', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'linkId', required: true, varType: VarType::INT)]
    public function unlink(int $linkId): ApiResponse
    {
        return $this->privateResponse($this->wfMyAccount->unlink($linkId));
    }

    #[ControllerMethod(name: 'StartSso', path: 'sso/start', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'providerId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'returnPath', required: true, varType: VarType::STRING)]
    public function startSso(int $providerId, string $returnPath): ApiResponse
    {
        return $this->privateResponse(['url' => $this->wfSso->startLink($providerId, $returnPath)]);
    }

    #[ControllerMethod(name: 'StartIntegration', path: 'integrations/start', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'providerId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'returnPath', required: true, varType: VarType::STRING)]
    public function startIntegration(int $providerId, string $returnPath): ApiResponse
    {
        return $this->privateResponse(['url' => $this->wfOAuthIntegration->start($providerId, $returnPath)]);
    }

    #[ControllerMethod(name: 'DisconnectIntegration', path: 'integrations/disconnect', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'providerId', required: true, varType: VarType::INT)]
    public function disconnectIntegration(int $providerId): ApiResponse
    {
        $this->wfMyAccount->requireAccount();
        $this->wfOAuthIntegration->disconnect($providerId);
        return $this->privateResponse($this->wfMyAccount->read());
    }

    private function privateResponse(array $data): ApiResponse
    {
        // Les réponses POST contiennent elles aussi le profil ou une URL d'autorisation sensible.
        $response = new ApiResponse($data);
        $response->httpHeaders['Cache-Control'] = 'private, no-store';
        return $response;
    }
}
