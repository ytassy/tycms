<?php

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use Firebase\JWT\BeforeValidException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use RuntimeException;
use ReflectionMethod;
use YTassy\Tycms\CoreExts\Core\Controllers\IntegrationController;
use YTassy\Tycms\CoreExts\Core\Controllers\MyAccountController;
use YTassy\Tycms\CoreExts\Core\Models\SsoProvider;
use YTassy\Tycms\CoreExts\Core\Helper\Crypt\CryptHelper;
use YTassy\Tycms\CoreExts\Core\Sso\GoogleOidc;
use YTassy\Tycms\CoreExts\Core\Sso\GenericOAuth;
use YTassy\Tycms\CoreExts\Core\Sso\SsoAutoLinkPolicy;
use YTassy\Tycms\CoreExts\Core\WF\WFSso;
use YTassy\Tycms\Request\Request;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Routing\Router;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class SsoTest extends FrameworkTestCase
{
    public function testEncryptedProviderSecretUsesSeparateKeyFromLinkData(): void
    {
        $crypto = new CryptHelper($this->createApplication());
        $cipher = $crypto->encrypt('secret Google', 'provider:v1');

        self::assertNotSame('secret Google', $cipher);
        self::assertSame('secret Google', $crypto->decrypt($cipher, 'provider:v1'));
        $this->expectException(RuntimeException::class);
        $crypto->decrypt($cipher, 'link:v1');
    }

    public function testMappingReadsOnlyDeclaredClaimsPaths(): void
    {
        $data = ['claims' => ['sub' => 'AbC', 'email_verified' => true]];
        self::assertSame('AbC', WFSso::mapped($data, 'claims.sub'));
        self::assertTrue(WFSso::mapped($data, 'claims.email_verified'));
        self::assertNull(WFSso::mapped($data, 'claims.missing'));
        self::assertNull(WFSso::mapped($data, 'claims.__construct()'));
        self::assertNull(WFSso::mapped($data, '../claims.sub'));
    }

    private function validClaims(): array
    {
        return [
            'iss' => 'https://accounts.google.com',
            'aud' => 'client-id',
            'exp' => time() + 300,
            'iat' => time(),
            'nonce' => 'expected-nonce',
            'sub' => 'external-subject',
        ];
    }

    private function signedIdToken(array $claims): array
    {
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $privateKey = openssl_pkey_new($options);
        if ($privateKey === false) {
            $bundledConfig = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf';
            if (is_file($bundledConfig)) {
                $privateKey = openssl_pkey_new($options + ['config' => $bundledConfig]);
            }
        }
        self::assertNotFalse($privateKey, 'Une clé RSA de test doit pouvoir être créée.');
        $details = openssl_pkey_get_details($privateKey);
        $encode = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $keys = JWK::parseKeySet(['keys' => [[
            'kty' => 'RSA',
            'kid' => 'test-key',
            'alg' => 'RS256',
            'n' => $encode($details['rsa']['n']),
            'e' => $encode($details['rsa']['e']),
        ]]], 'RS256');

        return [JWT::encode($claims, $privateKey, 'RS256', 'test-key'), $keys];
    }

    public function testOidcAcceptsSmallFutureIatDuringSignedTokenValidation(): void
    {
        $claims = $this->validClaims();
        $claims['iat'] = time() + 30;
        [$idToken, $keys] = $this->signedIdToken($claims);
        $originalLeeway = JWT::$leeway;

        self::assertSame(
            'external-subject',
            GoogleOidc::decodeAndValidateIdToken($idToken, $keys, 'client-id', 'expected-nonce', null)['sub']
        );
        self::assertSame($originalLeeway, JWT::$leeway);
    }

    public function testOidcRejectsIatBeyondClockSkew(): void
    {
        $claims = $this->validClaims();
        $claims['iat'] = time() + 120;
        [$idToken, $keys] = $this->signedIdToken($claims);

        $this->expectException(BeforeValidException::class);
        GoogleOidc::decodeAndValidateIdToken($idToken, $keys, 'client-id', 'expected-nonce', null);
    }

    public function testOidcStillRejectsRecentlyExpiredToken(): void
    {
        $claims = $this->validClaims();
        $claims['exp'] = time() - 10;
        [$idToken, $keys] = $this->signedIdToken($claims);

        $this->expectException(RuntimeException::class);
        GoogleOidc::decodeAndValidateIdToken($idToken, $keys, 'client-id', 'expected-nonce', null);
    }

    public function testOidcClaimsAcceptMatchingAudienceAndNonce(): void
    {
        $this->expectNotToPerformAssertions();
        GoogleOidc::assertValidClaims($this->validClaims(), 'client-id', 'expected-nonce', null);
    }

    public function testOidcClaimsRejectWrongAudience(): void
    {
        $this->expectException(RuntimeException::class);
        GoogleOidc::assertValidClaims($this->validClaims(), 'other-client', 'expected-nonce', null);
    }

    public function testOidcClaimsRejectWrongNonce(): void
    {
        $claims = $this->validClaims();
        $claims['nonce'] = 'wrong-nonce';
        $this->expectException(RuntimeException::class);
        GoogleOidc::assertValidClaims($claims, 'client-id', 'expected-nonce', null);
    }

    public function testGoogleAuthorizationIncludesStateNonceAndPkce(): void
    {
        $crypto = new CryptHelper($this->createApplication());
        $provider = $this->provider($crypto, [
                'driver' => 'google',
                'client_id' => 'client-id',
                'redirect_uri' => 'https://example.com/sso/callback',
        ]);
        $oidc = new GoogleOidc($provider, $crypto, new Request(url: 'https://example.com/login'));
        $url = $oidc->authorizationUrl('state-value', 'nonce-value', 'verifier-value');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        self::assertSame('state-value', $query['state']);
        self::assertSame('nonce-value', $query['nonce']);
        self::assertSame('S256', $query['code_challenge_method']);
        self::assertSame(
            rtrim(strtr(base64_encode(hash('sha256', 'verifier-value', true)), '+/', '-_'), '='),
            $query['code_challenge']
        );
        self::assertStringContainsString('openid', $query['scope']);
    }

    public function testInheritedProviderUsesRedirectUriOfCurrentHost(): void
    {
        $crypto = new CryptHelper($this->createApplication());
        $provider = $this->provider($crypto, [
                'driver' => 'google',
                'client_id' => 'client-id',
                'redirect_uris' => [
                    'https://parent.example.com/sso/callback',
                    'https://child.example.com/sso/callback',
                ],
        ]);
        $oidc = new GoogleOidc($provider, $crypto, new Request(url: 'https://child.example.com/login'));
        parse_str((string) parse_url($oidc->authorizationUrl('state', 'nonce', 'verifier'), PHP_URL_QUERY), $query);

        self::assertSame('https://child.example.com/sso/callback', $query['redirect_uri']);
    }

    public function testAutomaticLinkModesUseMappedVerification(): void
    {
        self::assertFalse(SsoAutoLinkPolicy::allows(0, true));
        self::assertTrue(SsoAutoLinkPolicy::allows(1, true));
        self::assertFalse(SsoAutoLinkPolicy::allows(1, false));
        self::assertFalse(SsoAutoLinkPolicy::allows(1, null));
        self::assertFalse(SsoAutoLinkPolicy::allows(1, 'true'));
        self::assertTrue(SsoAutoLinkPolicy::allows(2, false));
        self::assertTrue(SsoAutoLinkPolicy::allows(2, null));
        self::assertTrue(SsoAutoLinkPolicy::allows(true, true));
        self::assertFalse(SsoAutoLinkPolicy::allows(true, false));
        self::assertSame(0, SsoAutoLinkPolicy::mode(false));
        self::assertSame(0, SsoAutoLinkPolicy::mode(3));
    }

    public function testGenericOidcUsesPkceAndSpaceSeparatedScopes(): void
    {
        $crypto = new CryptHelper($this->createApplication());
        $provider = $this->provider($crypto, [
            'driver' => 'generic', 'client_id' => 'client-id',
            'authorization_endpoint' => 'https://identity.example/authorize',
            'token_endpoint' => 'https://identity.example/token',
            'issuer' => 'https://identity.example', 'jwks_uri' => 'https://identity.example/jwks',
            'redirect_uri' => 'https://app.example/sso/callback',
            'scopes' => ['profile', 'email'],
        ]);
        $provider->protocol = 'oidc';
        $client = new GenericOAuth($provider, $crypto, new Request(url: 'https://app.example/login'));
        parse_str((string) parse_url($client->authorizationUrl('state', 'nonce', 'verifier'), PHP_URL_QUERY), $query);
        self::assertSame('state', $query['state']);
        self::assertSame('nonce', $query['nonce']);
        self::assertSame('profile email openid', $query['scope']);
        self::assertSame('S256', $query['code_challenge_method']);
        self::assertSame('https://app.example/sso/callback', $query['redirect_uri']);
    }

    public function testGenericOidcRejectsWrongIssuerAndAudience(): void
    {
        $claims = $this->validClaims();
        $claims['iss'] = 'https://identity.example';
        $claims['aud'] = ['client-id', 'other-client'];
        $claims['azp'] = 'client-id';
        [$token, $keys] = $this->signedIdToken($claims);
        self::assertSame('external-subject', GenericOAuth::decodeAndValidateIdToken(
            $token, $keys, 'https://identity.example', 'client-id', 'expected-nonce')['sub']);
        foreach ([['https://wrong.example', 'client-id'], ['https://identity.example', 'wrong-client']] as [$issuer, $audience]) {
            try {
                GenericOAuth::decodeAndValidateIdToken($token, $keys, $issuer, $audience, 'expected-nonce');
                self::fail('Le jeton aurait dû être rejeté.');
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        }
    }

    public function testIntegrationTokenKeyIsSeparatedFromIdentityLinks(): void
    {
        $crypto = new CryptHelper($this->createApplication());
        $encrypted = $crypto->encrypt('access-token', 'oauth-grant:v1');
        self::assertSame('access-token', $crypto->decrypt($encrypted, 'oauth-grant:v1'));
        $this->expectException(RuntimeException::class);
        $crypto->decrypt($encrypted, 'link:v1');
    }

    public function testGenericOAuthIntegrationUsesItsOwnCallbackWithoutIdentityNonce(): void
    {
        $crypto = new CryptHelper($this->createApplication());
        $provider = $this->provider($crypto, [
            'driver' => 'generic', 'client_id' => 'client-id',
            'authorization_endpoint' => 'https://api.example/authorize',
            'token_endpoint' => 'https://api.example/token',
            'redirect_uri' => 'https://app.example/integrations/oauth/callback',
            'scopes' => ['read', 'write'],
        ]);
        $provider->protocol = 'oauth2';
        $client = new GenericOAuth($provider, $crypto, new Request(url: 'https://app.example/integrations'));
        parse_str((string) parse_url($client->authorizationUrl('state', '', 'verifier'), PHP_URL_QUERY), $query);
        self::assertSame('https://app.example/integrations/oauth/callback', $query['redirect_uri']);
        self::assertSame('read write', $query['scope']);
        self::assertArrayNotHasKey('nonce', $query);
        self::assertSame('S256', $query['code_challenge_method']);
    }

    public function testIntegrationsUseTheAccountApiAndKeepOnlyTheTechnicalCallback(): void
    {
        $app = $this->createApplication();
        $router = new Router($app);
        $register = new ReflectionMethod(Router::class, 'registerController');
        $register->invoke($router, new IntegrationController($app));
        $register->invoke($router, new MyAccountController($app));
        self::assertNull($router->resolve(new Request(uri: '/integrations')));
        self::assertNull($router->resolve(new Request(uri: '/integrations/oauth/start/12')));
        self::assertSame('IntegrationCallback', $router->resolve(new Request(uri: '/integrations/oauth/callback'))->name);
        self::assertNull($router->resolve(new Request(uri: '/integrations/oauth/disconnect/12')));
        self::assertNull($router->resolve(new Request(uri: '/account/api/integrations/start')));
        self::assertSame('MyAccountStartIntegration', $router->resolve(new Request(
            RequestMethod::POST, '/account/api/integrations/start'))->name);
        self::assertSame('MyAccountDisconnectIntegration', $router->resolve(new Request(
            RequestMethod::POST, '/account/api/integrations/disconnect'))->name);
    }

    /** @param array<string,mixed> $config */
    private function provider(CryptHelper $crypto, array $config): SsoProvider
    {
        $provider = new SsoProvider($this->createApplication());
        $provider->config = json_encode($config, JSON_THROW_ON_ERROR);
        $provider->secret_data = $crypto->encrypt('client-secret', 'provider:v1');
        $provider->secret_key_version = 1;
        return $provider;
    }
}
