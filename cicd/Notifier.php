<?php

declare(strict_types=1);

namespace Tycms\CiCd;

use RuntimeException;

/**
 * Notification d'un workflow GitHub vers les canaux configurés.
 *
 * Trois canaux optionnels, activés uniquement si leur variable
 * d'environnement est présente : Telegram, Slack et Discord. Un canal absent
 * est silencieux ; un canal présent mais mal configuré est signalé, sans
 * jamais empêcher le déroulement du workflow.
 *
 * Les adresses de webhook contiennent un secret : elles ne sont ni affichées,
 * ni journalisées, ni reprises dans un message d'erreur.
 */
final class Notifier
{
    /** Limites des canaux, cohérentes avec celles des automatisations du Core. */
    private const TELEGRAM_MAX = 4096;
    private const SLACK_MAX = 4000;
    private const DISCORD_MAX = 2000;

    private const MAX_RESPONSE_BYTES = 65536;

    /** @var list<array{channel: string, send: callable(string, string): void}> */
    private array $channels = [];

    /** @var list<string> Problèmes de configuration détectés, sans contenu sensible. */
    private array $errors = [];

    /** Construire les canaux déclarés par l'environnement. */
    public static function fromEnvironment(): self
    {
        $notifier = new self();

        // NOTIFY=off permet de désactiver les envois sans retirer les secrets.
        if (getenv('NOTIFY') === 'off') {
            return $notifier;
        }

        // Telegram : un seul secret « token:chat_id » évite d'exposer le chat en clair.
        $telegram = getenv('TELEGRAM_NOTIFY');
        if (is_string($telegram) && $telegram !== '') {
            if (preg_match('/\A([0-9]{6,12}:[A-Za-z0-9_-]{20,64}):(-?[0-9]{1,20}|@[A-Za-z0-9_]{5,64})\z/', $telegram, $parts) === 1) {
                $notifier->channels[] = self::telegram($parts[1], $parts[2]);
            } else {
                $notifier->errors[] = 'TELEGRAM_NOTIFY au format token:chat_id attendu.';
            }
        }

        foreach (['SLACK_WEBHOOK' => 'slack', 'DISCORD_WEBHOOK' => 'discord'] as $variable => $kind) {
            $url = getenv($variable);
            if (!is_string($url) || $url === '') {
                continue;
            }
            $channel = $kind === 'discord' ? self::discord($url) : self::slack($url);
            if ($channel === null) {
                $notifier->errors[] = $variable . ' : URL de webhook invalide.';
                continue;
            }
            $notifier->channels[] = $channel;
        }

        return $notifier;
    }

    /** Problèmes de configuration détectés, sans contenu sensible. */
    public function errors(): array
    {
        return $this->errors;
    }

    public function hasChannels(): bool
    {
        return $this->channels !== [];
    }

    /**
     * Envoyer un message. Ne lève jamais : une notification manquée ne doit pas
     * faire échouer le workflow.
     *
     * @return bool vrai si au moins un canal a accepté le message
     */
    public function notify(string $title, string $message): bool
    {
        $sent = false;
        foreach ($this->channels as $channel) {
            try {
                ($channel['send'])($title, $message);
                $sent = true;
            } catch (\Throwable) {
                // Le nom du canal suffit au diagnostic ; l'URL et le jeton restent secrets.
                error_log('Notifications : envoi ' . $channel['channel'] . ' impossible.');
            }
        }

        return $sent;
    }

    private static function telegram(string $token, string $chatId): array
    {
        return ['channel' => 'telegram', 'send' => static function (string $title, string $message) use ($token, $chatId): void {
            self::post(
                'https://api.telegram.org/bot' . $token . '/sendMessage',
                http_build_query([
                    'chat_id' => $chatId,
                    'text' => mb_substr($title . "\n\n" . $message, 0, self::TELEGRAM_MAX, 'UTF-8'),
                    'disable_web_page_preview' => 'true',
                ]),
                'application/x-www-form-urlencoded'
            );
        }];
    }

    private static function slack(string $url): ?array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'hooks.slack.com'
            || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return ['channel' => 'slack', 'send' => static function (string $title, string $message) use ($url): void {
            self::post($url, json_encode([
                'text' => mb_substr($title . "\n\n" . $message, 0, self::SLACK_MAX, 'UTF-8'),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'application/json');
        }];
    }

    private static function discord(string $url): ?array
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? ($parts['host'] ?? '') : '';
        $path = is_array($parts) ? ($parts['path'] ?? '') : '';
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || !in_array($host, ['discord.com', 'discordapp.com'], true)
            || !str_starts_with($path, '/api/webhooks/')
            || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return ['channel' => 'discord', 'send' => static function (string $title, string $message) use ($url): void {
            self::post($url, json_encode([
                'content' => mb_substr($title . "\n\n" . $message, 0, self::DISCORD_MAX, 'UTF-8'),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'application/json');
        }];
    }

    /** Transport HTTPS minimal : pas de redirection, réponse bornée et jamais recopiée. */
    private static function post(string $url, string $body, string $contentType): void
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Transport indisponible.');
        }
        $received = 0;
        try {
            if (!curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => ['Content-Type: ' . $contentType, 'Content-Length: ' . strlen($body)],
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$received): int {
                    $received += strlen($chunk);
                    return $received > self::MAX_RESPONSE_BYTES ? 0 : strlen($chunk);
                },
            ])) {
                throw new RuntimeException('Transport indisponible.');
            }
            $success = curl_exec($handle);
            $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if ($success === false || $status < 200 || $status >= 300) {
                throw new RuntimeException('Envoi refusé.');
            }
        } finally {
            // Pas de curl_close() : sans effet depuis PHP 8.0 et déprécié en 8.5.
            // La ressource libérée à la sortie de portée suffit.
            unset($handle);
        }
    }
}
