<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Controllers\Admin;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseController;
use YTassy\Tycms\CoreExts\Core\WF\TraitWF;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Response\ApiResponse;
use YTassy\Tycms\Routing\Annotation\Doc\{Controller, ControllerMethod, ControllerParam1, ControllerParam2, VarType};
use YTassy\Tycms\Routing\Annotation\NoTransaction;

#[Controller(name: 'administrator_api_logs_', isApi: true, path: 'administrator/api/logs')]
final class AdminLogsController extends BaseController
{
    use TraitWF;

    #[ControllerMethod(name: 'status', path: 'status')]
    #[NoTransaction]
    public function status(): ApiResponse
    {
        return new ApiResponse($this->wfLogs->status());
    }

    // POST conserve le curseur borné hors de l'URL et bénéficie du CSRF commun.
    #[ControllerMethod(name: 'read', path: 'read', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'filters', required: true, varType: VarType::STRING)]
    #[ControllerParam2(name: 'cursor', required: false, varType: VarType::STRING)]
    #[NoTransaction]
    public function read(string $filters, ?string $cursor = null): ApiResponse
    {
        return new ApiResponse($this->wfLogs->read($filters, $cursor));
    }
}
