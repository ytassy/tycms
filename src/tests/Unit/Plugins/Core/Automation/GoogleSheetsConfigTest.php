<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\Automation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\CoreExts\Core\Automation\Support\GoogleSheetsConfig;

final class GoogleSheetsConfigTest extends TestCase
{
    #[Test]
    public function validServiceAccountIsAccepted(): void
    {
        $config = GoogleSheetsConfig::validateJson(json_encode([
            'type' => 'service_account',
            'client_email' => 'automation@example.test',
            'private_key' => "-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----\n",
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ], JSON_THROW_ON_ERROR));

        self::assertSame('automation@example.test', $config['client_email']);
    }

    #[Test]
    public function invalidJsonOrListIsRejected(): void
    {
        foreach (['{', '["service_account"]'] as $json) {
            try {
                GoogleSheetsConfig::validateJson($json);
                self::fail('Configuration Google invalide acceptée.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    #[Test]
    public function wrongTypeOrMissingFieldsAreRejected(): void
    {
        $invalid = [
            ['type' => 'user'],
            ['type' => 'service_account', 'client_email' => 'a@b.test'],
            [
                'type' => 'service_account',
                'client_email' => 'a@b.test',
                'private_key' => 'pas-une-cle',
                'token_uri' => 'https://oauth2.googleapis.com/token',
            ],
        ];

        foreach ($invalid as $config) {
            try {
                GoogleSheetsConfig::validate($config);
                self::fail('Configuration Google incomplète acceptée.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
