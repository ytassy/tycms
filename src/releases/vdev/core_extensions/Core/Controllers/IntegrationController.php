<?php

namespace YTassy\Tycms\CoreExts\Core\Controllers;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseController;
use YTassy\Tycms\CoreExts\Core\WF\TraitWF;
use YTassy\Tycms\Request\Request;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Response\RedirectResponse;
use YTassy\Tycms\Routing\Annotation\Doc\{Controller, ControllerMethod};
use YTassy\Tycms\Routing\Annotation\NoTransaction;

#[Controller(name: 'Integration', isApi: false, path: 'integrations')]
final class IntegrationController extends BaseController
{
    use TraitWF;

    // Cette URI reste stable pour les clients OAuth déjà configurés chez les fournisseurs.
    #[ControllerMethod(name: 'Callback', path: 'oauth/callback')]
    #[NoTransaction]
    public function callback(Request $_request): RedirectResponse
    {
        try {
            return new RedirectResponse($this->wfOAuthIntegration->finish($_request));
        } catch (\Throwable $error) {
            ExceptionResponse::throwWarning('Autorisation OAuth impossible. Réessayez.', $error);
        }
    }
}
