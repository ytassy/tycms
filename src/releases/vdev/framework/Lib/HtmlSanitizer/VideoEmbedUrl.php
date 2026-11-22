<?php

namespace YTassy\Tycms\Lib\HtmlSanitizer;

/** URL de lecteurs canoniques, partagées par le texte riche et les composants vidéo. */
final class VideoEmbedUrl
{
    public static function normalize(string $url): ?string
    {
        return self::normalizeYouTubeEmbedUrl($url) ?? self::normalizeVimeoEmbedUrl($url);
    }

    private static function normalizeYouTubeEmbedUrl(string $url): ?string
    {
        $parts = parse_url($url);

        if ($parts === false) {
            return null;
        }

        if (strtolower($parts['scheme'] ?? '') !== 'https') {
            return null;
        }

        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';
        $videoId = null;

        if (in_array($host, ['www.youtube-nocookie.com', 'www.youtube.com', 'youtube.com', 'm.youtube.com'], true)) {
            if (preg_match('~^/(?:embed|shorts)/([A-Za-z0-9_-]{11})$~', $path, $matches) === 1) {
                $videoId = $matches[1];
            } elseif ($path === '/watch') {
                parse_str($parts['query'] ?? '', $query);
                $videoId = is_string($query['v'] ?? null) ? $query['v'] : null;
            }
        } elseif ($host === 'youtu.be' && preg_match('~^/([A-Za-z0-9_-]{11})$~', $path, $matches) === 1) {
            $videoId = $matches[1];
        }

        if (!is_string($videoId) || preg_match('~^[A-Za-z0-9_-]{11}$~', $videoId) !== 1) {
            return null;
        }

        return 'https://www.youtube.com/embed/' . $videoId;
    }

    private static function normalizeVimeoEmbedUrl(string $url): ?string
    {
        $parts = parse_url($url);

        if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'https') {
            return null;
        }

        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';
        $videoId = null;

        if ($host === 'player.vimeo.com' && preg_match('~^/video/([1-9][0-9]{0,19})$~', $path, $matches) === 1) {
            $videoId = $matches[1];
        } elseif (in_array($host, ['vimeo.com', 'www.vimeo.com'], true) && preg_match('~^/([1-9][0-9]{0,19})$~', $path, $matches) === 1) {
            $videoId = $matches[1];
        }

        return $videoId === null ? null : 'https://player.vimeo.com/video/' . $videoId;
    }
}
