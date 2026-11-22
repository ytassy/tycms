<?php

namespace YTassy\Tycms\CoreExts\Security\Waf;

use YTassy\Tycms\Request\Request;

/** Parcours borné, sans aplatir des champs distincts ni réécrire la requête. */
final class PatternScanner
{
    public const MAX_VALUE_BYTES = 131072;
    public const MAX_TOTAL_BYTES = 524288;
    public const MAX_NODES = 1024;
    public const MAX_DEPTH = 20;
    public const MAX_CHECKS = 10000;
    private int $bytes = 0;
    private int $nodes = 0;
    private int $checks = 0;
    private int $started;

    /** Retourner un seul événement, sans conserver les valeurs ni les fragments détectés. */
    public function scan(Request $request, object $patterns): ?array
    {
        $this->bytes = $this->nodes = $this->checks = 0;
        $this->started = hrtime(true);
        foreach (['path' => $request->path, 'query' => $request->query, 'body' => $request->body] as $source => $value) {
            $event = $this->visit($value, $source, $patterns, 0);
            if ($event !== null) return $event;
        }
        return null;
    }

    private function visit(mixed $value, string $source, object $patterns, int $depth): ?array
    {
        if (++$this->nodes > self::MAX_NODES || $depth > self::MAX_DEPTH) return $this->event('limit', $source);
        if (is_array($value) || $value instanceof \stdClass) {
            $index = 0;
            foreach ($value as $key => $child) {
                // Les indices de parcours restent utiles au diagnostic sans journaliser des clés sensibles.
                $location = $source . '[' . $index++ . ']';
                $event = $this->visit((string) $key, $location . '.key', $patterns, $depth + 1)
                    ?? $this->visit($child, $location, $patterns, $depth + 1);
                if ($event !== null) return $event;
            }
            return null;
        }
        if ($value === null) return null;
        if (!is_scalar($value)) return $this->event('limit', $source);
        $text = (string) $value;
        $variants = [$text];
        if (str_contains($text, '%')) {
            $decoded = rawurldecode($text);
            if ($decoded !== $text) $variants[] = $decoded;
        }

        // Examiner les variantes séparément, jamais leur concaténation artificielle.
        foreach ($variants as $variant) {
            $length = strlen($variant);
            $this->bytes += $length;
            if ($length > self::MAX_VALUE_BYTES || $this->bytes > self::MAX_TOTAL_BYTES) return $this->event('limit', $source);
            foreach ($patterns->strings as $index => $literal) {
                if ($this->overBudget()) return $this->event('limit', $source);
                if (stripos($variant, $literal) !== false) return $this->event('match', $source, 'string_' . $index);
            }
            foreach (get_object_vars($patterns->regexes) as $name => $regex) {
                if ($this->overBudget()) return $this->event('limit', $source);
                $result = BoundedRegex::match($regex, $variant);
                if ($result === false) return $this->event('regex_error', $source, $name);
                if ($result === 1) return $this->event('match', $source, $name);
            }
        }
        return null;
    }

    private function overBudget(): bool
    {
        // Ce budget est vérifié entre les comparaisons, pas une interruption du moteur PCRE en cours.
        return ++$this->checks > self::MAX_CHECKS || hrtime(true) - $this->started > 50000000;
    }

    private function event(string $kind, string $source, ?string $pattern = null): array
    {
        return ['kind' => $kind, 'source' => $source, 'pattern' => $pattern];
    }
}
