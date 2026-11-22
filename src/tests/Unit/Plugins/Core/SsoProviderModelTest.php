<?php

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use YTassy\Tycms\DB\Database;
use YTassy\Tycms\DB\ORM\DatabaseOrm;
use YTassy\Tycms\CoreExts\Core\Models\SsoProvider;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class SsoProviderModelTest extends FrameworkTestCase
{
    public function testOrmHydratesTypedProviderWithoutDecodingItsJson(): void
    {
        $database = $this->createMock(Database::class);
        $database->expects(self::once())->method('select')
            ->with('SELECT * FROM sso_providers WHERE id = %', [7])
            ->willReturn([(object) [
                'id' => '7',
                'space_id' => '2',
                'name' => 'Google',
                'enabled' => '1',
                'protocol' => 'oidc',
                'login_enabled' => '1',
                'integration_enabled' => '0',
                'auto_link_account' => '0',
                'config' => '{"driver":"google"}',
                'secret_data' => 'encrypted-secret',
                'secret_key_version' => '1',
                'mapping' => '{"subject":"claims.sub"}',
                'login_button_text' => 'Continuer avec Google',
                'register_button_text' => 'S’inscrire avec Google',
                'created' => '2026-10-13 10:00:00',
                'updated' => null,
            ]]);

        $provider = (new DatabaseOrm($this->createApplication(), $database))
            ->selectOneOrNull(SsoProvider::class, 'SELECT * FROM sso_providers WHERE id = %', [7]);

        self::assertInstanceOf(SsoProvider::class, $provider);
        self::assertSame(7, $provider->id);
        self::assertSame(2, $provider->space_id);
        self::assertTrue($provider->enabled);
        self::assertFalse($provider->integration_enabled);
        self::assertSame('{"driver":"google"}', $provider->config);
        self::assertSame('encrypted-secret', $provider->secret_data);
        self::assertInstanceOf(\DateTimeImmutable::class, $provider->created);
        self::assertNull($provider->updated);
    }

    public function testSerializationHidesProviderConfigurationAndSecret(): void
    {
        $provider = new SsoProvider($this->createApplication());
        $provider->id = 7;
        $provider->name = 'Google';
        $provider->config = '{"client_id":"private"}';
        $provider->mapping = '{"subject":"claims.sub"}';
        $provider->secret_data = 'encrypted-secret';

        self::assertSame(['id' => 7, 'name' => 'Google'], $provider->jsonSerialize());
    }
}
