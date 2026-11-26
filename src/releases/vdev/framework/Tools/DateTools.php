<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tools;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;

/**
 * Conversion des dates PHP vers la base.
 *
 * La connexion force `SET time_zone = '+00:00'` : MariaDB écrit, lit et compare
 * en UTC. Une date construite en PHP peut porter un autre fuseau ; la formater
 * directement avec `format('Y-m-d H:i:s')` écrirait une heure locale dans une
 * colonne UTC, et toute comparaison SQL deviendrait fausse.
 *
 * Ces méthodes sont le seul passage admis d'une date PHP vers la base.
 */
final class DateTools
{
    private const SQL_DATETIME = 'Y-m-d H:i:s';

    private const SQL_DATE = 'Y-m-d';

    /** Horodatage SQL UTC d'une date PHP, quel que soit son fuseau d'origine. */
    public static function toSql(DateTimeInterface $date): string
    {
        return self::utc($date)->format(self::SQL_DATETIME);
    }

    /**
     * Date seule SQL, pour une colonne `date`.
     *
     * Exception assumée : une colonne `date` porte un jour calendaire, pas un
     * instant. La convertir en UTC décalerait le jour (« 2026-06-15 » saisi à
     * Paris deviendrait « 2026-06-14 »). On conserve donc le jour tel que
     * l'objet le représente, sans changement de fuseau. Les colonnes
     * `datetime`, elles, portent un instant et passent par `toSql()`.
     */
    public static function toSqlDate(DateTimeInterface $date): string
    {
        return $date->format(self::SQL_DATE);
    }

    /**
     * Horodatage SQL d'une chaîne lue dans le fuseau PHP courant.
     *
     * Une chaîne sans fuseau (« 2026-06-15 20:30:00 ») est une heure locale ;
     * elle est convertie en UTC avant écriture. Retourne null si la chaîne
     * n'est pas une date exploitable.
     */
    public static function parseToSql(string $value): ?string
    {
        try {
            return self::toSql(new DateTimeImmutable($value));
        } catch (Throwable) {
            return null;
        }
    }

    private static function utc(DateTimeInterface $date): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $date->getTimestamp()))
            ->setTimezone(new DateTimeZone('UTC'));
    }
}
