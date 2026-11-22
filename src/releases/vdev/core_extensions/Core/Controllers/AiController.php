<?php

namespace YTassy\Tycms\CoreExts\Core\Controllers;

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
use YTassy\Tycms\Routing\Annotation\Doc\VarType;
use YTassy\Tycms\Routing\Annotation\NoTransaction;

#[Controller(name: 'api_ai_', isApi: true, path: 'api/ai')]
#[SecurityDoNotCheckPatterns]
final class AiController extends BaseController
{
    use TraitWF;

    #[ControllerMethod(name: 'capabilities', path: 'capabilities')]
    #[ControllerParam1(name: 'spaceId', required: true, varType: VarType::INT)]
    #[NoTransaction]
    public function capabilities(int $spaceId): ApiResponse
    {
        return new ApiResponse($this->wfAi->getCapabilities($spaceId));
    }

    #[ControllerMethod(name: 'llm', path: 'llm', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'spaceId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'useCase', required: true, varType: VarType::STRING)]
    #[ControllerParam3(name: 'input', required: true, varType: VarType::JSON)]
    #[NoTransaction]
    public function llm(int $spaceId, string $useCase, object $input): ApiResponse
    {
        if (!in_array($useCase, ['proofreading', 'design'], true)) {
            \YTassy\Tycms\Response\ExceptionResponse::throwWarning('Cas d’usage LLM invalide.');
        }
        return new ApiResponse($this->wfAi->run($spaceId, $useCase, $input));
    }

    #[ControllerMethod(name: 'decision', path: 'decision', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'spaceId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'input', required: true, varType: VarType::JSON)]
    #[NoTransaction]
    public function decision(int $spaceId, object $input): ApiResponse
    {
        return new ApiResponse($this->wfAi->run($spaceId, 'decision', $input));
    }

    #[ControllerMethod(name: 'image', path: 'image', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'spaceId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'input', required: true, varType: VarType::JSON)]
    #[NoTransaction]
    public function image(int $spaceId, object $input): ApiResponse
    {
        return new ApiResponse($this->wfAi->run($spaceId, 'image_generation', $input));
    }

    #[ControllerMethod(name: 'status', path: 'status', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'batchId', required: true, varType: VarType::STRING)]
    #[NoTransaction]
    public function status(string $batchId): ApiResponse
    {
        return new ApiResponse($this->wfAi->poll($batchId));
    }
}
