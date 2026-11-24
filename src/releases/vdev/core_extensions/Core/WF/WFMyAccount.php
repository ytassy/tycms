<?php

namespace YTassy\Tycms\CoreExts\Core\WF;

use YTassy\Tycms\CoreExts\Core\Helper\TraitAllHelpers;
use YTassy\Tycms\CoreExts\Core\Security\LocalPasswordPolicy;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\WF\BaseWF;

class WFMyAccount extends BaseWF
{
    use TraitAllHelpers;
    use TraitWF;

    public function requireAccount(bool $lock = false): object
    {
        // Aucun ID provenant du client : toutes les opérations visent le propriétaire de la session.
        $id = $this->session->getUserId();
        $user = $id === null ? null : $this->coreMyAccountHelper->getAccount($id, $lock);

        // Un compte de service n'ouvre pas de session interactive : le refuser
        // ici couvre aussi la liaison SSO explicite et les intégrations OAuth.
        if ($user !== null && ($user->type ?? 'user') === 'service') {
            $user = null;
        }

        if ($user === null || (bool) $user->blocked) {
            ExceptionResponse::throwWarning('Connectez-vous pour accéder à votre compte.');
        }

        return $user;
    }

    public function read(): array
    {
        $user = $this->requireAccount();
        $links = $this->coreMyAccountHelper->getLinks((int) $user->id);
        $providers = $this->coreSsoProviderHelper->getLoginProviders($this->coreSpaceHelper->getCurrentSpace());

        // Projeter explicitement les seuls champs publics, jamais le modèle ou ses secrets.
        return [
            'first_name' => $user->first_name, 'last_name' => $user->last_name,
            'email' => $user->email, 'timezone' => $user->timezone,
            'has_local_password' => self::hasLocalPassword($user, $links),
            'password_change_allowed' => $this->passwordChangeAllowed($user, $links),
            'sso_links' => array_map(static fn ($link) => [
                'id' => (int) $link->id, 'provider_id' => (int) $link->provider_id, 'name' => $link->name,
            ], $links),
            'sso_providers' => array_map(static fn ($provider) => [
                'id' => $provider->id, 'name' => $provider->name,
            ], $providers),
            'integrations' => $this->wfOAuthIntegration->listForUser(),
        ];
    }

    public function save(mixed $data): array
    {
        $user = $this->requireAccount(true);
        $allowed = ['first_name', 'last_name', 'email', 'timezone'];
        if (!is_object($data) || array_diff(array_keys((array) $data), $allowed) !== []) {
            ExceptionResponse::throwWarning('Les informations du compte sont invalides.');
        }

        // Les réglages du composant sont seulement visuels ; les champs personnels restent accessibles.
        foreach ($allowed as $field) {
            if (property_exists($data, $field)) {
                if (!is_string($data->{$field})) {
                    ExceptionResponse::throwWarning('Les informations du compte sont invalides.');
                }
                $user->{$field} = trim($data->{$field});
            }
        }
        $user->email = mb_strtolower($user->email);
        if ((property_exists($data, 'first_name') && $user->first_name === '') || mb_strlen($user->first_name) > 100
            || (property_exists($data, 'last_name') && $user->last_name === '') || mb_strlen($user->last_name) > 100
            || strlen($user->email) > 100 || filter_var($user->email, FILTER_VALIDATE_EMAIL) === false
            || !in_array($user->timezone, timezone_identifiers_list(), true)) {
            ExceptionResponse::throwWarning('Les informations du compte sont invalides.');
        }
        if ($this->coreUserAdminHelper->emailExists($user->email, (int) $user->id)) {
            ExceptionResponse::throwWarning('Cette adresse e-mail est déjà utilisée.');
        }

        $this->coreMyAccountHelper->updateProfile(
            (int) $user->id, $user->first_name, $user->last_name, $user->email, $user->timezone,
        );

        return $this->read();
    }

    public function changePassword(string $current, string $password, string $confirmation): array
    {
        $user = $this->requireAccount(true);
        $links = $this->coreMyAccountHelper->getLinks((int) $user->id);
        if (!$this->passwordChangeAllowed($user, $links)) {
            ExceptionResponse::throwWarning('Le mot de passe local est désactivé par votre espace principal.');
        }
        if ($current === '' || strlen($current) > 4096 || !password_verify($current, $user->password)) {
            ExceptionResponse::throwWarning('Le mot de passe actuel est incorrect.');
        }
        if ($password !== $confirmation || !LocalPasswordPolicy::acceptsNewPassword($password)) {
            ExceptionResponse::throwWarning(LocalPasswordPolicy::MESSAGE . ' Les deux saisies doivent être identiques.');
        }

        $url = $this->coreUserHelper->getLoginUrl();
        $this->coreUserHelper->updatePassword((int) $user->id, $password);
        $this->sessionManager->deleteUserSessions((int) $user->id);

        return ['url' => $url];
    }

    public function unlink(int $linkId): array
    {
        $user = $this->requireAccount(true);
        $links = $this->coreMyAccountHelper->getLinks((int) $user->id);
        $owned = array_filter($links, static fn ($link) => (int) $link->id === $linkId);
        if ($owned === []) ExceptionResponse::throwWarning('Connexion SSO introuvable.');

        // Ne compter que les moyens encore utilisables dans l'espace courant.
        $providers = $this->coreSsoProviderHelper->getLoginProviders($this->coreSpaceHelper->getCurrentSpace());
        $available = array_map(static fn ($provider) => $provider->id, $providers);
        $remaining = array_filter($links, static fn ($link) => (int) $link->id !== $linkId
            && in_array((int) $link->provider_id, $available, true));
        if (!self::hasLocalPassword($user, $links) && $remaining === []) {
            ExceptionResponse::throwWarning('Conservez un moyen de connexion : ajoutez un autre lien SSO ou définissez un mot de passe local.');
        }

        $this->coreUserAdminHelper->removeSsoLink((int) $user->id, $linkId);

        return $this->read();
    }

    /** Appelé après vérification OIDC, dans la transaction du retour SSO. */
    public function linkIdentity(int $expectedUserId, int $providerId, string $subject, array $data): void
    {
        $user = $this->requireAccount(true);
        if ((int) $user->id !== $expectedUserId) ExceptionResponse::throwWarning('La session utilisateur a changé.');

        // Recontrôler le fournisseur après l'échange réseau OIDC, avant toute écriture.
        $this->coreSpaceHelper->load();
        $provider = $this->coreSsoProviderHelper->getLoginProvider($this->coreSpaceHelper->getCurrentSpace(), $providerId);
        if ($provider === null || $subject === '' || strlen($subject) > 255) {
            ExceptionResponse::throwWarning('Connexion SSO indisponible.');
        }

        $identity = $this->coreMyAccountHelper->findIdentity($providerId, $subject);
        if ($identity !== null) {
            if ((int) $identity->user_id !== $expectedUserId) {
                ExceptionResponse::throwWarning('Ce compte SSO est déjà associé à un autre utilisateur.');
            }
            return;
        }

        // Une liaison explicite préserve le mot de passe existant, contrairement au rattachement automatique.
        $this->coreMyAccountHelper->markLocalPassword($expectedUserId,
            self::hasLocalPassword($user, $this->coreMyAccountHelper->getLinks($expectedUserId)));
        $this->coreMyAccountHelper->addIdentity($expectedUserId, $providerId, $subject,
            $this->coreCryptHelper->encrypt(json_encode($data, JSON_THROW_ON_ERROR), 'link:v1'));
    }

    public static function hasLocalPassword(object $user, array $links): bool
    {
        // Un marqueur ne rend pas utilisable un hash absent ou invalide.
        if (!is_string($user->password ?? null) || password_get_info($user->password)['algo'] === null) {
            return false;
        }

        $params = is_string($user->params) ? json_decode($user->params, true) : (array) $user->params;

        // Sans marqueur, un ancien compte lié au SSO est traité prudemment comme SSO seul.
        return isset($params['local_password_enabled'])
            ? in_array($params['local_password_enabled'], [true, 1], true) : $links === [];
    }

    private function passwordChangeAllowed(object $user, array $links): bool
    {
        if ($links === []) return true;
        $owner = $this->coreSpaceHelper->getSpace((int) $user->space_id);

        return in_array(((array) ($owner?->params ?? []))['sso_allow_reset_local_password'] ?? false, [true, 1], true);
    }
}
