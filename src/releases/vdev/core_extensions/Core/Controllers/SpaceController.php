<?php

namespace YTassy\Tycms\CoreExts\Core\Controllers;

use YTassy\Tycms\CoreExts\Core\WF\TraitWF;
use YTassy\Tycms\Request\Request;
use YTassy\Tycms\Response\RedirectResponse;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Routing\Annotation\Doc\Controller;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerMethod;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerParam1;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerParam2;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseController;
use YTassy\Tycms\CoreExts\Core\Views\SpaceChoose;
use YTassy\Tycms\Routing\Annotation\Doc\VarType;

#[Controller(name: "Space", isApi: false, path: "space")]
class SpaceController extends BaseController
{
    use TraitWF;
    
    #[ControllerMethod(name: "Choose", path: "choose")]
    public function choose()
    {
        /*if ($this->session->getSpaceId() != null)
        {
            return new RedirectResponse("/");
        }*/

        return $this->getViewResponse(new SpaceChoose($this));
    }

    #[ControllerMethod(name: "ChoosePost", path: "choose", methods:['POST'])]
    #[ControllerParam1(name:'id', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'csrf_token', required: true, varType: VarType::STRING)]
    public function choosePost(string $id, Request $_request, string $csrf_token)
    {
        // Refuser avant toute lecture du helper, qui peut lui-même sélectionner un Space par défaut.
        if (!$this->session->verifyCsrfToken($csrf_token)) {
            $this->logWarning('Choix de Space refusé : jeton CSRF invalide.', [],
                'SPACE_CHOICE_CSRF_INVALID', 'security.http');
            ExceptionResponse::throwWarning('Jeton de formulaire invalide. Rechargez la page.');
        }

        $space = $this->coreSpaceHelper->getSpace($id);
        if ($space != null) $this->session->setSpaceId($space->id);

        return new RedirectResponse("/");
    }

    

}
