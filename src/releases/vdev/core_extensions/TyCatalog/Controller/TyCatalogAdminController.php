<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\TyCatalog\Controller;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseController;
use YTassy\Tycms\CoreExts\TyCatalog\Models\CatalogVersion;
use YTassy\Tycms\CoreExts\TyCatalog\Traits\TraitTyCatalogWF;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Request\WebRequest;
use YTassy\Tycms\Response\ApiResponse;
use YTassy\Tycms\Routing\Annotation\Doc\Controller;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerMethod;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerParam1;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerParam2;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerParam3;
use YTassy\Tycms\Routing\Annotation\Doc\VarType;

#[Controller(name: 'administrator_api_ty_catalog_', isApi: true, path: 'administrator/api/ty-catalog')]
final class TyCatalogAdminController extends BaseController
{
    use TraitTyCatalogWF;

    /** Catalogues visibles pour l'utilisateur, avec l'état de ses permissions. */
    #[ControllerMethod(name: 'overview', path: '')]
    public function overview(): ApiResponse
    {
        return new ApiResponse($this->wfTyCatalogAdmin->getOverview());
    }

    /** Création ou modification d'un catalogue et de ses références amies. */
    #[ControllerMethod(name: 'save', path: 'save', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'data', required: true, schemaFile: __DIR__ . '/Schemas/CatalogSave.php')]
    public function save(object $data): ApiResponse
    {
        return new ApiResponse($this->wfTyCatalogAdmin->save($data));
    }

    /** Jetons de publication du catalogue, réservés à ROOT dans le WF. */
    #[ControllerMethod(name: 'tokens', path: 'tokens')]
    #[ControllerParam1(name: 'catalogId', required: true, varType: VarType::INT)]
    public function tokens(int $catalogId): ApiResponse
    {
        return new ApiResponse($this->wfTyCatalogAdmin->getTokens($catalogId));
    }

    #[ControllerMethod(name: 'token_create', path: 'tokens/create', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'data', required: true, schemaFile: __DIR__ . '/Schemas/TokenCreate.php')]
    public function tokenCreate(object $data): ApiResponse
    {
        return new ApiResponse($this->wfTyCatalogAdmin->createToken($data));
    }

    #[ControllerMethod(name: 'token_revoke', path: 'tokens/revoke', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'data', required: true, schemaFile: __DIR__ . '/Schemas/TokenRevoke.php')]
    public function tokenRevoke(object $data): ApiResponse
    {
        $this->wfTyCatalogAdmin->revokeToken($data);
        return new ApiResponse(['revoked' => true]);
    }

    /** Nom affiché et description de la fiche publique d'une extension. */
    #[ControllerMethod(name: 'save_extension', path: 'extensions/save', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'data', required: true, schemaFile: __DIR__ . '/Schemas/ExtensionFiche.php')]
    public function saveExtension(object $data): ApiResponse
    {
        return new ApiResponse($this->wfTyCatalogAdmin->saveExtensionFiche($data));
    }

    /** Versions et extensions d'un catalogue du Space courant. */
    #[ControllerMethod(name: 'versions', path: 'versions')]
    #[ControllerParam1(name: 'catalogId', required: true, varType: VarType::INT)]
    public function versions(int $catalogId): ApiResponse
    {
        return new ApiResponse($this->wfTyCatalogPublication->getVersions($catalogId));
    }

    /** Dépôt local : ZIP, notes et enveloppe de signature en multipart. */
    #[ControllerMethod(name: 'deposit_upload', path: 'versions/upload', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'catalogId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'displayName', required: false, varType: VarType::STRING)]
    #[ControllerParam3(name: 'description', required: false, varType: VarType::STRING)]
    public function depositUpload(
        int $catalogId,
        WebRequest $_request,
        string $displayName = '',
        string $description = '',
    ): ApiResponse {
        return new ApiResponse($this->wfTyCatalogPublication->depositLocal(
            $catalogId, $_request, $displayName, $description,
        ));
    }

    /** Dépôt par référence externe, avec vérification des octets téléchargés. */
    #[ControllerMethod(name: 'deposit_reference', path: 'versions/reference', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'data', required: true, schemaFile: __DIR__ . '/Schemas/VersionDeposit.php')]
    public function depositReference(object $data): ApiResponse
    {
        return new ApiResponse($this->wfTyCatalogPublication->depositExternal($data));
    }

    #[ControllerMethod(name: 'publish_version', path: 'versions/publish', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'data', required: true, schemaFile: __DIR__ . '/Schemas/VersionAction.php')]
    public function publishVersion(object $data): ApiResponse
    {
        // Canal facultatif : sans précision, la version part en release comme avant.
        $channel = isset($data->channel) && is_string($data->channel) && $data->channel !== ''
            ? $data->channel : CatalogVersion::CHANNEL_RELEASE;
        $cleanup = $this->wfTyCatalogPublication->publish(
            (int) $data->catalogId,
            (int) $data->versionId,
            $channel,
        );
        return new ApiResponse(['published' => true, 'channel' => $channel, 'cleanup' => $cleanup]);
    }

    #[ControllerMethod(name: 'promote_version', path: 'versions/promote', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'data', required: true, schemaFile: __DIR__ . '/Schemas/VersionAction.php')]
    public function promoteVersion(object $data): ApiResponse
    {
        $this->wfTyCatalogPublication->promote((int) $data->catalogId, (int) $data->versionId);
        return new ApiResponse(['promoted' => true]);
    }

    #[ControllerMethod(name: 'withdraw_version', path: 'versions/withdraw', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'data', required: true, schemaFile: __DIR__ . '/Schemas/VersionAction.php')]
    public function withdrawVersion(object $data): ApiResponse
    {
        $this->wfTyCatalogPublication->withdraw((int) $data->catalogId, (int) $data->versionId);
        return new ApiResponse(['withdrawn' => true]);
    }

    #[ControllerMethod(name: 'reactivate_version', path: 'versions/reactivate', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'data', required: true, schemaFile: __DIR__ . '/Schemas/VersionAction.php')]
    public function reactivateVersion(object $data): ApiResponse
    {
        $cleanup = $this->wfTyCatalogPublication->reactivate((int) $data->catalogId, (int) $data->versionId);
        return new ApiResponse(['reactivated' => true, 'cleanup' => $cleanup]);
    }

    /** Nettoyage manuel de la rétention du catalogue. */
    #[ControllerMethod(name: 'cleanup_versions', path: 'versions/cleanup', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'data', required: true, schemaFile: __DIR__ . '/Schemas/VersionCleanup.php')]
    public function cleanupVersions(object $data): ApiResponse
    {
        return new ApiResponse(['cleanup' => $this->wfTyCatalogPublication->cleanup((int) $data->catalogId)]);
    }
}
