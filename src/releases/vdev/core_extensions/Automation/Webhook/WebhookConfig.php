<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Webhook;

/** Lecture et validation de la configuration d'un webhook. */
final class WebhookConfig
{
    /** Nom réservé du jeton dans la query string ou le corps. */
    public const TOKEN_FIELD = 'tycms_token';

    /** Réponse générique : l'événement est accepté, sans détail du run. */
    public const RESPONSE_ACCEPTED = 'accepted';

    /** Réponse détaillée : le run est exécuté dans la requête et son résultat renvoyé. */
    public const RESPONSE_RESULT = 'result';

    /** Clé interne du secret HMAC chiffré. */
    public const HMAC_FIELD = 'hmac_secret';

    public const HMAC_PURPOSE = 'automation:webhook:hmac:v1';

    /** @return array<string, mixed> */
    public static function decode(?string $json): array
    {
        if ($json === null || trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) && !array_is_list($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $config */
    public static function authMode(array $config): string
    {
        $mode = $config['auth_mode'] ?? WebhookAuthMode::BEARER;

        return is_string($mode) && WebhookAuthMode::isValid($mode)
            ? $mode
            : WebhookAuthMode::BEARER;
    }

    /** @return list<string> */
    public static function responseModes(): array
    {
        return [self::RESPONSE_ACCEPTED, self::RESPONSE_RESULT];
    }

    /** @param array<string, mixed> $config */
    public static function responseMode(array $config): string
    {
        $mode = $config['response_mode'] ?? self::RESPONSE_ACCEPTED;

        return is_string($mode) && in_array($mode, self::responseModes(), true)
            ? $mode
            : self::RESPONSE_ACCEPTED;
    }

    /**
     * Conserve la configuration interne du webhook et applique le mode choisi.
     *
     * @param array<string, mixed> $existing
     * @return array<string, mixed>
     */
    public static function merge(mixed $input, array $existing): array
    {
        $mode = is_object($input)
            ? ($input->auth_mode ?? null)
            : (is_array($input) ? ($input['auth_mode'] ?? null) : null);

        if (!is_string($mode) || !WebhookAuthMode::isValid($mode)) {
            $mode = self::authMode($existing);
        }

        $responseMode = is_object($input)
            ? ($input->response_mode ?? null)
            : (is_array($input) ? ($input['response_mode'] ?? null) : null);

        if (!is_string($responseMode) || !in_array($responseMode, self::responseModes(), true)) {
            $responseMode = self::responseMode($existing);
        }

        $config = [
            'auth_mode' => $mode,
            'response_mode' => $responseMode,
        ];

        // Le secret HMAC reste interne et n'est jamais renvoyé à l'éditeur.
        if ($mode === WebhookAuthMode::HMAC && is_string($existing[self::HMAC_FIELD] ?? null)) {
            $config[self::HMAC_FIELD] = $existing[self::HMAC_FIELD];
        }

        return $config;
    }

    /** @param array<string, mixed> $config */
    public static function secretConfigured(object $trigger, array $config): bool
    {
        if (self::authMode($config) === WebhookAuthMode::HMAC) {
            return is_string($config[self::HMAC_FIELD] ?? null) && $config[self::HMAC_FIELD] !== '';
        }

        return is_string($trigger->secret_hash ?? null) && $trigger->secret_hash !== '';
    }

    private function __construct()
    {
    }
}
