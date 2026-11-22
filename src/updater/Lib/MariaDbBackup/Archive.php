<?php

declare(strict_types=1);

namespace Tycms\Updater\Lib\MariaDbBackup;

use RuntimeException;

/** Format JSONL privé : les octets SQL et les données binaires sont encodés en base64. */
final class Archive
{
    public const FORMAT = 'tycms-mariadb-backup';
    public const VERSION = 1;

    /** @param resource $handle */
    public static function write($handle, array $record): void
    {
        $line = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        $offset = 0;
        while ($offset < strlen($line)) {
            $written = fwrite($handle, substr($line, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Écriture de sauvegarde incomplète.');
            }
            $offset += $written;
        }
    }

    /** @param resource $handle */
    public static function read($handle): ?array
    {
        $line = fgets($handle);
        if ($line === false) {
            if (!feof($handle)) throw new RuntimeException('Lecture de sauvegarde interrompue.');
            return null;
        }
        if (!str_ends_with($line, "\n")) throw new RuntimeException('Sauvegarde tronquée.');
        try {
            $record = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('Enregistrement de sauvegarde invalide.');
        }
        if (!is_array($record) || array_is_list($record)) {
            throw new RuntimeException('Enregistrement de sauvegarde invalide.');
        }
        return $record;
    }

    public static function decode(mixed $value): string
    {
        if (!is_string($value) || ($decoded = base64_decode($value, true)) === false
            || base64_encode($decoded) !== $value) {
            throw new RuntimeException('Données de sauvegarde invalides.');
        }
        return $decoded;
    }
}
