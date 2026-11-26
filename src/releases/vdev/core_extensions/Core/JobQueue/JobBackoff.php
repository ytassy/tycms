<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\JobQueue;

/**
 * Calcul du délai avant nouvelle tentative.
 *
 * Délai exponentiel plafonné, avec une gigue pour éviter que des jobs échoués
 * au même instant repartent tous ensemble. Le calcul est pur et déterministe
 * hors gigue, donc testable sans base.
 */
final class JobBackoff
{
    /** Premier palier, en secondes. */
    public const BASE_SECONDS = 30;

    /** Plafond du délai, en secondes. */
    public const MAX_SECONDS = 3600;

    /** Gigue maximale, en pourcentage du délai calculé. */
    public const JITTER_PERCENT = 20;

    /**
     * @param int $attempts Nombre de tentatives déjà consommées (1 après la première).
     */
    public static function seconds(int $attempts): int
    {
        $steps = max(0, $attempts - 1);

        // 2^steps reste dans un entier raisonnable pour les bornes du projet,
        // mais on plafonne avant de multiplier pour éviter tout débordement.
        $delay = min(self::MAX_SECONDS, self::BASE_SECONDS * (2 ** min($steps, 20)));
        $jitter = (int) round($delay * (random_int(0, self::JITTER_PERCENT) / 100));

        return $delay + $jitter;
    }
}
