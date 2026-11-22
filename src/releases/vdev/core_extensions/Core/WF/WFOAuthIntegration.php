<?php

namespace YTassy\Tycms\CoreExts\Core\WF;

use League\OAuth2\Client\Token\AccessTokenInterface;
use YTassy\Tycms\Logging\LogEventException;
use YTassy\Tycms\CoreExts\Core\Helper\TraitAllHelpers;
use YTassy\Tycms\CoreExts\Core\Helper\AccountReturnPath;
use YTassy\Tycms\CoreExts\Core\Models\SsoProvider;
use YTassy\Tycms\CoreExts\Core\Sso\GenericOAuth;
use YTassy\Tycms\Request\Request;
use YTassy\Tycms\WF\BaseWF;

final class WFOAuthIntegration extends BaseWF
{
    use TraitAllHelpers;
    use TraitWF;

    private const SESSION_KEY = 'oauth_integration_flows';

    /** @return list<array{id:int,name:string,connected:bool,available:bool,expires_at:?string}> */
    public function listForUser(): array
    {
        $userId = $this->requireUser();
        $grants = [];
        foreach ($this->coreOAuthGrantHelper->getGrants($userId) as $grant) {
            $grants[(int) $grant->provider_id] = $grant;
        }
        $result = [];
        foreach ($this->coreSsoProviderHelper->getProvidersForSpace($this->coreSpaceHelper->getCurrentSpace()) as $provider) {
            $grant = $grants[$provider->id] ?? null;
            $available = $this->coreSsoProviderHelper->isIntegrationAvailable($provider);
            if ($provider->protocol !== 'oauth2' || ($grant === null && !$available)) continue;
            $result[] = [
                'id' => $provider->id, 'name' => $provider->name,
                'connected' => $grant !== null,
                'available' => $available,
                'expires_at' => $grant?->expires_at,
            ];
        }
        return $result;
    }

    public function start(int $providerId, string $returnPath): string
    {
        $userId = $this->requireUser();
        $returnPath = AccountReturnPath::validate($returnPath);
        $space = $this->coreSpaceHelper->getCurrentSpace();
        $provider = $this->requireProvider($providerId);
        $state = bin2hex(random_bytes(32));
        $verifier = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
        $url = $this->client($provider)->authorizationUrl($state, '', $verifier);
        $flows = $this->flows();
        if (count($flows) >= 5) array_shift($flows);
        $flows[$state] = [
            'user_id' => $userId, 'space_id' => $space->id, 'provider_id' => $providerId,
            'verifier' => $verifier, 'created' => time(), 'return_path' => $returnPath,
        ];
        $this->session->set(self::SESSION_KEY, $flows);
        return $url;
    }

    public function finish(Request $request): string
    {
        $state = $request->query('state');
        $flows = $this->flows();
        $flow = is_string($state) ? ($flows[$state] ?? null) : null;
        if (!is_array($flow)) throw new LogEventException('OAUTH_STATE_EXPIRED', 'État OAuth absent ou expiré.', 'oauth');
        $this->logger->setContext(['space_id' => $flow['space_id'] ?? null, 'provider_id' => $flow['provider_id'] ?? null, 'user_id' => $flow['user_id'] ?? null]);

        unset($flows[$state]);
        $this->session->set(self::SESSION_KEY, $flows);
        if ($request->query('error') !== null || $this->requireUser() !== ($flow['user_id'] ?? null)
            || $this->coreSpaceHelper->getCurrentSpace()->id !== ($flow['space_id'] ?? null)
            || !is_int($flow['provider_id'] ?? null) || !is_string($flow['verifier'] ?? null)) {
            throw new LogEventException('OAUTH_REFUSED', 'Autorisation OAuth refusée ou session modifiée.', 'oauth');
        }
        $code = $request->query('code');
        if (!is_string($code) || $code === '') throw new LogEventException('OAUTH_CODE_MISSING', 'Code OAuth absent.', 'oauth');
        $provider = $this->requireProvider($flow['provider_id']);
        $token = $this->client($provider)->tokenFromCode($code, $flow['verifier']);
        $this->saveToken($flow['user_id'], $provider->id, $token);
        return AccountReturnPath::validate($flow['return_path'] ?? '/');
    }

    public function disconnect(int $providerId): void
    {
        $this->coreOAuthGrantHelper->deleteGrant($this->requireUser(), $providerId);
    }

    /** For server-side integration helpers. Never expose this value in a response or template. */
    public function getAccessToken(int $providerId): string
    {
        $userId = $this->requireUser();
        $provider = $this->requireProvider($providerId);
        $grant = $this->coreOAuthGrantHelper->getGrant($userId, $providerId);
        if ($grant === null || (int) $grant->token_key_version !== 1) {
            throw new LogEventException('OAUTH_GRANT_MISSING', 'Intégration non autorisée.', 'oauth');
        }
        $data = json_decode($this->coreCryptHelper->decrypt($grant->token_data, 'oauth-grant:v1'), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($data) || !is_string($data['access_token'] ?? null)) {
            throw new LogEventException('OAUTH_GRANT_INVALID', 'Autorisation OAuth invalide.', 'oauth');
        }
        $expires = $data['expires'] ?? null;
        if (is_int($expires) && $expires <= time() + 60) {
            if (!is_string($data['refresh_token'] ?? null) || $data['refresh_token'] === '') {
                throw new LogEventException('OAUTH_GRANT_EXPIRED', 'Autorisation OAuth expirée.', 'oauth');
            }
            try {
                $token = $this->client($provider)->refresh($data['refresh_token']);
            } catch (\Throwable $error) {
                return $this->tokenAfterConcurrentUpdate($userId, $providerId, $grant->token_data) ?? throw $error;
            }
            if ($this->saveToken($userId, $providerId, $token, $data['refresh_token'], $grant->token_data)) {
                return $token->getToken();
            }
            return $this->tokenAfterConcurrentUpdate($userId, $providerId, $grant->token_data)
                ?? throw new LogEventException('OAUTH_GRANT_CHANGED', 'Autorisation OAuth modifiée pendant le rafraîchissement.', 'oauth');
        }
        return $data['access_token'];
    }

    private function saveToken(int $userId, int $providerId, AccessTokenInterface $token,
        ?string $oldRefresh = null, ?string $expectedEncrypted = null): bool
    {
        $data = [
            'access_token' => $token->getToken(),
            'refresh_token' => $token->getRefreshToken() ?: $oldRefresh,
            'expires' => $token->getExpires(),
        ];
        $encrypted = $this->coreCryptHelper->encrypt(json_encode($data, JSON_THROW_ON_ERROR), 'oauth-grant:v1');
        if ($expectedEncrypted !== null) {
            return $this->coreOAuthGrantHelper->replaceGrantIfUnchanged(
                $userId, $providerId, $expectedEncrypted, $encrypted, $data['expires']);
        }
        $this->coreOAuthGrantHelper->saveGrant($userId, $providerId, $encrypted, $data['expires']);
        return true;
    }

    private function tokenAfterConcurrentUpdate(int $userId, int $providerId, string $previousEncrypted): ?string
    {
        $current = $this->coreOAuthGrantHelper->getGrant($userId, $providerId);
        if ($current === null || (int) $current->token_key_version !== 1
            || hash_equals($previousEncrypted, $current->token_data)) return null;
        $data = json_decode($this->coreCryptHelper->decrypt($current->token_data, 'oauth-grant:v1'), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($data) || !is_string($data['access_token'] ?? null)
            || (is_int($data['expires'] ?? null) && $data['expires'] <= time())) return null;
        return $data['access_token'];
    }

    private function requireUser(): int
    {
        return (int) $this->wfMyAccount->requireAccount()->id;
    }

    private function requireProvider(int $providerId): SsoProvider
    {
        $provider = $this->coreSsoProviderHelper->getIntegrationProvider($this->coreSpaceHelper->getCurrentSpace(), $providerId);
        if ($provider === null) throw new LogEventException('OAUTH_PROVIDER_UNAVAILABLE', 'Intégration indisponible.', 'oauth');
        return $provider;
    }

    private function client(SsoProvider $provider): GenericOAuth
    {
        return new GenericOAuth($provider, $this->coreCryptHelper, $this->request);
    }

    /** @return array<string,array<string,mixed>> */
    private function flows(): array
    {
        $flows = $this->session->get(self::SESSION_KEY, []);
        if (!is_array($flows)) return [];
        return array_filter($flows, static fn ($flow) => is_array($flow)
            && is_int($flow['created'] ?? null) && $flow['created'] >= time() - 600);
    }
}
