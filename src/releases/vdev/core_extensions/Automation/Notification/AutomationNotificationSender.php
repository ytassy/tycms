<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Notification;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;

/**
 * Envoi commun aux notifications d'échec et à l'action core::notify.
 *
 * Les deux usages ont leurs propres canaux et secrets dans la configuration du
 * Space. Un échec de canal est journalisé et retourné, sans interrompre le run.
 */
final class AutomationNotificationSender extends BaseHelper
{
    /** @var list<string> */
    private const CHANNELS = ['telegram', 'slack', 'discord'];

    /**
     * @return array{sent: list<string>, failed: array<string, string>, skipped: bool}
     */
    public function sendFailure(int $spaceId, string $title, string $message): array
    {
        return $this->send($spaceId, '', $title, $message, null);
    }

    /**
     * @param list<string>|null $only null pour tous les canaux activés
     * @return array{sent: list<string>, failed: array<string, string>, skipped: bool}
     */
    public function sendScenario(int $spaceId, string $title, string $message, ?array $only = null): array
    {
        return $this->send($spaceId, 'scenario_', $title, $message, $only);
    }

    /**
     * Envoie via un provider précis, avec un profil nommé facultatif.
     *
     * Sans cible, le canal de scénario par défaut du provider est utilisé.
     * Avec une cible, seule la configuration chiffrée du profil est lue.
     *
     * @return array{sent: list<string>, failed: array<string, string>, skipped: bool}
     */
    public function sendProvider(
        int $spaceId,
        string $provider,
        string $title,
        string $message,
        ?string $target = null,
    ): array {
        if ($target === null) {
            $channel = $this->buildChannel(
                $provider,
                $this->storedConfig($spaceId),
                'scenario_',
                $spaceId,
                'scenario',
            );

            return $channel === null
                ? ['sent' => [], 'failed' => [$provider => 'disabled'], 'skipped' => false]
                : $this->dispatch([$provider => $channel], [], $title, $message, $spaceId, 'scenario');
        }

        if (preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/', $target) !== 1) {
            throw new \InvalidArgumentException('Clé de profil de notification invalide.');
        }

        $targets = $this->notificationTargets($spaceId);
        $profile = $targets[$target] ?? null;

        if ($profile === null) {
            throw new \InvalidArgumentException('Profil de notification introuvable : ' . $target . '.');
        }

        if ($profile['type'] !== $provider) {
            throw new \InvalidArgumentException(
                'Le profil « ' . $target . ' » n’est pas de type ' . $provider . '.',
            );
        }

        try {
            $channel = $this->profileChannel($provider, $profile);
        } catch (\Throwable $exception) {
            $this->logWarning(
                'Le profil de notification est invalide.',
                ['space_id' => $spaceId, 'source' => $provider, 'group' => 'scenario', 'target' => $target],
                'AUTOMATION_NOTIFICATION_INVALID',
                'automation',
            );
            $this->logException($exception);

            throw new \InvalidArgumentException(
                'Le profil de notification « ' . $target . ' » est invalide.',
            );
        }

        return $this->dispatch(
            [$provider => $channel],
            [],
            $title,
            $message,
            $spaceId,
            'scenario',
            $target,
        );
    }

    /**
     * @param list<string>|null $only
     * @return array{sent: list<string>, failed: array<string, string>, skipped: bool}
     */
    private function send(
        int $spaceId,
        string $prefix,
        string $title,
        string $message,
        ?array $only,
    ): array {
        $config = $this->storedConfig($spaceId);
        $group = $prefix === '' ? 'failure' : 'scenario';
        $channels = [];
        $failed = [];

        foreach (self::CHANNELS as $name) {
            if ($only !== null && !in_array($name, $only, true)) {
                continue;
            }

            $channel = $this->buildChannel($name, $config, $prefix, $spaceId, $group);

            if ($channel === null) {
                if ($only !== null) {
                    $failed[$name] = 'disabled';
                }

                continue;
            }

            $channels[$name] = $channel;
        }

        return $this->dispatch($channels, $failed, $title, $message, $spaceId, $group);
    }

    /**
     * @param array<string, NotificationChannel> $channels
     * @param array<string, string> $failed
     * @return array{sent: list<string>, failed: array<string, string>, skipped: bool}
     */
    private function dispatch(
        array $channels,
        array $failed,
        string $title,
        string $message,
        int $spaceId,
        string $group,
        ?string $target = null,
    ): array {
        $sent = [];

        foreach ($channels as $name => $channel) {
            try {
                $channel->send($title, $message);
                $sent[] = $name;
            } catch (NotificationException $exception) {
                $failed[$name] = 'http_error';
                $this->logWarning(
                    'La notification n’a pas pu être envoyée.',
                    $this->failureContext($spaceId, $name, $group, $exception, $target),
                    'AUTOMATION_NOTIFICATION_FAILED',
                    'automation',
                );
            } catch (\Throwable $exception) {
                $failed[$name] = 'invalid';
                $context = ['space_id' => $spaceId, 'source' => $name, 'group' => $group];

                if ($target !== null) {
                    $context['target'] = $target;
                }

                $this->logWarning(
                    'La notification n’a pas pu être envoyée.',
                    $context,
                    'AUTOMATION_NOTIFICATION_INVALID',
                    'automation',
                );
                $this->logException($exception);
            }
        }

        return [
            'sent' => $sent,
            'failed' => $failed,
            'skipped' => $channels === [] && $failed === [],
        ];
    }

    /** @param array<string, mixed> $config */
    private function buildChannel(
        string $name,
        array $config,
        string $prefix,
        int $spaceId,
        string $group,
    ): ?NotificationChannel {
        if ((string) ($config[$prefix . $name . '_enabled'] ?? '0') !== '1') {
            return null;
        }

        try {
            if ($name === 'telegram') {
                $token = $this->decrypt(
                    (string) ($config[$prefix . 'telegram_token'] ?? ''),
                    $spaceId,
                    'telegram',
                    $group,
                );
                $chatId = (string) ($config[$prefix . 'telegram_chat_id'] ?? '');

                return $token === '' || $chatId === ''
                    ? null
                    : new TelegramChannel($token, $chatId);
            }

            $field = $name === 'slack' ? 'slack_webhook' : 'discord_webhook';
            $url = $this->decrypt(
                (string) ($config[$prefix . $field] ?? ''),
                $spaceId,
                $name,
                $group,
            );

            if ($url === '') {
                return null;
            }

            return $name === 'slack' ? new SlackChannel($url) : new DiscordChannel($url);
        } catch (\Throwable $exception) {
            $this->logWarning(
                'La notification n’a pas pu être configurée.',
                ['space_id' => $spaceId, 'source' => $name, 'group' => $group],
                'AUTOMATION_NOTIFICATION_INVALID',
                'automation',
            );
            $this->logException($exception);

            return null;
        }
    }

    /** @return array<string, array<string, string>> */
    private function notificationTargets(int $spaceId): array
    {
        $config = $this->storedConfig($spaceId);
        $raw = (string) ($config[NotificationSecrets::TARGETS_FIELD] ?? '');

        if ($raw === '') {
            return [];
        }

        try {
            $json = $this->coreCryptHelper->decrypt($raw, NotificationSecrets::TARGETS_PURPOSE);
        } catch (\Throwable $exception) {
            $this->logWarning(
                'Les profils de notification sont illisibles.',
                ['space_id' => $spaceId, 'group' => 'scenario'],
                'AUTOMATION_NOTIFICATION_SECRET_INVALID',
                'automation',
            );
            $this->logException($exception);

            throw new \InvalidArgumentException('Les profils de notification sont illisibles.');
        }

        return NotificationTargets::parse($json);
    }

    /** @param array<string, string> $profile */
    private function profileChannel(string $provider, array $profile): NotificationChannel
    {
        return match ($provider) {
            'telegram' => new TelegramChannel($profile['token'], $profile['chat_id']),
            'slack' => new SlackChannel($profile['webhook']),
            'discord' => new DiscordChannel($profile['webhook']),
        };
    }

    /** @return array<string, mixed> */
    private function storedConfig(int $spaceId): array
    {
        $row = $this->db->selectOneOrNull(
            'SELECT params FROM config WHERE space_id = % AND extension = % LIMIT 1',
            [$spaceId, 'plg_automation'],
        );

        if ($row === null) {
            return [];
        }

        $decoded = json_decode((string) $row->params, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function decrypt(string $value, int $spaceId, string $channel, string $group): string
    {
        if ($value === '') {
            return '';
        }

        try {
            return $this->coreCryptHelper->decrypt($value, NotificationSecrets::PURPOSE);
        } catch (\Throwable) {
            $this->logWarning(
                'Le secret de notification est illisible.',
                ['space_id' => $spaceId, 'source' => $channel, 'group' => $group],
                'AUTOMATION_NOTIFICATION_SECRET_INVALID',
                'automation',
            );

            return '';
        }
    }

    /** @return array<string, mixed> */
    private function failureContext(
        int $spaceId,
        string $channel,
        string $group,
        NotificationException $exception,
        ?string $target = null,
    ): array {
        $context = ['space_id' => $spaceId, 'source' => $channel, 'group' => $group];

        if ($target !== null) {
            $context['target'] = $target;
        }

        if ($exception->httpStatus > 0) {
            $context['http_status'] = $exception->httpStatus;
        }

        if ($exception->curlErrno > 0) {
            $context['curl_errno'] = $exception->curlErrno;
        }

        return $context;
    }
}
