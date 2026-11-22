<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Helper\Fonts;

use RuntimeException;

/** Transport borné : aucune URL libre ni redirection ne peut atteindre le réseau. */
class FontDownload
{
    public function get(string $url, int $limit, int $timeout = 15): string
    {
        self::assertUrl($url);
        $body = '';
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // Une réponse WOFF2 reproductible, indépendante du navigateur de l'administrateur.
            CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, $limit): int {
                if (strlen($body) + strlen($chunk) > $limit) {
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);

        try {
            $ok = curl_exec($curl);
            if ($ok === false || curl_getinfo($curl, CURLINFO_HTTP_CODE) !== 200 || $body === '') {
                throw new RuntimeException('Impossible de télécharger la police ou sa licence dans les limites autorisées.');
            }
            return $body;
        } finally {
            curl_close($curl);
        }
    }

    public static function assertUrl(string $url): void
    {
        // Les chemins des licences sont épinglés par le catalogue livré avec Core.
        $valid = in_array($url, [FontCatalog::METADATA_URL, FontCatalog::TREE_URL], true)
            || preg_match('~\Ahttps://fonts\.googleapis\.com/css2\?family=[A-Za-z0-9%+&:;=.,@_-]+\z~D', $url)
            || preg_match('~\Ahttps://fonts\.gstatic\.com/s/[A-Za-z0-9/_-]+\.woff2\z~D', $url)
            || preg_match('~\Ahttps://raw\.githubusercontent\.com/google/fonts/[a-f0-9]{40}/(?:ofl|apache|ufl)/[a-z0-9]+/(?:OFL\.txt|LICENSE\.txt|LICENCE\.txt|NOTICE(?:\.txt)?)\z~D', $url);
        if (!$valid) {
            throw new RuntimeException('Source de police non autorisée.');
        }
    }

    /** Ne conserve que les descripteurs nécessaires ; aucun CSS distant n'est exécuté. */
    public static function parseCss(string $css): array
    {
        preg_match_all('/@font-face\s*\{([^{}]+)\}/', $css, $blocks);
        if (count($blocks[1]) === 0 || count($blocks[1]) > 256) {
            throw new RuntimeException('La feuille de police est vide ou dépasse les limites autorisées.');
        }
        $faces = [];
        foreach ($blocks[1] as $block) {
            if (!preg_match('~src:\s*url\((https://fonts\.gstatic\.com/s/[A-Za-z0-9/_-]+\.woff2)\)~', $block, $url)
                || !preg_match('/font-style:\s*(normal|italic)\s*;/', $block, $style)
                || !preg_match('/font-weight:\s*([1-9][0-9]{0,2}(?:\s+[1-9][0-9]{0,2})?)\s*;/', $block, $weight)) {
                throw new RuntimeException('Format de police distante non pris en charge.');
            }
            self::assertUrl($url[1]);
            $range = '';
            if (preg_match('/unicode-range:\s*([^;]+);/', $block, $match)) {
                $range = trim($match[1]);
                if (!preg_match('/\AU\+[0-9A-F?]{1,6}(?:-[0-9A-F]{1,6})?(?:,\s*U\+[0-9A-F?]{1,6}(?:-[0-9A-F]{1,6})?)*\z/i', $range)) {
                    throw new RuntimeException('Jeu de caractères distant invalide.');
                }
            }
            $faces[] = ['url' => $url[1], 'style' => $style[1], 'weight' => $weight[1], 'range' => $range];
        }
        return $faces;
    }
}
