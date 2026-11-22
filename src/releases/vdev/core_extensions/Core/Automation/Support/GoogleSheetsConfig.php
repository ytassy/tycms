<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Support;

/** Validation du JSON de compte de service Google Sheets. */
final class GoogleSheetsConfig
{
    public const FIELD = 'google_service_account';
    public const PURPOSE = 'automation:google_sheets:v1';
    public const MAX_BYTES = 20000;

    /** @return array<string, mixed> */
    public static function decode(string $json): array
    {
        $decoded = json_decode($json, true);

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new \InvalidArgumentException('La configuration Google doit être un objet JSON.');
        }

        return $decoded;
    }

    /** @param array<string, mixed> $config */
    public static function validate(array $config): void
    {
        if (($config['type'] ?? null) !== 'service_account') {
            throw new \InvalidArgumentException('Le JSON Google doit être un compte de service.');
        }

        foreach (['client_email', 'private_key', 'token_uri'] as $field) {
            if (!is_string($config[$field] ?? null) || trim($config[$field]) === '') {
                throw new \InvalidArgumentException('La configuration Google est incomplète : ' . $field . '.');
            }
        }

        if (!str_contains((string) $config['private_key'], 'BEGIN PRIVATE KEY')) {
            throw new \InvalidArgumentException('La clé privée Google est invalide.');
        }
    }

    /** @return array<string, mixed> */
    public static function validateJson(string $json): array
    {
        $config = self::decode($json);
        self::validate($config);

        return $config;
    }

    private function __construct()
    {
    }
}
