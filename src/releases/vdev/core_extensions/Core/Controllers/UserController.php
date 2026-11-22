<?php

namespace YTassy\Tycms\CoreExts\Core\Controllers;

use YTassy\Tycms\CoreExts\Core\Views\Login;
use YTassy\Tycms\CoreExts\Core\WF\TraitWF;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseController;
use YTassy\Tycms\Request\{Request, RequestMethod};
use YTassy\Tycms\Response\{RedirectResponse, ExceptionResponse};
use YTassy\Tycms\Routing\Annotation\Doc\{Controller, ControllerMethod, ControllerParam1, ControllerParam2, ControllerParam3, ControllerParam4, VarType};
use YTassy\Tycms\Routing\Annotation\NoTransaction;

#[Controller(name: 'User', isApi: false, path: '')]
class UserController extends BaseController
{
    use TraitWF;
    use \YTassy\Tycms\CoreExts\Core\Helper\TraitCoreAdditionalHelpers;

    #[ControllerMethod(name: 'FallbackLogin', path: 'login-fb')]
    public function login()
    {
        if ($this->session->getUserId() !== null) {
            return new RedirectResponse($this->coreAuthenticationHelper->destination());
        }
        $response = $this->getViewResponse(new Login($this));
        $response->httpHeaders['Cache-Control'] = 'private, no-store';
        $response->httpHeaders['X-Robots-Tag'] = 'noindex, nofollow';
        return $response;
    }

    public function logout()
    {
        $this->session->setUser(null);
        $this->session->destroy();
        return new RedirectResponse($this->corePublicLocaleHelper->publicPath('/') ?? '/');
    }

    #[ControllerMethod(name: 'SsoStart', path: 'sso/start', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'providerId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'token', required: true, varType: VarType::STRING)]
    #[ControllerParam3(name: 'acceptedTerms', required: true, varType: VarType::BOOL)]
    #[ControllerParam4(name: 'csrf_token', required: true, varType: VarType::STRING)]
    public function ssoStart(int $providerId, string $token, bool $acceptedTerms, string $csrf_token): RedirectResponse
    {
        if (!$this->session->verifyCsrfToken($csrf_token)) {
            ExceptionResponse::throwWarning('Jeton de formulaire invalide. Rechargez la page.');
        }
        try {
            return RedirectResponse::external($this->wfSso->startLogin($providerId, $token, $acceptedTerms));
        } catch (ExceptionResponse $response) {
            throw $response;
        } catch (\Throwable $error) {
            ExceptionResponse::throwWarning('Ce fournisseur SSO est indisponible.', $error);
        }
    }

    #[ControllerMethod(name: 'SsoCallback', path: 'sso/callback')]
    #[ControllerParam1(name: 'state')]
    #[ControllerParam2(name: 'code')]
    #[ControllerParam3(name: 'error')]
    #[NoTransaction]
    public function ssoCallback(Request $_request): RedirectResponse
    {
        try {
            $this->wfSso->finishLogin($_request);
            return new RedirectResponse($this->wfSso->getLinkReturnPath() ?? $this->coreAuthenticationHelper->destination());
        } catch (ExceptionResponse $response) {
            throw $response;
        } catch (\Throwable $error) {
            ExceptionResponse::throwWarning('Connexion SSO impossible. Vérifiez que votre compte est autorisé et réessayez.', $error);
        }
    }
}
