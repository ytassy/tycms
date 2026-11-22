<?php

namespace YTassy\Tycms\CoreExts\Core\Controllers\Admin;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseController;
use YTassy\Tycms\CoreExts\Core\WF\TraitWF;
use YTassy\Tycms\CoreExts\Security\ExtensionApi\AnnotationsController\SecurityDoNotCheckPatterns;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Request\WebRequest;
use YTassy\Tycms\Response\ApiResponse;
use YTassy\Tycms\Routing\Annotation\Doc\Controller;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerMethod;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerParam1;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerParam2;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerParam3;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerParam4;
use YTassy\Tycms\Routing\Annotation\Doc\VarType;

#[Controller(name: 'administrator_api_medias_', isApi: true, path: 'administrator/api/medias')]
class AdminMediaController extends BaseController
{
    use TraitWF;

    #[ControllerMethod(name: 'read', path: '')]
    public function read(): ApiResponse
    {
        return new ApiResponse($this->wfMedia->getMediaDirectory());
    }

    #[ControllerMethod(name: 'list', path: 'list')]
    #[ControllerParam1(name: 'spaceId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'path', required: false, varType: VarType::STRING)]
    public function list(int $spaceId, string $path = ''): ApiResponse
    {
        return new ApiResponse($this->wfMedia->listDirectory($spaceId, $path));
    }

    #[ControllerMethod(name: 'page_files', path: 'page-files', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'spaceId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'options', required: true, varType: VarType::JSON)]
    #[ControllerParam3(name: 'locale', required: false, varType: VarType::STRING)]
    public function pageFiles(int $spaceId, object $options, string $locale = 'fr'): ApiResponse
    {
        return new ApiResponse($this->wfMedia->pageFileList($spaceId, $options, $locale));
    }

    #[ControllerMethod(name: 'folder_create', path: 'folder/create', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'spaceId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'path', required: false, varType: VarType::STRING)]
    #[ControllerParam3(name: 'name', required: true, varType: VarType::STRING)]
    public function folder_create(int $spaceId, string $name, string $path = ''): ApiResponse
    {
        $this->wfMedia->createDirectory($spaceId, $path, $name);
        return new ApiResponse(['created' => true]);
    }

    #[ControllerMethod(name: 'upload', path: 'upload', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'spaceId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'path', required: false, varType: VarType::STRING)]
    #[ControllerParam3(name: 'overwrite', required: false, varType: VarType::BOOL)]
    public function upload(int $spaceId, WebRequest $_request, string $path = '', bool $overwrite = false): ApiResponse
    {
        $this->wfMedia->uploadFile($spaceId, $path, $_request, $overwrite);
        return new ApiResponse(['uploaded' => true]);
    }

    #[ControllerMethod(name: 'file_read', path: 'file/read')]
    #[ControllerParam1(name: 'spaceId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'path', required: true, varType: VarType::STRING)]
    public function file_read(int $spaceId, string $path): ApiResponse
    {
        return new ApiResponse($this->wfMedia->readEditableFile($spaceId, $path));
    }

    #[SecurityDoNotCheckPatterns]
    #[ControllerMethod(name: 'file_create', path: 'file/create', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'spaceId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'path', required: false, varType: VarType::STRING)]
    #[ControllerParam3(name: 'name', required: true, varType: VarType::STRING)]
    #[ControllerParam4(name: 'content', required: true, varType: VarType::STRING)]
    public function file_create(int $spaceId, string $name, string $content, string $path = ''): ApiResponse
    {
        return new ApiResponse(['version' => $this->wfMedia->createEditableFile($spaceId, $path, $name, $content)]);
    }

    #[SecurityDoNotCheckPatterns]
    #[ControllerMethod(name: 'file_save', path: 'file/save', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'spaceId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'path', required: true, varType: VarType::STRING)]
    #[ControllerParam3(name: 'content', required: true, varType: VarType::STRING)]
    #[ControllerParam4(name: 'version', required: true, varType: VarType::STRING)]
    public function file_save(int $spaceId, string $path, string $content, string $version): ApiResponse
    {
        return new ApiResponse(['version' => $this->wfMedia->saveEditableFile($spaceId, $path, $content, $version)]);
    }

    #[ControllerMethod(name: 'rename', path: 'rename', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'spaceId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'path', required: true, varType: VarType::STRING)]
    #[ControllerParam3(name: 'name', required: true, varType: VarType::STRING)]
    public function rename(int $spaceId, string $path, string $name): ApiResponse
    {
        $this->wfMedia->rename($spaceId, $path, $name);
        return new ApiResponse(['renamed' => true]);
    }

    #[ControllerMethod(name: 'move', path: 'move', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'spaceId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'path', required: true, varType: VarType::STRING)]
    #[ControllerParam3(name: 'destinationPath', required: false, varType: VarType::STRING)]
    public function move(int $spaceId, string $path, string $destinationPath = ''): ApiResponse
    {
        $this->wfMedia->move($spaceId, $path, $destinationPath);
        return new ApiResponse(['moved' => true]);
    }

    #[ControllerMethod(name: 'delete', path: 'delete', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'spaceId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'path', required: true, varType: VarType::STRING)]
    public function delete(int $spaceId, string $path): ApiResponse
    {
        $this->wfMedia->delete($spaceId, $path);
        return new ApiResponse(['deleted' => true]);
    }
}
