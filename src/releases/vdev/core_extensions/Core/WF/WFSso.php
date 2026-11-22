<?php

namespace YTassy\Tycms\CoreExts\Core\WF;

use YTassy\Tycms\Logging\LogEventException;
use YTassy\Tycms\CoreExts\Core\Helper\TraitAllHelpers;
use YTassy\Tycms\CoreExts\Core\Helper\AccountReturnPath;
use YTassy\Tycms\CoreExts\Core\Models\SsoProvider;
use YTassy\Tycms\CoreExts\Core\Models\Space;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\CoreExts\Core\Sso\GoogleOidc;
use YTassy\Tycms\CoreExts\Core\Sso\GenericOAuth;
use YTassy\Tycms\CoreExts\Core\Sso\SsoAutoLinkPolicy;
use YTassy\Tycms\Request\Request;
use YTassy\Tycms\WF\BaseWF;

final class WFSso extends BaseWF
{
    use TraitAllHelpers;
    use TraitUserEnrollment;
    use TraitWF;

    private const SESSION_KEY = 'sso_login_flows';
    private const FLOW_LIFETIME = 600;

    private ?string $newRegistrationEmail = null;
    private ?string $linkReturnPath = null;

    public function startLogin(int $providerId, string $authorization, bool $acceptedTerms): string
    {
        $space = $this->coreSpaceHelper->getCurrentSpace();
        $this->wfAuthentication->authorize($authorization, 'sso', $acceptedTerms, $space);
        $provider = $this->coreSsoProviderHelper->getLoginProvider($space, $providerId);
        if ($provider === null || $this->session->getUserId() !== null) {
            throw new LogEventException('SSO_PROVIDER_UNAVAILABLE', 'Fournisseur de connexion indisponible.', 'sso');
        }
        $state = bin2hex(random_bytes(32));
        $nonce = bin2hex(random_bytes(32));
        $verifier = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
        $client = $this->client($provider);
        $url = $client->authorizationUrl($state, $nonce, $verifier);

        $flows = $this->currentFlows();
        if (count($flows) >= 5) array_shift($flows);
        $flows[$state] = [
            'provider_id' => $providerId,
            'space_id' => $space->id,
            'authorization' => $authorization,
            'accepted_terms' => $acceptedTerms,
            'nonce' => $nonce,
            'verifier' => $verifier,
            'created' => time(),
        ];
        $this->session->set(self::SESSION_KEY, $flows);
        return $url;
    }

    public function startLink(int $providerId, string $returnPath): string
    {
        $user = $this->wfMyAccount->requireAccount();
        $space = $this->coreSpaceHelper->getCurrentSpace();
        $returnPath = AccountReturnPath::validate($returnPath);
        $provider = $this->coreSsoProviderHelper->getLoginProvider($space, $providerId);
        if ($provider === null) throw new LogEventException('SSO_PROVIDER_UNAVAILABLE', 'Fournisseur SSO indisponible.', 'sso');

        // Le flux explicite est lié à l'utilisateur et ne pourra jamais ouvrir une autre session.
        $state = bin2hex(random_bytes(32));
        $nonce = bin2hex(random_bytes(32));
        $verifier = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
        $url = $this->client($provider)->authorizationUrl($state, $nonce, $verifier);
        $flows = $this->currentFlows();
        if (count($flows) >= 5) array_shift($flows);
        $flows[$state] = [
            'purpose' => 'link', 'user_id' => (int) $user->id, 'space_id' => $space->id,
            'provider_id' => $providerId, 'return_path' => $returnPath,
            'nonce' => $nonce, 'verifier' => $verifier, 'created' => time(),
        ];
        $this->session->set(self::SESSION_KEY, $flows);
        return $url;
    }

    public function getLinkReturnPath(): ?string { return $this->linkReturnPath; }

    public function finishLogin(Request $request): int
    {
        $this->newRegistrationEmail = null;
        $this->linkReturnPath = null;
        $state = $request->query('state');
        $flows = $this->currentFlows();
        $flow = is_string($state) ? ($flows[$state] ?? null) : null;
        if (!is_array($flow)) {
            throw new LogEventException('SSO_STATE_EXPIRED', 'État de connexion SSO absent ou expiré.', 'sso');
        }
        unset($flows[$state]);
        $this->session->set(self::SESSION_KEY, $flows);

        $this->logger->setContext(['space_id' => $flow['space_id'] ?? null, 'provider_id' => $flow['provider_id'] ?? null]);

        $linking = ($flow['purpose'] ?? null) === 'link';
        if ($linking) {
            if ($this->session->getUserId() !== ($flow['user_id'] ?? null)
                || !is_int($flow['user_id'] ?? null)
                || $this->coreSpaceHelper->getCurrentSpace()->id !== ($flow['space_id'] ?? null)) {
                throw new LogEventException('SSO_SESSION_CHANGED', 'La session utilisateur ou l’espace a changé.', 'sso');
            }
            $this->wfMyAccount->requireAccount();
        } elseif ($this->session->getUserId() !== null) {
            throw new LogEventException('SSO_SESSION_EXISTS', 'Une session utilisateur existe déjà.', 'sso');
        }

        if ($request->query('error') !== null || !is_int($flow['space_id'] ?? null)
            || !is_int($flow['provider_id'] ?? null)
            || !is_string($flow['nonce'] ?? null)
            || !is_string($flow['verifier'] ?? null)) {
            throw new LogEventException('SSO_REFUSED', 'Connexion OIDC refusée ou invalide.', 'sso');
        }
        $code = $request->query('code');
        if (!is_string($code) || $code === '') {
            throw new LogEventException('SSO_CODE_MISSING', 'Code de connexion OIDC absent.', 'sso');
        }
        $space = $this->coreSpaceHelper->getSpace($flow['space_id']);
        if ($space === null) throw new LogEventException('SSO_SPACE_UNAVAILABLE', 'Espace SSO introuvable.', 'sso');
        $allowRegistration = false;
        if (!$linking) {
            if (!is_string($flow['authorization'] ?? null) || !is_bool($flow['accepted_terms'] ?? null)) {
                throw new LogEventException('SSO_FORM_MISSING', 'Autorisation du formulaire SSO absente.', 'sso');
            }

            // La configuration et les CGU sont recontrôlées après le retour du fournisseur.
            $scope = $this->wfAuthentication->authorize($flow['authorization'], 'sso', $flow['accepted_terms'], $space);
            $allowRegistration = $scope->kind === 'register';
        }
        $provider = $this->coreSsoProviderHelper->getLoginProvider($space, $flow['provider_id']);
        if ($provider === null) throw new LogEventException('SSO_PROVIDER_DISABLED', 'Fournisseur SSO désactivé.', 'sso');

        $claims = $this->client($provider)->identityFromCode($code, $flow['verifier'], $flow['nonce']);
        $mapping = json_decode($provider->mapping, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($mapping)) throw new LogEventException('SSO_MAPPING_INVALID', 'Mapping SSO invalide.', 'sso');
        $data = ['claims' => $claims];
        $subject = self::mapped($data, $mapping['subject'] ?? null);
        if (!is_string($subject) || $subject === '' || strlen($subject) > 255) {
            throw new LogEventException('SSO_SUBJECT_INVALID', 'Identifiant SSO externe invalide.', 'sso');
        }

        // Cette route est sans transaction automatique : l'état doit rester consommé même si le fournisseur échoue.
        $this->db->execute('START TRANSACTION');
        try {
            if ($linking) {
                $userId = $flow['user_id'];
                $this->wfMyAccount->linkIdentity($userId, $provider->id, $subject, $data);
            } else {
                $userId = $this->resolveUser($provider, $space, $subject, $data, $mapping, $allowRegistration);
            }
            $this->db->execute('COMMIT');
        } catch (\Throwable $error) {
            $this->db->execute('ROLLBACK');
            throw $error;
        }

        if ($linking) {
            $this->linkReturnPath = AccountReturnPath::validate($flow['return_path']);
            return $userId;
        }

        $this->session->rotateId();
        $this->session->setSpaceId($space->id);
        $this->session->setUser($userId);
        $this->logger->setContext(['auth_method' => 'sso']);

        // Seule une première création reçoit le bienvenue SSO, jamais une simple adhésion.
        if ($this->newRegistrationEmail !== null) {
            $this->coreUserWelcomeMailHelper->sendSsoRegistration(
                $space, $this->newRegistrationEmail, $this->corePublicLocaleHelper->locale(),
            );
        }

        return $userId;
    }

    /** @param array<string,mixed> $data @param array<string,mixed> $mapping */
    private function resolveUser(SsoProvider $provider, Space $space, string $subject, array $data, array $mapping, bool $allowRegistration = false): int
    {
        $link = $this->db->selectOneOrNull(
            'SELECT * FROM sso_links WHERE provider_id = % AND external_subject = % FOR UPDATE',
            [(int) $provider->id, $subject]
        );
        $encryptedData = $this->coreCryptHelper->encrypt(json_encode($data, JSON_THROW_ON_ERROR), 'link:v1');
        if ($link !== null) {
            $user = $this->coreUserRegistrationHelper->getUserForUpdate((int) $link->user_id);
            $this->ensureEligible($user, $space, $allowRegistration);
            $this->db->execute(
                'UPDATE sso_links SET `data` = %, `data_key_version` = 1, `last_used` = current_timestamp() WHERE id = %',
                [$encryptedData, (int) $link->id]
            );
            return (int) $user->id;
        }

        $email = self::mapped($data, $mapping['email'] ?? null);
        if (!is_string($email) || strlen($email) > 100
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new LogEventException('SSO_EMAIL_INVALID', 'Adresse e-mail SSO absente ou invalide.', 'sso');
        }
        $emailVerified = self::mapped($data, $mapping['email_verified'] ?? null);
        $user = $this->coreUserRegistrationHelper->getUserByEmailForUpdate($email);
        $params = is_object($space->params) ? (array) $space->params : $space->params;
        $params = is_array($params) ? $params : [];
        $password = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
        if ($user !== null) {
            if ((int) $provider->auto_link_account !== 1
                || !SsoAutoLinkPolicy::allows($params['sso_auto_link_existing_account'] ?? 0, $emailVerified)) {
                throw new LogEventException('SSO_AUTO_LINK_DENIED', 'Ce compte doit être lié explicitement au fournisseur SSO.', 'sso');
            }

            // Un compte encore étranger à l'espace ne peut être rattaché par sa seule adresse
            // que si le fournisseur atteste l'avoir vérifiée, même avec le mode de liaison permissif.
            if (!$this->coreAclHelper->userHasGlobalPermission((int) $user->id, Permissions::MEMBER, $space)
                && $emailVerified !== true) {
                throw new LogEventException('SSO_EMAIL_UNVERIFIED', 'Adresse e-mail SSO non vérifiée pour rejoindre cet espace.', 'sso');
            }

            $this->ensureEligible($user, $space, $allowRegistration);
            $this->db->execute("UPDATE users SET password = %, reset_password_token = '', updated = current_timestamp() WHERE id = %",
                [$password, (int) $user->id]);
            $userId = (int) $user->id;
        } else {
            // Une première inscription exige une adresse attestée et l'ouverture des inscriptions.
            if ($emailVerified !== true) {
                throw new LogEventException('SSO_EMAIL_UNVERIFIED', 'Adresse e-mail SSO non vérifiée pour l’inscription.', 'sso');
            }
            if (!$allowRegistration || !$this->coreAuthenticationHelper->registrationEnabled($space)) {
                throw new LogEventException('SSO_REGISTRATION_DISABLED', 'Inscription SSO désactivée dans cet espace.', 'sso');
            }
            $firstName = self::name(self::mapped($data, $mapping['first_name'] ?? null));
            $lastName = self::name(self::mapped($data, $mapping['last_name'] ?? null));
            $principalSpace = $this->coreUserRegistrationHelper->getRegistrationTargetSpace($space);
            $userId = $this->coreUserRegistrationHelper->createUser(
                $principalSpace, $firstName, $lastName, $email, $password
            );
            $this->enrollUserInSpace($userId, $space);
            $this->newRegistrationEmail = $email;
            $this->logger->setContext(['first_name' => $firstName]);
        }

        $this->coreMyAccountHelper->markLocalPassword($userId, false);

        $this->db->execute(<<<SQL
            INSERT INTO sso_links (user_id, provider_id, external_subject, `data`, data_key_version, last_used)
            VALUES (%, %, %, %, 1, current_timestamp())
            SQL, [$userId, (int) $provider->id, $subject, $encryptedData]);
        return $userId;
    }

    private function ensureEligible(?object $user, Space $space, bool $allowRegistration): void
    {
        $this->logger->setContext(['space_id' => (int) $space->id, 'user_id' => $user === null ? null : (int) $user->id,
            'first_name' => $user?->first_name ?? null]);

        if ($user === null || (int) $user->blocked !== 0) {
            throw new LogEventException('SSO_ACCOUNT_BLOCKED', 'Compte non autorisé dans cet espace.', 'sso');
        }

        if (!$this->coreAclHelper->userHasGlobalPermission((int) $user->id, Permissions::MEMBER, $space)) {
            if (!$allowRegistration || !$this->coreAuthenticationHelper->registrationEnabled($space)) {
                throw new LogEventException('SSO_MEMBER_REQUIRED', 'Compte non autorisé dans cet espace.', 'sso');
            }

            // Un lien SSO déjà connu est une preuve d'identité suffisante pour rejoindre un autre espace.
            $this->enrollUserInSpace((int) $user->id, $space);
        }
    }

    /** @param array<string,mixed> $data */
    public static function mapped(array $data, mixed $path): mixed
    {
        if (!is_string($path) || preg_match('/^claims(?:\.[a-zA-Z_][a-zA-Z_0-9]*)+$/', $path) !== 1) {
            return null;
        }
        $value = $data;
        foreach (explode('.', $path) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) return null;
            $value = $value[$part];
        }
        return $value;
    }

    private static function name(mixed $value): string
    {
        return is_string($value) ? mb_substr(trim($value), 0, 100) : '';
    }

    /** @return array<string,array<string,mixed>> */
    private function currentFlows(): array
    {
        $flows = $this->session->get(self::SESSION_KEY, []);
        if (!is_array($flows)) return [];
        return array_filter($flows, static fn ($flow) =>
            is_array($flow) && is_int($flow['created'] ?? null)
            && $flow['created'] >= time() - self::FLOW_LIFETIME);
    }

    private function client(SsoProvider $provider): GoogleOidc|GenericOAuth
    {
        $config = json_decode($provider->config, true, flags: JSON_THROW_ON_ERROR);
        return ($config['driver'] ?? null) === 'google'
            ? new GoogleOidc($provider, $this->coreCryptHelper, $this->request)
            : new GenericOAuth($provider, $this->coreCryptHelper, $this->request);
    }
}
