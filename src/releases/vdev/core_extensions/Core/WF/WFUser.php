<?php

namespace YTassy\Tycms\CoreExts\Core\WF;

use YTassy\Tycms\CoreExts\Core\Helper\TraitAllHelpers;
use YTassy\Tycms\CoreExts\Core\Security\LocalPasswordPolicy;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\WF\BaseWF;

class WFUser extends BaseWF
{
    use TraitAllHelpers;
    use TraitUserEnrollment;

    public function registrationEnabled(): bool
    {
        return $this->coreAuthenticationHelper->registrationEnabled($this->coreSpaceHelper->getCurrentSpace());
    }

    public function isRegisteredInCurrentSpace(): bool
    {
        $userId = $this->session->getUserId();

        return $userId !== null && $this->coreAclHelper->hasGlobalPermission(
            Permissions::MEMBER, $this->coreSpaceHelper->getCurrentSpace()
        );
    }

    public function registerUser(
        string $firstName, string $lastName, string $email, string $password, string $passwordConfirmation
    ): int {
        if ($this->session->getUserId() !== null || !$this->registrationEnabled()) {
            ExceptionResponse::throwWarning('L’inscription est indisponible dans cet espace.');
        }

        // Nettoyer la saisie avant de créer le compte et de l'inscrire dans l'espace courant.
        $firstName = trim($firstName);
        $lastName = trim($lastName);
        $email = mb_strtolower(trim($email));
        if ($firstName === '' || mb_strlen($firstName) > 100
            || $lastName === '' || mb_strlen($lastName) > 100
            || strlen($email) > 100 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            ExceptionResponse::throwWarning('Les informations du compte sont invalides.');
        }

        if ($password !== $passwordConfirmation || !LocalPasswordPolicy::acceptsNewPassword($password)) {
            ExceptionResponse::throwWarning(LocalPasswordPolicy::MESSAGE . ' Les deux saisies doivent être identiques.');
        }

        // L'e-mail est unique pour tous les espaces : un compte existant doit d'abord se connecter.
        if ($this->coreUserRegistrationHelper->getUserByEmailForUpdate($email) !== null) {
            ExceptionResponse::throwWarning('Un compte utilise déjà cette adresse. Connectez-vous pour rejoindre cet espace.');
        }

        $space = $this->coreSpaceHelper->getCurrentSpace();
        $principalSpace = $this->coreUserRegistrationHelper->getRegistrationTargetSpace($space);
        $userId = $this->coreUserRegistrationHelper->createUser(
            $principalSpace, $firstName, $lastName, $email, password_hash($password, PASSWORD_DEFAULT)
        );
        $this->enrollUserInSpace($userId, $space);

        $this->session->rotateId();
        $this->session->setUser($userId);
        $this->logger->setContext(['auth_method' => 'local', 'first_name' => $firstName]);

        // L'envoi est facultatif pour la création : un échec SMTP laisse le compte utilisable.
        $this->coreUserWelcomeMailHelper->sendLocalRegistration(
            $space, $email, $firstName, $this->corePublicLocaleHelper->locale(),
        );

        return $userId;
    }

    public function joinCurrentSpace(): void
    {
        $userId = $this->session->getUserId();
        if ($userId === null) {
            ExceptionResponse::throwWarning('Connectez-vous pour rejoindre cet espace.');
        }

        $space = $this->coreSpaceHelper->getCurrentSpace();
        $user = $this->coreUserRegistrationHelper->getUserForUpdate($userId);
        if ($user === null || (int) $user->blocked !== 0) {
            ExceptionResponse::throwWarning('Ce compte ne peut pas rejoindre cet espace.');
        }

        if ($this->coreAclHelper->hasGlobalPermission(Permissions::MEMBER, $space)) {
            return;
        }
        if (!$this->registrationEnabled()) {
            ExceptionResponse::throwWarning('Les inscriptions sont fermées dans cet espace.');
        }

        // Réutiliser le compte existant et lui attribuer les rôles du nouvel espace.
        $this->enrollUserInSpace($userId, $space);
    }
    
    public function loginUser(string $email, string $password)
    {

        if ($this->session->getUserId() !== null || strlen($email) > 100 || strlen($password) > 4096) {
            ExceptionResponse::throwWarning('Login ou mot de passe invalide');
        }
        // Sérialiser la vérification, le rehash et le marqueur avec les autres changements du compte.
        $user = $this->coreUserHelper->getByEmail(mb_strtolower(trim($email)), lock: true);

        if ($user == null || $user->blocked || password_verify($password, $user->password) == false) {
            ExceptionResponse::throwWarning('Login ou mot de passe invalide');
        }

        // Vérifie si le hash a été généré avec des paramètres obsolètes
        if (password_needs_rehash($user->password, PASSWORD_DEFAULT)) {
            $this->coreUserHelper->updatePassword($user->id, $password);
            $user = $this->coreUserHelper->getByEmail(mb_strtolower(trim($email)));
        }

        // Une connexion locale réussie prouve aussi qu'un ancien compte SSO connaît son mot de passe.
        $this->coreMyAccountHelper->markLocalPassword($user->id, true);

        $this->session->rotateId();
        $this->session->setUser($user->id);
        $this->logger->setContext(['auth_method' => 'local', 'first_name' => $user->first_name]);

        return $user;
    }
}
