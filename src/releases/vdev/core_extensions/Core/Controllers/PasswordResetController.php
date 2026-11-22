<?php

namespace YTassy\Tycms\CoreExts\Core\Controllers;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseController;
use YTassy\Tycms\CoreExts\Core\Views\ForgotPassword;
use YTassy\Tycms\CoreExts\Core\Views\ResetPassword;
use YTassy\Tycms\CoreExts\Core\WF\TraitWF;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Response\HtmlResponse;
use YTassy\Tycms\Response\RedirectResponse;
use YTassy\Tycms\Routing\Annotation\Doc\Controller;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerMethod;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerParam1;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerParam2;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerParam3;
use YTassy\Tycms\Routing\Annotation\Doc\VarType;

#[Controller(name: 'PasswordReset', path: '', isApi: false)]
class PasswordResetController extends BaseController
{
    use TraitWF;

    #[ControllerMethod(name: 'RequestForm', path: 'forgot-password')]
    public function requestForm(): HtmlResponse
    {
        return $this->protectPage($this->getViewResponse(new ForgotPassword($this)));
    }

    #[ControllerMethod(name: 'Request', path: 'forgot-password', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'email', required: true, varType: VarType::STRING)]
    #[ControllerParam2(name: 'csrf_token', required: true, varType: VarType::STRING)]
    public function requestReset(string $email, string $csrf_token): RedirectResponse
    {
        $this->verifyCsrf($csrf_token);
        $this->wfPasswordReset->requestReset($email);

        // Réponse identique pour une adresse inconnue, bloquée, limitée ou sans envoi possible.
        $this->pendingMessageQueue->success(
            'Si ce compte permet la récupération, un e-mail contenant un lien vous sera envoyé. '
                . 'Si vous venez de faire une demande, patientez avant de réessayer.',
        );

        return new RedirectResponse($this->url('PasswordResetRequestForm'));
    }

    #[ControllerMethod(name: 'Form', path: 'reset-password/{token}')]
    public function resetForm(string $token): HtmlResponse
    {
        return $this->protectPage($this->getViewResponse(new ResetPassword(
            $this, $token, $this->wfPasswordReset->isValidToken($token),
        )));
    }

    #[ControllerMethod(name: 'Save', path: 'reset-password/{token}', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'password', required: true, varType: VarType::STRING)]
    #[ControllerParam2(name: 'password_confirmation', required: true, varType: VarType::STRING)]
    #[ControllerParam3(name: 'csrf_token', required: true, varType: VarType::STRING)]
    public function resetPassword(
        string $token, string $password, string $password_confirmation, string $csrf_token,
    ): RedirectResponse {
        $this->verifyCsrf($csrf_token);
        $spaceId = $this->coreSpaceHelper->getCurrentSpace()->id;
        $loginUrl = $this->coreUserHelper->getLoginUrl();
        $this->wfPasswordReset->resetPassword($token, $password, $password_confirmation);

        // Si la session courante a été révoquée, recréer une session invitée pour la confirmation.
        $this->session->initialize();
        $this->session->setSpaceId($spaceId);
        $this->pendingMessageQueue->success('Votre mot de passe a été modifié. Vous pouvez vous connecter.');

        return new RedirectResponse($loginUrl);
    }

    private function verifyCsrf(string $token): void
    {
        if (!$this->session->verifyCsrfToken($token)) {
            ExceptionResponse::throwWarning('Jeton de formulaire invalide. Rechargez la page.');
        }
    }

    private function protectPage(HtmlResponse $response): HtmlResponse
    {
        // Le lien secret ne doit pas partir dans un Referer ni être indexé.
        $response->httpHeaders['Referrer-Policy'] = 'no-referrer';
        $response->httpHeaders['X-Robots-Tag'] = 'noindex, nofollow';

        return $response;
    }
}
