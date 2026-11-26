<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\JobQueue;

use JsonException;
use YTassy\Tycms\Exceptions\TechException;

/**
 * Sérialisation du payload d'un job.
 *
 * Le payload porte des références, jamais une autorité : identifiants, clés de
 * modèle, options simples. Il ne contient ni secret ni entité complète, et sa
 * taille est bornée pour qu'une ligne de file reste petite et lisible.
 */
final class JobPayload
{
    /** Taille maximale du JSON encodé, en octets. */
    public const MAX_BYTES = 32768;

    /** Profondeur maximale acceptée au décodage, pour ne pas suivre une structure abusive. */
    private const MAX_DEPTH = 32;

    /**
     * @param array<string, mixed> $payload
     *
     * @throws TechException Si le payload n'est pas sérialisable ou trop grand.
     */
    public static function encode(array $payload): string
    {
        try {
            $json = json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException $exception) {
            throw new TechException('Le payload du job n’est pas sérialisable en JSON.', previous: $exception);
        }

        if (strlen($json) > self::MAX_BYTES) {
            throw new TechException(sprintf(
                'Le payload du job dépasse la limite de %d octets.',
                self::MAX_BYTES,
            ));
        }

        return $json;
    }

    /**
     * Décode un payload stocké. Un contenu invalide retourne null : le worker
     * marque alors le job en échec avec un code dédié au lieu de planter.
     *
     * @return array<string, mixed>|null
     */
    public static function decode(string $payload): ?array
    {
        try {
            $decoded = json_decode($payload, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
