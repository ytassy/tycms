<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Notification;

/**
 * Profils de notification nommés, stockés chiffrés dans la configuration.
 *
 * Un profil ne contient que des identifiants de destination. Le scénario ne
 * référence que sa clé, jamais le jeton ou le webhook.
 */
final class NotificationTargets
{
    public const MAX_JSON_BYTES = 32768;
    public const MAX_TARGETS = 20;

    /**
     * @return array<string, array{type: string, token?: string, chat_id?: string, webhook?: string}>
     */
    public static function parse(string $json): array
    {
        if (strlen($json) > self::MAX_JSON_BYTES) {
            throw new \InvalidArgumentException('Le JSON des profils de notification est trop volumineux.');
        }

        try {
            $decoded = json_decode($json, false, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('Le JSON des profils de notification est invalide.');
        }

        if (!is_object($decoded)) {
            throw new \InvalidArgumentException('Les profils de notification doivent former une map clé → profil.');
        }

        $profiles = get_object_vars($decoded);

        if (count($profiles) > self::MAX_TARGETS) {
            throw new \InvalidArgumentException('Trop de profils de notification (maximum ' . self::MAX_TARGETS . ').');
        }

        $targets = [];

        foreach ($profiles as $key => $profile) {
            if (!is_string($key) || preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/', $key) !== 1) {
                throw new \InvalidArgumentException(
                    'Clé de profil invalide : utilisez des minuscules, chiffres, tirets ou underscores.',
                );
            }

            if (!is_object($profile)) {
                throw new \InvalidArgumentException('Le profil « ' . $key . ' » doit être un objet JSON.');
            }

            $profile = get_object_vars($profile);

            $type = $profile['type'] ?? null;

            if (!is_string($type) || !in_array($type, ['telegram', 'slack', 'discord'], true)) {
                throw new \InvalidArgumentException(
                    'Le profil « ' . $key . ' » doit déclarer type = telegram, slack ou discord.',
                );
            }

            $allowed = $type === 'telegram'
                ? ['type', 'token', 'chat_id']
                : ['type', 'webhook'];

            foreach (array_keys($profile) as $field) {
                if (!in_array($field, $allowed, true)) {
                    throw new \InvalidArgumentException(
                        'Champ « ' . $field . ' » inconnu dans le profil « ' . $key . ' ».',
                    );
                }
            }

            foreach ($profile as $field => $value) {
                if (!is_string($value) || trim($value) === '') {
                    throw new \InvalidArgumentException(
                        'Le champ « ' . $field . ' » du profil « ' . $key . ' » doit être une chaîne non vide.',
                    );
                }
            }

            if ($type === 'telegram') {
                if (!isset($profile['token'], $profile['chat_id'])) {
                    throw new \InvalidArgumentException(
                        'Le profil « ' . $key . ' » doit définir token et chat_id.',
                    );
                }

                if (strlen($profile['token']) > 512 || strlen($profile['chat_id']) > 64) {
                    throw new \InvalidArgumentException('Identifiants Telegram trop longs dans le profil « ' . $key . ' ».');
                }
            } else {
                if (!isset($profile['webhook'])) {
                    throw new \InvalidArgumentException(
                        'Le profil « ' . $key . ' » doit définir webhook.',
                    );
                }

                if (strlen($profile['webhook']) > 512 || !str_starts_with($profile['webhook'], 'https://')) {
                    throw new \InvalidArgumentException(
                        'Le webhook du profil « ' . $key . ' » doit être une URL HTTPS de 512 octets maximum.',
                    );
                }
            }

            $targets[$key] = $profile;
        }

        return $targets;
    }
}
