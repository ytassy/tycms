<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Schedule;

/**
 * Calcul des créneaux de planification dans le fuseau du Space.
 *
 * Le worker ne rattrape pas les créneaux manqués : il compare l'heure locale
 * courante au preset et n'exécute que le créneau en cours. L'éditeur utilise
 * le même calculateur pour afficher la prochaine exécution, afin que les deux
 * chemins ne divergent pas.
 */
final class ScheduleCalculator
{
    public static function timezone(string $timezone): \DateTimeZone
    {
        try {
            return new \DateTimeZone($timezone);
        } catch (\Throwable) {
            return new \DateTimeZone('UTC');
        }
    }

    /** Fuseau IANA du Space, stocké dans le JSON de ses paramètres. */
    public static function spaceTimezone(mixed $params): string
    {
        if (is_string($params)) {
            $decoded = json_decode($params, true);
            $params = is_array($decoded) ? $decoded : [];
        }

        $timezone = is_array($params) && is_string($params['timezone'] ?? null)
            ? $params['timezone']
            : date_default_timezone_get();

        return in_array($timezone, timezone_identifiers_list(), true)
            ? $timezone
            : 'UTC';
    }

    public static function now(string $timezone): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', self::timezone($timezone));
    }

    /**
     * Créneau correspondant à la minute courante, ou null si le preset n'est
     * pas dû maintenant.
     *
     * @param array<string, mixed> $config
     */
    public static function dueSlot(
        array $config,
        string $timezone,
        ?\DateTimeImmutable $now = null,
    ): ?\DateTimeImmutable {
        $values = ScheduleConfig::normalize($config);
        $zone = self::timezone($timezone);
        $current = ($now ?? new \DateTimeImmutable('now', $zone))->setTimezone($zone);
        $hour = (int) $current->format('G');
        $minute = (int) $current->format('i');

        switch ($values['preset']) {
            case ScheduleConfig::EVERY_15_MINUTES:
                if ($minute % 15 !== 0) {
                    return null;
                }

                return $current->setTime($hour, $minute, 0);

            case ScheduleConfig::HOURLY:
                if ($minute !== 0) {
                    return null;
                }

                return $current->setTime($hour, 0, 0);

            case ScheduleConfig::DAILY:
                if ($current->format('H:i') !== $values['time']) {
                    return null;
                }

                return self::atTime($current, $values['time']);

            case ScheduleConfig::WEEKLY:
                if ((int) $current->format('N') !== $values['weekday']
                    || $current->format('H:i') !== $values['time']) {
                    return null;
                }

                return self::atTime($current, $values['time']);
        }

        return null;
    }

    /**
     * Prochaine occurrence strictement postérieure à l'instant fourni.
     *
     * @param array<string, mixed> $config
     */
    public static function nextRun(
        array $config,
        string $timezone,
        ?\DateTimeImmutable $now = null,
    ): \DateTimeImmutable {
        $values = ScheduleConfig::normalize($config);
        $zone = self::timezone($timezone);
        $current = ($now ?? new \DateTimeImmutable('now', $zone))->setTimezone($zone);
        $current = $current->setTime((int) $current->format('G'), (int) $current->format('i'), 0);

        switch ($values['preset']) {
            case ScheduleConfig::EVERY_15_MINUTES:
                $slot = $current->setTime(
                    (int) $current->format('G'),
                    intdiv((int) $current->format('i'), 15) * 15,
                    0,
                );

                return $slot->modify('+15 minutes');

            case ScheduleConfig::HOURLY:
                return $current->setTime((int) $current->format('G'), 0, 0)->modify('+1 hour');

            case ScheduleConfig::DAILY:
                $candidate = self::atTime($current, $values['time']);

                // L'heure du jour est déjà passée : on vise le lendemain.
                return $candidate > $current ? $candidate : $candidate->modify('+1 day');

            case ScheduleConfig::WEEKLY:
                $candidate = self::atTime($current, $values['time']);
                $delta = ($values['weekday'] - (int) $candidate->format('N') + 7) % 7;
                $candidate = $candidate->modify('+' . $delta . ' days');

                // Même jour mais heure passée : la semaine suivante.
                return $candidate > $current ? $candidate : $candidate->modify('+7 days');
        }

        return $current;
    }

    /** Libellé stable du créneau, sans décalage, utilisé dans la clé de déduplication. */
    public static function slotLabel(\DateTimeImmutable $slot): string
    {
        return $slot->format('Y-m-d\TH:i');
    }

    /** Date ISO 8601 transmise dans $input, avec le décalage du Space. */
    public static function scheduledAt(\DateTimeImmutable $slot): string
    {
        return $slot->format(DATE_ATOM);
    }

    private static function atTime(\DateTimeImmutable $day, string $time): \DateTimeImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', $time, 2));

        // PHP normalise une heure locale inexistante (passage à l'heure d'été).
        return $day->setTime($hour, $minute, 0);
    }

    private function __construct()
    {
    }
}
