<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Controllers\Admin;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\AnnotationsController\Acl;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseController;
use YTassy\Tycms\CoreExts\Core\WF\TraitWF;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Response\ApiResponse;
use YTassy\Tycms\Routing\Annotation\Doc\Controller;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerMethod;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerParam1;

/** Sources de catalogues externes du client Core, réservées à ROOT. */
#[Controller(name: 'administrator_api_extension_sources_', isApi: true, path: 'administrator/api/extension-sources')]
final class AdminExtensionSourceController extends BaseController
{
    use TraitWF;

    #[ControllerMethod(name: 'sources', path: '')]
    #[Acl(globalPermission: Permissions::ROOT, spaceId: 1)]
    public function sources(): ApiResponse
    {
        return new ApiResponse($this->wfExtensionSource->list());
    }

    #[ControllerMethod(name: 'source_save', path: 'save', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'data', required: true, schemaFile: __DIR__ . '/Schemas/ExtensionSourceSave.php')]
    #[Acl(globalPermission: Permissions::ROOT, spaceId: 1)]
    public function save(object $data): ApiResponse
    {
        return new ApiResponse($this->wfExtensionSource->save($data));
    }

    #[ControllerMethod(name: 'source_consult', path: 'consult', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'data', required: true, schemaFile: __DIR__ . '/Schemas/ExtensionSourceConsult.php')]
    #[Acl(globalPermission: Permissions::ROOT, spaceId: 1)]
    public function consult(object $data): ApiResponse
    {
        return new ApiResponse($this->wfExtensionSource->consult($data));
    }

    #[ControllerMethod(name: 'source_install', path: 'install', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'data', required: true, schemaFile: __DIR__ . '/Schemas/ExtensionSourceInstall.php')]
    #[Acl(globalPermission: Permissions::ROOT, spaceId: 1)]
    public function install(object $data): ApiResponse
    {
        return new ApiResponse($this->wfExtensionSource->install($data));
    }

    #[ControllerMethod(name: 'source_update', path: 'update', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'data', required: true, schemaFile: __DIR__ . '/Schemas/ExtensionSourceUpdate.php')]
    #[Acl(globalPermission: Permissions::ROOT, spaceId: 1)]
    public function update(object $data): ApiResponse
    {
        return new ApiResponse($this->wfExtensionSource->update($data));
    }

    #[ControllerMethod(name: 'source_update_finalize', path: 'update/finalize', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'data', required: true, schemaFile: __DIR__ . '/Schemas/ExtensionSourceUpdateOperation.php')]
    #[Acl(globalPermission: Permissions::ROOT, spaceId: 1)]
    public function updateFinalize(object $data): ApiResponse
    {
        return new ApiResponse($this->wfExtensionSource->finalizeUpdate($data));
    }

    #[ControllerMethod(name: 'source_update_rollback', path: 'update/rollback', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'data', required: true, schemaFile: __DIR__ . '/Schemas/ExtensionSourceUpdateOperation.php')]
    #[Acl(globalPermission: Permissions::ROOT, spaceId: 1)]
    public function updateRollback(object $data): ApiResponse
    {
        return new ApiResponse($this->wfExtensionSource->rollbackUpdate($data));
    }
}
