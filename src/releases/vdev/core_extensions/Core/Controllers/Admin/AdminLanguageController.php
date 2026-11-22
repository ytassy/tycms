<?php

namespace YTassy\Tycms\CoreExts\Core\Controllers\Admin;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseController;
use YTassy\Tycms\CoreExts\Core\WF\TraitWF;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Response\ApiResponse;
use YTassy\Tycms\Routing\Annotation\Doc\Controller;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerMethod;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerParam1;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerParam2;
use YTassy\Tycms\Routing\Annotation\Doc\VarType;

/** Adapte les requêtes HTTP et délègue les droits et règles métier au workflow. */
#[Controller(name: 'administrator_api_languages_', isApi: true, path: 'administrator/api/languages')]
final class AdminLanguageController extends BaseController
{
    use TraitWF;

    #[ControllerMethod(name: 'spaces', path: 'spaces')]
    public function spaces(): ApiResponse
    {
        return new ApiResponse($this->wfLanguage->listSpaces());
    }

    #[ControllerMethod(name: 'entries', path: 'entries')]
    #[ControllerParam1(name: 'spaceId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'locale', required: true, varType: VarType::STRING)]
    public function entries(int $spaceId, string $locale): ApiResponse
    {
        return new ApiResponse($this->wfLanguage->listEntries($spaceId, $locale));
    }

    #[ControllerMethod(name: 'matrix', path: 'matrix')]
    #[ControllerParam1(name: 'spaceId', required: true, varType: VarType::INT)]
    public function matrix(int $spaceId): ApiResponse
    {
        // L'éditeur charge toutes les langues d'un Space avec une seule requête.
        return new ApiResponse($this->wfLanguage->listMatrix($spaceId));
    }

    #[ControllerMethod(name: 'selectable_keys', path: 'selectable-keys')]
    #[ControllerParam1(name: 'spaceId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'locale', required: true, varType: VarType::STRING)]
    public function selectableKeys(int $spaceId, string $locale): ApiResponse
    {
        return new ApiResponse($this->wfLanguage->listSelectableKeys($spaceId, $locale));
    }

    #[ControllerMethod(name: 'custom_create', path: 'custom/create', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'spaceId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'data', required: true, schemaFile: __DIR__ . '/Schemas/LanguageCustomKey.php')]
    public function customCreate(int $spaceId, object $data): ApiResponse
    {
        $this->wfLanguage->createCustomKey($spaceId, $data);
        return new ApiResponse(['created' => true]);
    }

    #[ControllerMethod(name: 'override_save', path: 'overrides/save', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'spaceId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'data', required: true, schemaFile: __DIR__ . '/Schemas/LanguageOverrideWrite.php')]
    public function overrideSave(int $spaceId, object $data): ApiResponse
    {
        $this->wfLanguage->saveOverride($spaceId, $data);
        return new ApiResponse(['saved' => true]);
    }

    #[ControllerMethod(name: 'override_save_many', path: 'overrides/save-many', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'spaceId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'data', required: true, schemaFile: __DIR__ . '/Schemas/LanguageOverrideSaveMany.php')]
    public function overrideSaveMany(int $spaceId, object $data): ApiResponse
    {
        // Le workflow valide tout le lot avant que la transaction ne soit validée.
        $this->wfLanguage->saveManyOverrides($spaceId, $data);
        return new ApiResponse(['saved' => true]);
    }

    #[ControllerMethod(name: 'override_delete', path: 'overrides/delete', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'spaceId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'data', required: true, schemaFile: __DIR__ . '/Schemas/LanguageOverrideDelete.php')]
    public function overrideDelete(int $spaceId, object $data): ApiResponse
    {
        $this->wfLanguage->deleteOverride($spaceId, $data);
        return new ApiResponse(['deleted' => true]);
    }
}
