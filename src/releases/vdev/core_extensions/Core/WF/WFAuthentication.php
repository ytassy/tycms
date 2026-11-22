<?php

namespace YTassy\Tycms\CoreExts\Core\WF;

use YTassy\Tycms\CoreExts\Core\Helper\TraitAllHelpers;
use YTassy\Tycms\CoreExts\Core\Models\Space;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\WF\BaseWF;

/** Contrôle serveur du composant publié, de son Space et des moyens d'authentification. */
class WFAuthentication extends BaseWF
{
    use TraitAllHelpers;
    use TraitWF;

    public function authorize(string $token, string $method, bool $acceptedTerms, ?Space $space = null): object
    {
        $space ??= $this->coreSpaceHelper->getCurrentSpace();
        $scope = $this->coreAuthenticationHelper->readToken($token);
        if ($scope === null || $scope->space_id !== $space->id) {
            ExceptionResponse::throwWarning('Ce formulaire a expiré ou est invalide. Rechargez la page.');
        }
        if ($scope->kind === 'fallback') return $scope;

        $configuration = $this->coreAuthenticationHelper->configuration($space, $scope->kind, $scope->node_id, $scope->locale, $scope->page_id);
        if ($configuration === null || $configuration['page_id'] !== $scope->page_id
            || !hash_equals($configuration['digest'], $scope->digest)) {
            ExceptionResponse::throwWarning('La configuration du formulaire a changé. Rechargez la page.');
        }
        $settings = $configuration['settings'];
        if (($settings->{$method . '_enabled'} ?? false) !== true) {
            ExceptionResponse::throwWarning('Ce moyen d’authentification est désactivé.');
        }
        if ($scope->kind === 'register') {
            if (!$this->coreAuthenticationHelper->registrationEnabled($space)) {
                ExceptionResponse::throwWarning('Les inscriptions sont indisponibles dans cet espace.');
            }
            if (($settings->terms_enabled ?? false) === true && !$acceptedTerms) {
                ExceptionResponse::throwWarning('Vous devez accepter les conditions générales d’utilisation.');
            }
        }
        return $scope;
    }

    public function login(string $token, string $email, string $password): array
    {
        $scope = $this->authorize($token, 'form', false);
        if (!in_array($scope->kind, ['login', 'fallback'], true) || $this->session->getUserId() !== null) {
            ExceptionResponse::throwWarning('La connexion est indisponible.');
        }
        $this->wfUser->loginUser($email, $password);
        return ['url' => $this->coreAuthenticationHelper->destination()];
    }

    public function register(string $token, mixed $data, bool $acceptedTerms): array
    {
        $scope = $this->authorize($token, 'form', $acceptedTerms);
        if ($scope->kind !== 'register' || !is_object($data)) ExceptionResponse::throwWarning('Formulaire d’inscription invalide.');
        foreach (['first_name', 'last_name', 'email', 'password', 'password_confirmation'] as $key) {
            if (!is_string($data->{$key} ?? null)) ExceptionResponse::throwWarning('Informations du compte invalides.');
        }
        $this->wfUser->registerUser($data->first_name, $data->last_name, $data->email, $data->password, $data->password_confirmation);
        return ['url' => $this->coreAuthenticationHelper->destination()];
    }

    public function join(string $token, bool $acceptedTerms): array
    {
        $scope = $this->authorize($token, 'form', $acceptedTerms);
        if ($scope->kind !== 'register') ExceptionResponse::throwWarning('Formulaire d’adhésion invalide.');
        $this->wfUser->joinCurrentSpace();
        return ['url' => $this->coreAuthenticationHelper->destination()];
    }
}
