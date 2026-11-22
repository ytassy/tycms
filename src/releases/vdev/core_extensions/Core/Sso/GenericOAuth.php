<?php

namespace YTassy\Tycms\CoreExts\Core\Sso;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use League\OAuth2\Client\OptionProvider\PostAuthOptionProvider;
use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Token\AccessTokenInterface;
use YTassy\Tycms\Logging\LogEventException;
use YTassy\Tycms\CoreExts\Core\Helper\Crypt\CryptHelper;
use YTassy\Tycms\CoreExts\Core\Models\SsoProvider;
use YTassy\Tycms\Request\Request;

/** Authorization code + PKCE for configured OAuth clients; OIDC adds signed identity validation. */
final class GenericOAuth
{
    private GenericProvider $client;
    private array $config;
    private string $protocol;

    public function __construct(SsoProvider $provider, CryptHelper $crypto, Request $request)
    {
        $this->config = json_decode($provider->config, true, flags: JSON_THROW_ON_ERROR);
        $this->protocol = $provider->protocol;
        SsoProviderSettings::assertActiveGeneric($this->config, $this->protocol);
        $redirect = self::redirectForRequest($this->config['redirect_uris'] ?? [$this->config['redirect_uri'] ?? null], $request, $this->protocol);
        $secret = is_string($provider->secret_data) && $provider->secret_data !== ''
            ? $crypto->decrypt($provider->secret_data, 'provider:v1') : '';
        $this->client = new class([
            'clientId' => $this->config['client_id'],
            'clientSecret' => $secret,
            'redirectUri' => $redirect,
            'urlAuthorize' => $this->config['authorization_endpoint'],
            'urlAccessToken' => $this->config['token_endpoint'],
            'urlResourceOwnerDetails' => $this->config['token_endpoint'],
            'scopeSeparator' => ' ',
            'timeout' => 8,
        ], [
            'httpClient' => new Client(['timeout' => 8, 'allow_redirects' => false]),
            'optionProvider' => new class extends PostAuthOptionProvider {
                public function getAccessTokenOptions($method, array $params)
                {
                    if (($params['client_secret'] ?? null) === '') unset($params['client_secret']);
                    return parent::getAccessTokenOptions($method, $params);
                }
            },
        ]) extends GenericProvider {
            protected function getAuthorizationParameters(array $options)
            {
                $params = parent::getAuthorizationParameters($options);
                unset($params['approval_prompt']);
                return $params;
            }
        };
    }

    public function authorizationUrl(string $state, string $nonce, string $verifier): string
    {
        $scopes = $this->config['scopes'] ?? [];
        if ($this->protocol === 'oidc' && !in_array('openid', $scopes, true)) $scopes[] = 'openid';
        $options = [
            'state' => $state,
            'scope' => $scopes,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ];
        if ($this->protocol === 'oidc') $options['nonce'] = $nonce;
        return $this->client->getAuthorizationUrl($options);
    }

    public function tokenFromCode(string $code, string $verifier): AccessTokenInterface
    {
        $this->client->setPkceCode($verifier);
        try {
            return $this->client->getAccessToken('authorization_code', ['code' => $code]);
        } catch (\Throwable $error) {
            $oidc = $this->protocol === 'oidc';
            throw new LogEventException($oidc ? 'SSO_CODE_EXCHANGE_FAILED' : 'OAUTH_CODE_EXCHANGE_FAILED',
                'L’échange du code OAuth a échoué.', $oidc ? 'sso' : 'oauth', [], 'ERROR', $error);
        }
    }

    public function refresh(string $refreshToken): AccessTokenInterface
    {
        return $this->client->getAccessToken('refresh_token', ['refresh_token' => $refreshToken]);
    }

    /** @return array<string,mixed> */
    public function identityFromCode(string $code, string $verifier, string $nonce): array
    {
        if ($this->protocol !== 'oidc') throw new LogEventException('SSO_PROTOCOL_INVALID', 'OAuth 2.0 ne fournit pas une identité OIDC.', 'sso', [], 'ERROR');
        $token = $this->tokenFromCode($code, $verifier);
        $idToken = $token->getValues()['id_token'] ?? null;
        if (!is_string($idToken) || $idToken === '') throw new LogEventException('SSO_ID_TOKEN_MISSING', 'ID token absent.', 'sso', [], 'ERROR');
        $response = (new Client(['timeout' => 8, 'allow_redirects' => false]))->get($this->config['jwks_uri']);
        $jwks = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($jwks) || !is_array($jwks['keys'] ?? null)) throw new LogEventException('SSO_JWKS_INVALID', 'JWKS invalide.', 'sso', [], 'ERROR');
        $jwks['keys'] = array_values(array_filter($jwks['keys'], static fn ($key) =>
            is_array($key) && ($key['kty'] ?? null) === 'RSA'
            && ($key['alg'] ?? 'RS256') === 'RS256' && ($key['use'] ?? 'sig') === 'sig'));
        return self::decodeAndValidateIdToken($idToken, JWK::parseKeySet($jwks, 'RS256'),
            $this->config['issuer'], $this->config['client_id'], $nonce);
    }

    /** @param array<string,\Firebase\JWT\Key> $keys @return array<string,mixed> */
    public static function decodeAndValidateIdToken(string $idToken, array $keys, string $issuer, string $clientId, string $nonce): array
    {
        $segments = explode('.', $idToken);
        $header = count($segments) === 3 ? json_decode(JWT::urlsafeB64Decode($segments[0]), true) : null;
        if (!is_array($header) || ($header['alg'] ?? null) !== 'RS256') throw new LogEventException('SSO_ALGORITHM_INVALID', 'Algorithme OIDC invalide.', 'sso', [], 'ERROR');
        $old = JWT::$leeway;
        try {
            JWT::$leeway = 60;
            $claims = (array) JWT::decode($idToken, $keys);
        } finally {
            JWT::$leeway = $old;
        }
        $audience = $claims['aud'] ?? null;
        $audiences = is_array($audience) ? $audience : [$audience];
        $now = time();
        if (($claims['iss'] ?? null) !== $issuer || !in_array($clientId, $audiences, true)
            || (count($audiences) > 1 && ($claims['azp'] ?? null) !== $clientId)
            || (isset($claims['azp']) && $claims['azp'] !== $clientId)
            || !is_int($claims['exp'] ?? null) || $claims['exp'] <= $now
            || !is_int($claims['iat'] ?? null) || $claims['iat'] > $now + 60
            || !is_string($claims['nonce'] ?? null) || !hash_equals($nonce, $claims['nonce'])
            || !is_string($claims['sub'] ?? null) || $claims['sub'] === '') {
            throw new LogEventException('SSO_IDENTITY_INVALID', 'Identité OIDC invalide.', 'sso', [], 'ERROR');
        }
        return $claims;
    }

    private static function redirectForRequest(array $uris, Request $request, string $protocol): string
    {
        $current = parse_url($request->url);
        $path = $protocol === 'oidc' ? '/sso/callback' : '/integrations/oauth/callback';
        foreach ($uris as $uri) {
            if (!is_string($uri)) continue;
            $parts = parse_url($uri);
            if (is_array($parts) && is_array($current)
                && ($parts['scheme'] ?? null) === ($current['scheme'] ?? null)
                && ($parts['host'] ?? null) === ($current['host'] ?? null)
                && ($parts['port'] ?? null) === ($current['port'] ?? null)
                && ($parts['path'] ?? null) === $path
                && !isset($parts['query']) && !isset($parts['fragment'])) return $uri;
        }
        throw new LogEventException('OAUTH_REDIRECT_INVALID', 'URI de retour OAuth invalide pour cet hôte.', 'oauth', [], 'ERROR');
    }
}
