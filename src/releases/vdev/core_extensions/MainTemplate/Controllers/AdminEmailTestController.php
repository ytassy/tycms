<?php

namespace YTassy\Tycms\CoreExts\MainTemplate\Controllers;

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
use YTassy\Tycms\Routing\Annotation\Doc\ControllerParam4;
use YTassy\Tycms\Routing\Annotation\Doc\VarType;
use YTassy\Tycms\Routing\Annotation\NoTransaction;

#[Controller(name: 'TemplateMainEmailTest', isApi: true, path: '/administrator/api/templates/main/mail/test')]
class AdminEmailTestController extends BaseController
{
    use TraitWF;

    #[SecurityDoNotCheckPatterns]
    #[ControllerMethod(name: 'send', path: '', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'spaceId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'recipient', required: true, varType: VarType::STRING)]
    #[ControllerParam3(name: 'mode', required: true, varType: VarType::STRING)]
    #[ControllerParam4(name: 'params', required: true, schemaFile: __DIR__ . '/Schemas/EmailTestConfiguration.php')]
    #[NoTransaction]
    public function send(int $spaceId, string $recipient, string $mode, object $params): ApiResponse
    {
        return new ApiResponse([
            'sent' => $this->wfAdminExtensions->sendTemplateTestMail($spaceId, $recipient, $mode, $params),
        ]);
    }
}
