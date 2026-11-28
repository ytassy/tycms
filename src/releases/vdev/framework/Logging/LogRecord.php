<?php

declare(strict_types=1);

namespace YTassy\Tycms\Logging;

use YTassy\Tycms\Response\ExceptionResponse;

/** Frontière commune à l'écriture et à la lecture des fichiers potentiellement altérés. */
final class LogRecord
{
    public const LEVELS = ['INFO', 'WARNING', 'ERROR'];
    public const MAX_BYTES = 16000;

    public static function context(array $values): array
    {
        $result = [];
        foreach (['space_id', 'user_id', 'provider_id', 'policy_space_id'] as $key) {
            if (is_int($values[$key] ?? null) && $values[$key] > 0) $result[$key] = $values[$key];
        }
        foreach ([
            'route', 'method', 'mode', 'kind', 'source', 'pattern', 'auth_method',
            'google_error', 'google_reason', 'google_operation',
            // Identifiants techniques du journal d'événements, des canaux de
            // notification et du CRON : valeurs contrôlées par le code, jamais
            // une entrée utilisateur.
            'event_type', 'channel', 'listener', 'extension', 'hook',
        ] as $key) {
            $value = $values[$key] ?? null;
            if (is_string($value) && preg_match('/\A[a-zA-Z0-9_.\[\]-]{1,100}\z/', $value)) $result[$key] = $value;
        }

        // Diagnostics réseau numériques seulement : aucun corps ni en-tête distant.
        foreach (['http_status' => [100, 599], 'curl_errno' => [1, 999], 'deleted' => [0, 10_000_000]] as $key => [$min, $max]) {
            $value = $values[$key] ?? null;
            if (is_int($value) && $value >= $min && $value <= $max) $result[$key] = $value;
        }

        // Le prénom du compte reste sur une ligne et borné, avec ses accents.
        $firstName = $values['first_name'] ?? null;
        if (is_string($firstName) && mb_check_encoding($firstName, 'UTF-8')) {
            $firstName = trim(mb_substr(preg_replace('/[\p{C}\p{Z}]+/u', ' ', $firstName) ?? '', 0, 100, 'UTF-8'));
            if ($firstName !== '') $result['first_name'] = $firstName;
        }
        return $result;
    }

    public static function isSuccessfulLogin(array $record): bool
    {
        return ($record['level'] ?? null) === 'INFO' && ($record['channel'] ?? null) === 'authentication'
            && ($record['code'] ?? null) === 'USER_LOGIN_SUCCEEDED';
    }

    public static function valid(mixed $record): bool
    {
        if (!is_array($record) || ($record['schema'] ?? null) !== 1
            || !in_array($record['level'] ?? null, self::LEVELS, true)) return false;
        foreach (['id', 'request_id'] as $key) {
            if (!is_string($record[$key] ?? null) || !preg_match('/\A[a-f0-9]{32}\z/', $record[$key])) return false;
        }
        foreach (['channel' => '/\A[a-z][a-z0-9_.]{0,60}\z/', 'code' => '/\A[A-Z][A-Z0-9_]{0,80}\z/',
            'timestamp' => '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z\z/'] as $key => $pattern) {
            if (!is_string($record[$key] ?? null) || !preg_match($pattern, $record[$key])) return false;
        }
        return is_string($record['message'] ?? null) && strlen($record['message']) <= 2000
            && is_array($record['context'] ?? null) && self::context($record['context']) === $record['context']
            && (!isset($record['context']['first_name']) || self::isSuccessfulLogin($record));
    }

    /** Parcourir les deux liens possibles, sans cycle et avec une borne explicite. */
    public static function causes(\Throwable $error): array
    {
        $queue = [$error];
        $seen = new \SplObjectStorage();
        $causes = [];
        while ($queue !== [] && count($causes) < 10) {
            $current = array_shift($queue);
            if (isset($seen[$current])) continue;
            $seen[$current] = true;
            $causes[] = $current;
            if ($current instanceof ExceptionResponse && $current->exception !== null) $queue[] = $current->exception;
            if ($current->getPrevious() !== null) $queue[] = $current->getPrevious();
        }
        return $causes;
    }

    /** Réduire chaque cause aux seules localisations de code, jamais ses arguments ou son message. */
    public static function exception(\Throwable $error, string $codeRoot): array
    {
        $result = [];
        foreach (self::causes($error) as $cause) {
            $frames = [['file' => $cause->getFile(), 'line' => $cause->getLine()], ...array_slice($cause->getTrace(), 0, 5)];
            $safe = [];
            foreach ($frames as $frame) {
                $file = $frame['file'] ?? '';
                $relative = str_starts_with($file, $codeRoot . '/') ? substr($file, strlen($codeRoot) + 1) : '';
                $safe[] = ['file' => self::safeFile($relative),
                    'line' => (int) ($frame['line'] ?? 0)];
            }
            $class = get_class($cause);
            $result[] = ['class' => preg_match('/\A[a-zA-Z0-9_\\\\]{1,200}\z/', $class) ? $class : '[anonymous]', 'frames' => $safe];
        }
        return $result;
    }

    private static function safeFile(string $relative): string
    {
        // Les points d'entrée updater contiennent un secret dans leur nom de fichier.
        return preg_match('~\A[a-zA-Z0-9_./-]{1,160}\z~', $relative)
            && !str_starts_with($relative, '/') && !str_contains($relative, '..')
            && !preg_match('~(?:^|/)(?:update_)?[a-f0-9]{32,}(?:[./]|$)~i', $relative)
            ? $relative : '[external]';
    }

    /** Aucune clé ajoutée manuellement à un fichier local n'est renvoyée par l'API. */
    public static function project(array $record): array
    {
        $result = array_intersect_key($record, array_flip(['schema', 'id', 'request_id', 'timestamp', 'level', 'channel', 'code', 'message', 'context']));
        $result['app_version'] = is_string($record['app_version'] ?? null)
            && preg_match('/\A[a-zA-Z0-9_.-]{1,50}\z/', $record['app_version']) ? $record['app_version'] : '';
        $result['exceptions'] = [];
        $result['truncated'] = ($record['truncated'] ?? false) === true;
        foreach (array_slice(is_array($record['exceptions'] ?? null) ? $record['exceptions'] : [], 0, 10) as $exception) {
            if (!is_array($exception) || !is_string($exception['class'] ?? null)
                || !preg_match('/\A[a-zA-Z0-9_\\\\\[\]]{1,200}\z/', $exception['class'])) continue;
            $frames = [];
            foreach (array_slice(is_array($exception['frames'] ?? null) ? $exception['frames'] : [], 0, 6) as $frame) {
                if (is_array($frame) && is_string($frame['file'] ?? null) && is_int($frame['line'] ?? null)) {
                    $frames[] = ['file' => self::safeFile($frame['file']), 'line' => $frame['line']];
                }
            }
            $result['exceptions'][] = ['class' => $exception['class'], 'frames' => $frames];
        }
        return $result;
    }
}
