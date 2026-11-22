<?php

namespace YTassy\Tycms\CoreExts\Security;

use RuntimeException;

/** Valeurs validées d’une politique ; aucune directive CSP libre n’est acceptée. */
final readonly class SecurityPolicy
{
    public const LIST_FIELDS = ['iframe_origins', 'image_origins', 'media_origins', 'style_origins', 'font_origins'];

    public array $values;

    public function __construct(array $values)
    {
        $this->values = self::normalize($values);
    }

    public static function defaults(): array
    {
        return ['iframe_mode' => 'all_https', 'iframe_origins' => '', 'image_origins' => '',
            'media_origins' => '', 'style_origins' => '', 'font_origins' => ''];
    }

    /** Contrat strict utilisé à l’enregistrement et à la lecture des valeurs persistées. */
    public static function normalize(array $values): array
    {
        if (array_diff_key($values, self::defaults()) !== [] || array_diff_key(self::defaults(), $values) !== []) {
            throw new RuntimeException('Les paramètres de sécurité sont incomplets ou inconnus. Appliquez les migrations nécessaires.');
        }
        if (!in_array($values['iframe_mode'], ['all_https', 'allowlist', 'self'], true)) {
            throw new RuntimeException('Le mode d’autorisation des iframes est invalide.');
        }
        foreach (self::LIST_FIELDS as $field) {
            $values[$field] = implode("\n", self::origins($values[$field]));
        }
        if (strlen(implode(" ", array_intersect_key($values, array_flip(self::LIST_FIELDS)))) > 6000) throw new RuntimeException('Les listes de sécurité cumulées sont limitées à 6000 octets.');
        return $values;
    }

    /** Une origine HTTPS exacte par ligne ; pas de joker, chemin, identifiant ou directive CSP. */
    public static function origins(mixed $text): array
    {
        if (!is_string($text) || strlen($text) > 16000) throw new RuntimeException('Une liste d’origines doit contenir au maximum 16000 octets.');
        $lines = preg_split('/\r\n|\r|\n/', $text);
        $origins = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $origin = self::httpsOrigin($line);
            $parts = parse_url($line);
            if ($origin === null || !in_array($parts['path'] ?? '', ['', '/'], true)
                || isset($parts['query']) || isset($parts['fragment'])) {
                throw new RuntimeException('Origine invalide : utilisez une origine HTTPS sans chemin ni joker, par exemple https://docs.google.com.');
            }
            $origins[$origin] = $origin;
        }
        if (count($origins) > 100) throw new RuntimeException('Une liste est limitée à 100 origines HTTPS.');
        return array_values($origins);
    }

    /** Extraire une origine sans différence d’interprétation entre PHP, le navigateur et la CSP. */
    public static function httpsOrigin(string $url): ?string
    {
        if (strlen($url) > 16000 || preg_match('/[\x00-\x20\x7f-\xff"\x27<>\\\\]/', $url)
            || filter_var($url, FILTER_VALIDATE_URL) === false) return null;
        $parts = parse_url($url);
        if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass'])) return null;

        $host = strtolower($parts['host']);
        // Les représentations IPv4 abrégées, hexadécimales et octales sont refusées.
        if (str_starts_with($host, '[')) {
            if (filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) return null;
            $host = '[' . inet_ntop(inet_pton(substr($host, 1, -1))) . ']';
        } elseif (preg_match('/^[0-9.]+$/D', $host)) {
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) return null;
        } elseif (strlen($host) > 253 || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $host)) {
            return null;
        }
        $port = $parts['port'] ?? 443;
        if ($port < 1 || $port > 65535) return null;
        return 'https://' . $host . ($port === 443 ? '' : ':' . $port);
    }

    /** Sources additionnelles ; le site lui-même et les besoins internes restent gérés par le code. */
    public function sources(string $resource): array
    {
        if ($resource === 'iframe') {
            return match ($this->values['iframe_mode']) {
                'all_https' => ['https:'],
                'self' => [],
                'allowlist' => self::origins($this->values['iframe_origins']),
            };
        }
        return self::origins($this->values[$resource . '_origins']);
    }

    public function allowsFrame(string $url, ?string $siteOrigin): bool
    {
        $origin = self::httpsOrigin($url);
        if ($origin === null) return false;
        if ($origin === $siteOrigin || $this->values['iframe_mode'] === 'all_https') return true;
        return in_array($origin, $this->sources('iframe'), true);
    }

    public function iframeDescription(): string
    {
        return match ($this->values['iframe_mode']) {
            'all_https' => 'Domaines autorisés : tous les domaines HTTPS.',
            'self' => 'Domaines autorisés : uniquement l’origine HTTPS du site.',
            'allowlist' => 'Origines HTTPS autorisées : l’origine du site'
                . ($this->values['iframe_origins'] === '' ? ' uniquement.' : ' et ' . implode(', ', $this->sources('iframe')) . '.'),
        };
    }

    public function previewSources(): array
    {
        $sources = [];
        foreach (['iframe', 'image', 'media', 'style', 'font'] as $resource) $sources[$resource] = $this->sources($resource);
        return $sources;
    }
}
