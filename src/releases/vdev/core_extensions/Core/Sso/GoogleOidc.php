<?php

namespace YTassy\Tycms\CoreExts\Core\Sso;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use League\OAuth2\Client\Provider\Google;
use YTassy\Tycms\Logging\LogEventException;
use YTassy\Tycms\CoreExts\Core\Helper\Crypt\CryptHelper;
use YTassy\Tycms\CoreExts\Core\Models\SsoProvider;
use YTassy\Tycms\Request\Request;

final class GoogleOidc
{
    private const CLOCK_SKEW_SECONDS = 60;

    private Google $client;
    private string $clientId;
    private ?string $hostedDomain;

    public function __construct(SsoProvider $provider, CryptHelper $crypto, Request $request)
    {
        $config = json_decode($provider->config, true, flags: JSON_THROW_ON_ERROR);
        $this->clientId = $config['client_id'] ?? '';
        $redirectUris = $config['redirect_uris'] ?? [$config['redirect_uri'] ?? null];
        $this->hostedDomain = $config['hosted_domain'] ?? null;
        if (($config['driver'] ?? null) !== 'google' || !is_string($this->clientId)
            || $this->clientId === '' || !is_array($redirectUris)
            || (int) ($provider->secret_key_version ?? 0) !== 1
            || !is_string($provider->secret_data) || $provider->secret_data === '') {
            throw new LogEventException('SSO_CONFIG_INVALID', 'Configuration Google OIDC incomplète.', 'sso', [], 'ERROR');
        }

        $current = parse_url($request->url);
        $redirectUri = null;
        foreach ($redirectUris as $candidate) {
            if (!is_string($candidate)) continue;
            $parts = parse_url($candidate);
            if (!is_array($parts) || !is_array($current)
                || ($parts['scheme'] ?? null) !== ($current['scheme'] ?? null)
                || ($parts['host'] ?? null) !== ($current['host'] ?? null)
                || ($parts['port'] ?? null) !== ($current['port'] ?? null)
                || ($parts['path'] ?? null) !== '/sso/callback'
                || isset($parts['query']) || isset($parts['fragment'])
                || (!in_array($parts['scheme'], ['https'], true)
                    && !in_array($parts['host'], ['localhost', '127.0.0.1'], true))) continue;
            $redirectUri = $candidate;
            break;
        }
        if ($redirectUri === null) {
            throw new LogEventException('SSO_REDIRECT_INVALID', 'URI de retour Google OIDC invalide pour cet hôte.', 'sso', [], 'ERROR');
        }

        $this->client = new Google([
            'clientId' => $this->clientId,
            'clientSecret' => $crypto->decrypt($provider->secret_data, 'provider:v1'),
            'redirectUri' => $redirectUri,
            'timeout' => 8,
        ]);
    }

    public function authorizationUrl(string $state, string $nonce, string $verifier): string
    {
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $options = [
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ];
        if (is_string($this->hostedDomain) && $this->hostedDomain !== '') {
            $options['hd'] = $this->hostedDomain;
        }
        return $this->client->getAuthorizationUrl($options);
    }

    /** @return array<string,mixed> */
    public function identityFromCode(string $code, string $verifier, string $nonce): array
    {
        $this->client->setPkceCode($verifier);
        try {
            $token = $this->client->getAccessToken('authorization_code', ['code' => $code]);
        } catch (\Throwable $error) {
            throw new LogEventException('SSO_CODE_EXCHANGE_FAILED', 'L’échange du code OIDC a échoué.', 'sso', [], 'ERROR', $error);
        }
        $idToken = $token->getValues()['id_token'] ?? null;
        if (!is_string($idToken) || $idToken === '') {
            throw new LogEventException('SSO_ID_TOKEN_MISSING', 'Le fournisseur n’a pas retourné de jeton d’identité.', 'sso', [], 'ERROR');
        }
        return $this->verifyIdToken($idToken, $nonce);
    }

    /** @return array<string,mixed> */
    private function verifyIdToken(string $idToken, string $nonce): array
    {
        $segments = explode('.', $idToken);
        $header = count($segments) === 3
            ? json_decode(JWT::urlsafeB64Decode($segments[0]), true) : null;
        if (!is_array($header) || ($header['alg'] ?? null) !== 'RS256') {
            throw new LogEventException('SSO_ALGORITHM_INVALID', 'Algorithme du jeton Google invalide.', 'sso', [], 'ERROR');
        }

        $response = new Client(['timeout' => 8])->get('https://www.googleapis.com/oauth2/v3/certs');
        $jwks = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($jwks) || !is_array($jwks['keys'] ?? null)) {
            throw new LogEventException('SSO_JWKS_INVALID', 'Clés publiques Google invalides.', 'sso', [], 'ERROR');
        }
        $jwks['keys'] = array_values(array_filter($jwks['keys'], static fn ($key) =>
            is_array($key) && ($key['kty'] ?? null) === 'RSA'
            && ($key['alg'] ?? 'RS256') === 'RS256'
            && ($key['use'] ?? 'sig') === 'sig'));
        return self::decodeAndValidateIdToken(
            $idToken,
            JWK::parseKeySet($jwks, 'RS256'),
            $this->clientId,
            $nonce,
            $this->hostedDomain
        );
    }

    /** @param array<string,\Firebase\JWT\Key> $keys @return array<string,mixed> */
    public static function decodeAndValidateIdToken(
        string $idToken,
        array $keys,
        string $clientId,
        string $nonce,
        ?string $hostedDomain
    ): array {
        $previousLeeway = JWT::$leeway;
        try {
            JWT::$leeway = self::CLOCK_SKEW_SECONDS;
            $claims = (array) JWT::decode($idToken, $keys);
        } finally {
            JWT::$leeway = $previousLeeway;
        }

        self::assertValidClaims($claims, $clientId, $nonce, $hostedDomain);
        return $claims;
    }

    /** @param array<string,mixed> $claims */
    public static function assertValidClaims(array $claims, string $clientId, string $nonce, ?string $hostedDomain): void
    {
        $now = time();
        if (!in_array($claims['iss'] ?? null, ['https://accounts.google.com', 'accounts.google.com'], true)
            || ($claims['aud'] ?? null) !== $clientId
            || (isset($claims['azp']) && $claims['azp'] !== $clientId)
            || !is_int($claims['exp'] ?? null) || $claims['exp'] <= $now
            || !is_int($claims['iat'] ?? null) || $claims['iat'] > $now + self::CLOCK_SKEW_SECONDS
            || !is_string($claims['nonce'] ?? null) || !hash_equals($nonce, $claims['nonce'])
            || !is_string($claims['sub'] ?? null) || $claims['sub'] === '') {
            throw new LogEventException('SSO_IDENTITY_INVALID', 'Identité Google OIDC invalide.', 'sso', [], 'ERROR');
        }
        if (is_string($hostedDomain) && $hostedDomain !== ''
            && ($claims['hd'] ?? null) !== $hostedDomain) {
            throw new LogEventException('SSO_DOMAIN_DENIED', 'Domaine Google non autorisé.', 'sso', [], 'ERROR');
        }
    }
}
