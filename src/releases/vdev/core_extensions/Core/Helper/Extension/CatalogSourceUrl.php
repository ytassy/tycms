<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Helper\Extension;

use RuntimeException;

/** Normalisation bornée d'une URL de base de catalogue externe. */
final class CatalogSourceUrl
{
    public static function normalize(string $url, bool $dev): string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 500) {
            throw new RuntimeException('L’URL du catalogue est obligatoire et limitée à 500 caractères.');
        }

        $parts = parse_url($url);
        $host = is_array($parts) ? strtolower(trim((string) ($parts['host'] ?? ''), '[]')) : '';
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $local = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        $allowedSchemes = $local && $dev ? ['https', 'http'] : ['https'];
        $path = is_array($parts) ? (string) ($parts['path'] ?? '') : '';

        if (!is_array($parts) || $host === ''
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || !in_array($scheme, $allowedSchemes, true)
            || (isset($parts['port']) && !$local && (int) $parts['port'] !== 443)
            || preg_match('/[\x00-\x20\x7f\\\\]/', $path) === 1) {
            throw new RuntimeException(
                'L’URL du catalogue doit être absolue, en HTTPS, sans identifiant, requête ni fragment.',
            );
        }

        // Un chemin de base ne doit pas pouvoir sortir de son préfixe.
        foreach (explode('/', trim($path, '/')) as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new RuntimeException('Le chemin de l’URL du catalogue est invalide.');
            }
        }

        $authority = $host;
        if (isset($parts['port'])) {
            $authority .= ':' . (int) $parts['port'];
        }

        $normalized = $scheme . '://' . $authority . rtrim($path, '/');
        if (strlen($normalized) > 500) {
            throw new RuntimeException('L’URL normalisée du catalogue dépasse 500 caractères.');
        }

        return $normalized;
    }

    /**
     * Même origine HTTP : schéma, hôte et port effectif.
     *
     * La licence n'est transmise qu'à cette condition. Une redirection ou une
     * URL de CDN externe ne reçoit donc jamais l'en-tête de licence.
     */
    public static function sameOrigin(string $baseUrl, string $url): bool
    {
        $base = parse_url($baseUrl);
        $target = parse_url($url);
        if (!is_array($base) || !is_array($target)) {
            return false;
        }

        $origin = static function (array $parts): ?array {
            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
            if (!in_array($scheme, ['https', 'http'], true) || $host === '') {
                return null;
            }

            $port = isset($parts['port'])
                ? (int) $parts['port']
                : ($scheme === 'https' ? 443 : 80);

            return [$scheme, $host, $port];
        };

        $baseOrigin = $origin($base);
        $targetOrigin = $origin($target);

        return $baseOrigin !== null && $targetOrigin !== null && $baseOrigin === $targetOrigin;
    }
}
