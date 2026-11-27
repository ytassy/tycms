<?php

namespace YTassy\Tycms\CoreExts\Core\WF;

use YTassy\Tycms\CoreExts\Core\Helper\TraitAllHelpers;
use YTassy\Tycms\CoreExts\Core\Security\LocalPasswordPolicy;
use YTassy\Tycms\Logging\LogEventException;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\WF\BaseWF;

class WFPasswordReset extends BaseWF
{
    use TraitAllHelpers;

    public function requestReset(string $email): void
    {
        $email = mb_strtolower(trim($email));
        if (strlen($email) > 100 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        // Le compte est global : aucune adhésion à l'espace de la demande n'est exigée.
        $user = $this->corePasswordResetHelper->findByEmailForUpdate($email);
        if (!$this->isEligible($user)) {
            return;
        }

        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        if (!$this->corePasswordResetHelper->storeRequest((int) $user->id, $hash)) {
            return;
        }

        $space = null;
        try {
            // Le domaine vient exclusivement de la configuration, jamais de l'en-tête Host.
            $space = $this->coreSpaceHelper->getCurrentSpace();
            $url = $this->coreMailHelper->siteUrlForEmail($space)
                . $this->url('PasswordResetForm', ['token' => $token]);
            $locale = $this->corePublicLocaleHelper->locale();
            $active = array_map(static fn ($language) => $language->locale,
                $this->coreSpaceLanguageHelper->listActiveForSpace($space->id));
            if (!in_array($locale, $active, true)) {
                $locale = $this->coreSpaceLanguageHelper->getDefaultLocale($space->id);
            }

            if ($this->coreMailHelper->sendTemplate(
                'plg_core__password_reset', $user->email, ['reset_url' => $url], $space, $locale,
                secrets: [$url],
            )) {
                return;
            }

            // Inclut l'envoi désactivé : ne jamais associer l'adresse ou le jeton au journal.
            $this->logWarning('L’e-mail de récupération n’a pas été envoyé.', ['space_id' => $space->id],
                'PASSWORD_RESET_MAIL_NOT_SENT', 'mail');
        } catch (\Throwable $exception) {
            // Garder la réponse publique générique tout en conservant les causes techniques sûres.
            $this->logException(new LogEventException('PASSWORD_RESET_MAIL_FAILED',
                'La préparation ou l’envoi de l’e-mail de récupération a échoué.', 'mail',
                ['space_id' => $space?->id], 'ERROR', $exception));
        }

        $this->corePasswordResetHelper->clearToken((int) $user->id, $hash);
    }

    public function isValidToken(string $token): bool
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            return false;
        }

        $user = $this->corePasswordResetHelper->findByToken(hash('sha256', $token));

        return $this->isEligible($user) && (bool) $user->token_valid;
    }

    public function resetPassword(string $token, string $password, string $confirmation): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            ExceptionResponse::throwWarning('Ce lien est invalide ou expiré. Faites une nouvelle demande.');
        }

        // Le verrou sérialise la consommation, les renvois et les changements administratifs.
        $hash = hash('sha256', $token);
        $user = $this->corePasswordResetHelper->findByToken($hash, true);
        if (!$this->isEligible($user) || !(bool) $user->token_valid) {
            ExceptionResponse::throwWarning('Ce lien est invalide ou expiré. Faites une nouvelle demande.');
        }
        if ($password !== $confirmation || !LocalPasswordPolicy::acceptsNewPassword($password)) {
            ExceptionResponse::throwWarning(LocalPasswordPolicy::MESSAGE . ' Les deux saisies doivent être identiques.');
        }

        if (!$this->corePasswordResetHelper->consume((int) $user->id, $hash, password_hash($password, PASSWORD_DEFAULT))) {
            ExceptionResponse::throwWarning('Ce lien est invalide ou expiré. Faites une nouvelle demande.');
        }

        // La révocation est dans la même transaction ; aucune connexion automatique n'est ouverte.
        $this->sessionManager->deleteUserSessions((int) $user->id);
    }

    private function isEligible(?object $user): bool
    {
        if ($user === null || (bool) $user->blocked) {
            return false;
        }

        // Un compte de service n'ouvre aucune connexion interactive : il ne
        // peut ni demander ni consommer une réinitialisation de mot de passe.
        if (($user->type ?? 'user') === 'service') {
            return false;
        }

        if (!(bool) $user->has_sso_link) {
            return true;
        }

        // Un autre espace ne peut pas contourner la politique du propriétaire du compte.
        $owner = $this->coreSpaceHelper->getSpace((int) $user->space_id);
        $params = (array) ($owner?->params ?? []);

        return in_array($params['sso_allow_reset_local_password'] ?? false, [true, 1], true);
    }
}
