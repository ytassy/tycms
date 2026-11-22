<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Helper\Extension;

/** Règles pures de compatibilité d'un paquet avec le CMS et PHP. */
final class CatalogCompatibility
{
    /** @param array<string, mixed> $metadata */
    public static function compatible(array $metadata, string $appVersion, string $phpVersion): ?bool
    {
        // La release dev ne fournit pas de numéro comparable ; ne pas inventer un refus.
        if ($appVersion === 'dev') {
            return null;
        }
        foreach ([
            ['cms_min', 'cms_max_exclusive', $appVersion],
            ['php_min', 'php_max_exclusive', $phpVersion],
        ] as [$minimum, $maximum, $current]) {
            $min = $metadata[$minimum] ?? null;
            $max = $metadata[$maximum] ?? null;
            if (!is_string($min) || !is_string($max)
                || version_compare($current, $min, '<') || version_compare($current, $max, '>=')) {
                return false;
            }
        }

        return true;
    }

    public static function sourceAllows(mixed $sourceVersions, string $localVersion): bool
    {
        if ($sourceVersions === '*') {
            return true;
        }

        return is_array($sourceVersions) && in_array($localVersion, $sourceVersions, true);
    }
}
