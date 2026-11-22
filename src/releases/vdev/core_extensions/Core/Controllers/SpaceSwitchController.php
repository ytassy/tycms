<?php

namespace YTassy\Tycms\CoreExts\Core\Controllers;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseController;
use YTassy\Tycms\CoreExts\Core\WF\TraitWF;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Response\ApiResponse;
use YTassy\Tycms\Routing\Annotation\Doc\Controller;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerMethod;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerParam1;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerParam2;
use YTassy\Tycms\Routing\Annotation\Doc\VarType;

#[Controller(name: 'space_switch_', isApi: true, path: 'space/api/switch')]
class SpaceSwitchController extends BaseController
{
    use TraitWF;

    #[ControllerMethod(name: 'options', path: 'options', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'token', required: true, varType: VarType::STRING)]
    public function options(string $token): ApiResponse
    {
        return new ApiResponse($this->wfSpaceSwitch->getOptions($token));
    }

    #[ControllerMethod(name: 'select', path: 'select', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'token', required: true, varType: VarType::STRING)]
    #[ControllerParam2(name: 'spaceId', required: true, varType: VarType::INT)]
    public function select(string $token, int $spaceId): ApiResponse
    {
        return new ApiResponse($this->wfSpaceSwitch->select($token, $spaceId));
    }
}
