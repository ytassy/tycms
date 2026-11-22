<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Controllers;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseController;
use YTassy\Tycms\CoreExts\Core\WF\TraitWF;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Response\ApiResponse;
use YTassy\Tycms\Routing\Annotation\Doc\Controller;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerMethod;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerParam1;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerParam2;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerParam3;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerParam4;
use YTassy\Tycms\Routing\Annotation\Doc\VarType;

/**
 * Exécution d'un bouton de page par le composant public Vue.
 *
 * Le jeton HMAC est lié à la page, au nœud et au CSRF de session. Le jeton CSRF
 * est vérifié par le runner dans l'en-tête, puis réutilisé pour le HMAC.
 */
#[Controller(name: 'page_button', isApi: true, path: 'page/button')]
final class PageButtonApiController extends BaseController
{
    use TraitWF;

    #[ControllerMethod(name: 'run', path: '', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'page_id', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'node_id', required: true, varType: VarType::STRING)]
    #[ControllerParam3(name: 'locale', required: true, varType: VarType::STRING)]
    #[ControllerParam4(name: 'button_token', required: true, varType: VarType::STRING)]
    public function run(
        int $page_id,
        string $node_id,
        string $locale,
        string $button_token,
    ): ApiResponse {
        $result = $this->wfPageButton->click(
            $page_id,
            $node_id,
            $locale,
            (string) $this->request->header('X-CSRF-Token'),
            $button_token,
        );

        return new ApiResponse([
            'message' => $result['message'],
            'level' => $result['level'],
            'reload' => $result['reload'],
        ]);
    }
}
