<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\ExtensionApi;

use YTassy\Tycms\CoreExts\Core\Cron\CronTick;

/**
 * Contrat des extensions qui participent au CRON global.
 *
 * Utiliser TraitCronProvider pour obtenir des méthodes vides par défaut et ne
 * surcharger que les fréquences réellement utiles. Chaque méthode retourne un
 * bilan libre, agrégé dans la réponse de /cron.
 */
interface ICronProvider
{
    /** @return array<string, mixed> */
    public function onCronMinute(CronTick $tick): array;

    /** @return array<string, mixed> */
    public function onCron15Min(CronTick $tick): array;

    /** @return array<string, mixed> */
    public function onCronHour(CronTick $tick): array;
}
