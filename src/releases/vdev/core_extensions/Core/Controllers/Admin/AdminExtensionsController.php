<?php

namespace YTassy\Tycms\CoreExts\Core\Controllers\Admin;

use YTassy\Tycms\Routing\Annotation\Doc\Controller;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerMethod;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerParam1;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerParam2;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerParam3;
use YTassy\Tycms\Routing\Annotation\Doc\VarType;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseController;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\AnnotationsController\Acl;
use YTassy\Tycms\CoreExts\Core\WF\TraitWF;
use YTassy\Tycms\Response\ApiResponse;
use YTassy\Tycms\CoreExts\Security\ExtensionApi\AnnotationsController\SecurityDoNotCheckPatterns;

#[Controller(name: "administrator_api_", isApi: true, path: "administrator/api/ext")]
class AdminExtensionsController extends BaseController
{
    use TraitWF;
    
    #[ControllerMethod(name: "get", path: "")]
    public function getDatas()
    {
        $data = $this->wfAdminExtensions->getDatas();

        $response = new ApiResponse($data);
        return $response;
    }

    /** État des migrations par extension ; réservé à ROOT dans le WF. */
    #[ControllerMethod(name: 'migrations', path: 'migrations')]
    #[Acl(globalPermission: Permissions::ROOT, spaceId: 1)]
    public function getMigrations(): ApiResponse
    {
        return new ApiResponse($this->wfAdminExtensions->getMigrationsStatus());
    }

    /** État des mises à jour des extensions installées ; réservé à ROOT dans le WF. */
    #[ControllerMethod(name: 'updates', path: 'updates')]
    #[Acl(globalPermission: Permissions::ROOT, spaceId: 1)]
    public function getUpdates(): ApiResponse
    {
        return new ApiResponse($this->wfAdminExtensions->getUpdatesStatus());
    }

    /** Exécution des migrations d'une extension Core ; POST protégé par le CSRF commun. */
    #[ControllerMethod(name: 'runMigrations', path: 'migrations/run', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'extension', required: true, varType: VarType::STRING)]
    #[Acl(globalPermission: Permissions::ROOT, spaceId: 1)]
    public function runMigrations(string $extension): ApiResponse
    {
        return new ApiResponse($this->wfAdminExtensions->runMigrations($extension));
    }

    /** Désinstallation d'une extension suivie par un catalogue ; ROOT uniquement. */
    #[ControllerMethod(name: 'uninstall', path: 'uninstall', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'extension', required: true, varType: VarType::STRING)]
    #[Acl(globalPermission: Permissions::ROOT, spaceId: 1)]
    public function uninstall(string $extension): ApiResponse
    {
        return new ApiResponse($this->wfAdminExtensions->uninstall($extension));
    }

    // Les motifs WAF sont eux-mêmes des entrées légitimes de ce formulaire ; les ACL restent dans le WF.
    #[SecurityDoNotCheckPatterns]
    #[ControllerMethod(name: 'save', path: 'save', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'spaceId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'extension', required: true, varType: VarType::STRING)]
    #[ControllerParam3(
        name: 'params',
        required: true,
        schemaFile: __DIR__ . '/Schemas/ExtensionConfiguration.php',
    )]
    public function save(int $spaceId, string $extension, object $params): ApiResponse
    {
        $response = new ApiResponse([
            'configuration' => $this->wfAdminExtensions->saveConfiguration($spaceId, $extension, $params),
        ]);
        return $response;
    }
}
