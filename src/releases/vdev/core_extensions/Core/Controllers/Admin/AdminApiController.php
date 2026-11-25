<?php

namespace YTassy\Tycms\CoreExts\Core\Controllers\Admin;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\AnnotationsController\Acl;
use YTassy\Tycms\Routing\Annotation\Doc\Controller;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerMethod;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerParam1;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerParam2;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerParam3;
use YTassy\Tycms\Routing\Annotation\Doc\VarType;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseController;
use YTassy\Tycms\CoreExts\Core\Views\Admin\AdminView;
use YTassy\Tycms\CoreExts\Core\WF\TraitWF;
use YTassy\Tycms\Response\ApiResponse;

#[Controller(name: "administrator_api_", isApi: true, path: "administrator/api")]
class AdminApiController extends BaseController
{
    use TraitWF;

    #[ControllerMethod(name: 'SystemUpdates', path: 'system-updates')]
    public function systemUpdates(): ApiResponse
    {
        return new ApiResponse($this->wfSystemUpdate->context());
    }

    /**
     * Bilan d'analyse de sécurité du site.
     *
     * Lecture seule, réservée aux comptes ROOT : les contrôles sont exécutés à
     * la demande et rien n'est persisté.
     */
    #[ControllerMethod(name: 'SecurityReport', path: 'system/security-report')]
    public function securityReport(): ApiResponse
    {
        return new ApiResponse($this->wfSecurityReport->report());
    }

    #[ControllerMethod(name: 'CheckSystemUpdates', path: 'system-updates/check', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'sourceVersion', required: false, varType: VarType::STRING)]
    public function checkSystemUpdates(?string $sourceVersion = null): ApiResponse
    {
        return new ApiResponse($this->wfSystemUpdate->check($sourceVersion));
    }

    #[ControllerMethod(name: 'DownloadSystemUpdate', path: 'system-updates/download', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'version', required: true, varType: VarType::STRING)]
    #[ControllerParam2(name: 'sha256', required: true, varType: VarType::STRING)]
    #[ControllerParam3(name: 'sourceVersion', required: false, varType: VarType::STRING)]
    public function downloadSystemUpdate(string $version, string $sha256, ?string $sourceVersion = null): ApiResponse
    {
        return new ApiResponse($this->wfSystemUpdate->download($version, $sha256, $sourceVersion));
    }

    #[ControllerMethod(name: 'PrepareSystemUpdate', path: 'system-updates/prepare', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'version', required: true, varType: VarType::STRING)]
    #[ControllerParam2(name: 'sha256', required: true, varType: VarType::STRING)]
    #[ControllerParam3(name: 'sourceVersion', required: false, varType: VarType::STRING)]
    public function prepareSystemUpdate(string $version, string $sha256, ?string $sourceVersion = null): ApiResponse
    {
        $response = new ApiResponse($this->wfSystemUpdate->prepare($version, $sha256, $sourceVersion));
        $response->httpHeaders['Cache-Control'] = 'no-store';
        return $response;
    }

    #[ControllerMethod(name: 'ResetSystemUpdate', path: 'system-updates/reset', methods: [RequestMethod::POST])]
    public function resetSystemUpdate(): ApiResponse
    {
        return new ApiResponse($this->wfSystemUpdate->reset());
    }

    #[ControllerMethod(name: 'CleanupSystemUpdate', path: 'system-updates/cleanup', methods: [RequestMethod::POST])]
    public function cleanupSystemUpdate(): ApiResponse
    {
        return new ApiResponse($this->wfSystemUpdate->cleanup());
    }

    #[ControllerMethod(name: "Info", path: "infos_system")]
    #[Acl(globalPermission: Permissions::ROOT, spaceId: 1)]
    public function infos_system()
    {
        return new ApiResponse($this->wfSystemInfo->getInfos());
    }

}
