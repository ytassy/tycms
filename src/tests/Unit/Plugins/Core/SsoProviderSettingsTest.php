<?php

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use stdClass;
use YTassy\Tycms\CoreExts\Core\Sso\SsoProviderSettings;

final class SsoProviderSettingsTest extends TestCase
{
    public function testJsonMustRemainAnObjectAndRejectNestedSecrets(): void
    {
        self::assertInstanceOf(stdClass::class, SsoProviderSettings::parseObject('{}', 'Configuration'));
        self::assertSame('{}', json_encode(SsoProviderSettings::parseObject('{}', 'Configuration'), JSON_THROW_ON_ERROR));
        foreach (['[]', '{', '{"nested":[{"client_secret":"visible"}]}'] as $json) {
            try {
                SsoProviderSettings::parseObject($json, 'Configuration');
                self::fail("Le document $json aurait dû être rejeté.");
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        SsoProviderSettings::parseObject('{"refreshToken":"visible"}', 'Mapping');
    }

    public function testGoogleConfigurationRequiresSafeCallbackAndSubject(): void
    {
        $config = [
            'driver' => 'google', 'client_id' => 'client-id',
            'redirect_uris' => ['https://example.com/sso/callback', 'http://localhost:8080/sso/callback'],
        ];
        SsoProviderSettings::assertActiveGoogle($config, ['subject' => 'claims.sub']);
        self::assertTrue(true);

        foreach (['https://example.com/other', 'http://example.com/sso/callback', 'https://user@example.com/sso/callback'] as $uri) {
            try {
                SsoProviderSettings::assertActiveGoogle([
                    ...$config, 'redirect_uris' => [$uri],
                ], ['subject' => 'claims.sub']);
                self::fail("L’URI $uri aurait dû être rejetée.");
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testGenericConfigurationRequiresSafeEndpointsAndProtocolSpecificCallback(): void
    {
        $config = [
            'driver' => 'generic', 'client_id' => 'client-id',
            'authorization_endpoint' => 'https://identity.example/authorize',
            'token_endpoint' => 'https://identity.example/token',
            'issuer' => 'https://identity.example', 'jwks_uri' => 'https://identity.example/jwks',
            'redirect_uri' => 'https://app.example/sso/callback',
        ];
        SsoProviderSettings::assertActiveGeneric($config, 'oidc');
        SsoProviderSettings::assertOidcMapping(['subject' => 'claims.sub']);
        foreach ([
            ['token_endpoint' => 'http://identity.example/token'],
            ['jwks_uri' => 'https://user@identity.example/jwks'],
            ['redirect_uri' => 'https://app.example/integrations/oauth/callback'],
        ] as $bad) {
            try {
                SsoProviderSettings::assertActiveGeneric([...$config, ...$bad], 'oidc');
                self::fail('La configuration dangereuse aurait dû être rejetée.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
