<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\ExtensionApi\Traits;

use YTassy\Tycms\CoreExts\Core\Cron\CronTick;

/**
 * Implémentations vides des hooks CRON.
 *
 * Une extension qui ne traite qu'une fréquence implémente ICronProvider et
 * utilise ce trait, puis surcharge uniquement les méthodes utiles.
 */
trait TraitCronProvider
{
    /** @return array<string, mixed> */
    public function onCronMinute(CronTick $tick): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function onCron15Min(CronTick $tick): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function onCronHour(CronTick $tick): array
    {
        return [];
    }
}
