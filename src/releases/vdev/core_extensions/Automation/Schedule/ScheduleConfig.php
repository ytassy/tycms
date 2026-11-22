<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Schedule;

/** Presets de planification et validation de leur configuration. */
final class ScheduleConfig
{
    public const EVERY_15_MINUTES = 'every_15_minutes';
    public const HOURLY = 'hourly';
    public const DAILY = 'daily';
    public const WEEKLY = 'weekly';

    /** @return list<string> */
    public static function presets(): array
    {
        return [self::EVERY_15_MINUTES, self::HOURLY, self::DAILY, self::WEEKLY];
    }

    /** @return array<string, mixed> */
    public static function decode(?string $json): array
    {
        if ($json === null || trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) && !array_is_list($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $existing
     * @return array{preset: string, time: string, weekday: int}
     */
    public static function normalize(mixed $input, array $existing = []): array
    {
        $values = is_object($input)
            ? get_object_vars($input)
            : (is_array($input) ? $input : []);
        $preset = $values['preset'] ?? $existing['preset'] ?? self::DAILY;

        if (!is_string($preset) || !in_array($preset, self::presets(), true)) {
            throw new \InvalidArgumentException('Preset de planification invalide.');
        }

        $time = $values['time'] ?? $existing['time'] ?? '09:00';

        if (!is_string($time) || preg_match('/\A([01]\d|2[0-3]):[0-5]\d\z/', $time) !== 1) {
            throw new \InvalidArgumentException('Heure de planification invalide.');
        }

        $weekday = $values['weekday'] ?? $existing['weekday'] ?? 1;

        if (is_string($weekday) && ctype_digit($weekday)) {
            $weekday = (int) $weekday;
        }

        if (!is_int($weekday) || $weekday < 1 || $weekday > 7) {
            throw new \InvalidArgumentException('Jour de planification invalide.');
        }

        return [
            'preset' => $preset,
            'time' => $time,
            'weekday' => $weekday,
        ];
    }

    private function __construct()
    {
    }
}
