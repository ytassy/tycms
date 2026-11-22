<?php

namespace YTassy\Tycms\CoreExts\Core\Helper\ShortCode;

final class DiagramShortCode
{
    public static function replace(string $html, ?object $params = null, ?callable $protect = null): string
    {
        $render = static function (array $matches) use ($params, $protect): string {
            $html = self::render($matches['source'], $matches['options'], $params);
            return $protect === null ? $html : $protect($html);
        };

        $html = preg_replace_callback(
            '~<(p|div)\b[^>]*>\s*\[\[DIAGRAM(?<options>(?:\s*,[^\[\]]*)?)\s*\]\]\s*</\1>(?<source>.*?)<(p|div)\b[^>]*>\s*\[\[/DIAGRAM\]\]\s*</\4>~is',
            $render,
            $html
        ) ?? $html;
        $html = preg_replace_callback(
            '~<(p|div)\b[^>]*>\s*\[\[DIAGRAM(?<options>(?:\s*,[^\[\]]*)?)\s*\]\](?<source>.*?)\[\[/DIAGRAM\]\]\s*</\1>~is',
            $render,
            $html
        ) ?? $html;

        return preg_replace_callback(
            '~\[\[DIAGRAM(?<options>(?:\s*,[^\[\]]*)?)\s*\]\](?<source>.*?)\[\[/DIAGRAM\]\]~is',
            $render,
            $html
        ) ?? $html;
    }

    public static function getDefaultOptions(?object $params = null): array
    {
        $get = static fn(string $name, string $fallback): string => (string) ($params?->$name ?? $fallback);

        $width = strtolower(trim($get('mermaid_default_width', '95%')));
        $align = strtolower(trim($get('mermaid_default_align', 'center')));
        $look = strtolower(trim($get('mermaid_default_look', 'auto')));
        $theme = strtolower(trim($get('mermaid_default_theme', 'auto')));
        $sketch = trim($get('mermaid_default_sketch', '1'));
        $wrapWidth = trim($get('mermaid_default_wrapwidth', '120'));

        return [
            'width' => self::validWidth($width) ? $width : '95%',
            'align' => in_array($align, ['left', 'center', 'right'], true) ? $align : 'center',
            'look' => self::normalizeLook($look) ?? 'auto',
            'theme' => self::normalizeTheme($theme) ?? 'auto',
            'sketch' => in_array($sketch, ['0', '1'], true) ? $sketch : '1',
            'wrapwidth' => self::validWrapWidth($wrapWidth) ? $wrapWidth : '120',
        ];
    }

    private static function validWrapWidth(string $value): bool
    {
        return ctype_digit($value) && (int) $value >= 40 && (int) $value <= 1000;
    }

    private static function validWidth(string $value): bool
    {
        return (bool) preg_match('/^([1-9][0-9]*)(px|%)$/', $value, $size)
            && (int) $size[1] <= ($size[2] === '%' ? 100 : 10000);
    }

    private static function normalizeLook(string $value): ?string
    {
        return match (strtolower($value)) {
            'auto' => 'auto',
            'classic' => 'classic',
            'handdrawn' => 'handDrawn',
            'neo' => 'neo',
            default => null,
        };
    }

    private static function normalizeTheme(string $value): ?string
    {
        $value = strtolower($value);
        return in_array($value, [
            'auto', 'default', 'base', 'dark', 'forest', 'neutral', 'neo', 'neo-dark',
            'redux', 'redux-dark', 'redux-color', 'redux-dark-color',
        ], true) ? $value : null;
    }

    // Les options éditoriales restent une liste fermée de valeurs contrôlées.
    private static function parseOptions(string $raw, ?object $params): array
    {
        $options = self::getDefaultOptions($params);
        if (trim($raw) === '') return $options;

        $seen = [];

        foreach (explode(',', substr(ltrim($raw), 1)) as $option) {
            if (!preg_match('/^\s*(width|align|look|theme|sketch|wrapwidth)\s*=\s*([A-Za-z0-9.%-]+)\s*$/i', $option, $matches)) {
                throw new \InvalidArgumentException('Option de diagramme invalide : ' . trim($option));
            }

            $name = strtolower($matches[1]);
            $value = strtolower($matches[2]);
            if (isset($seen[$name])) {
                throw new \InvalidArgumentException('Option de diagramme répétée : ' . $name);
            }
            $seen[$name] = true;

            if ($name === 'width') {
                if (!self::validWidth($value)) {
                    throw new \InvalidArgumentException('La largeur doit être comprise entre 1 et 10000 px ou entre 1 et 100 %.');
                }
                $options['width'] = $value;
            } elseif ($name === 'align') {
                if (!in_array($value, ['left', 'center', 'right'], true)) {
                    throw new \InvalidArgumentException('L’alignement doit être left, center ou right.');
                }
                $options['align'] = $value;
            } elseif ($name === 'look') {
                $look = self::normalizeLook($value);
                if ($look === null) {
                    throw new \InvalidArgumentException('Le rendu doit être auto, classic, handDrawn ou neo.');
                }
                $options['look'] = $look;
            } elseif ($name === 'theme') {
                $theme = self::normalizeTheme($value);
                if ($theme === null) {
                    throw new \InvalidArgumentException('Thème Mermaid inconnu : ' . $value);
                }
                $options['theme'] = $theme;
            } elseif ($name === 'sketch') {
                if (!in_array($value, ['0', '1'], true)) {
                    throw new \InvalidArgumentException('L’option sketch doit être 0 ou 1.');
                }
                $options['sketch'] = $value;
            } else {
                if (!self::validWrapWidth($value)) {
                    throw new \InvalidArgumentException('La largeur de retour à la ligne doit être comprise entre 40 et 1000 px.');
                }
                $options['wrapwidth'] = $value;
            }
        }

        return $options;
    }

    // TinyMCE peut entourer chaque ligne de balises ; conserver les retraits du diagramme.
    private static function normalizeSource(string $html): string
    {
        $html = preg_replace('~<\s*br\b[^>]*>~i', "\n", $html);
        $html = preg_replace('~</\s*(?:p|div|li|pre|h[1-6])\s*>~i', "\n", $html);
        $source = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $source = str_replace(["\xc2\xa0", "\xe2\x80\xaf", "\r\n", "\r"], [' ', ' ', "\n", "\n"], $source);
        $source = preg_replace('/\n[ \t]*\n+/u', "\n", $source) ?? $source;

        return trim($source, "\n");
    }

    private static function render(string $html, string $rawOptions, ?object $params): string
    {
        $source = self::normalizeSource($html);
        if ($source === '' || strlen($source) > 30000) {
            return '<div class="alert alert-warning">Diagramme vide ou trop volumineux.</div>';
        }

        try {
            $options = self::parseOptions($rawOptions, $params);
            return self::renderSource($source, (object) $options);
        } catch (\Throwable) {
            $message = '<p>Impossible de préparer le diagramme.</p>';

            return '<div class="alert alert-warning">' . $message . '</div>';
        }
    }
    /** Valider des surcharges structurées ; une propriété absente conserve le défaut du Space. */
    public static function resolveOptions(object $overrides, ?object $params = null): array
    {
        $parts = [];
        foreach (get_object_vars($overrides) as $name => $value) {
            if (!array_key_exists($name, self::getDefaultOptions()) || (!is_string($value) && !is_int($value)) || !preg_match('/^[A-Za-z0-9.%-]+$/D', (string) $value)) {
                throw new \InvalidArgumentException('Option de diagramme invalide : ' . $name);
            }
            $parts[] = $name . '=' . $value;
        }
        return self::parseOptions($parts === [] ? '' : ',' . implode(',', $parts), $params);
    }

    /** La source structurée est du texte Mermaid : aucune normalisation HTML ne doit l'altérer. */
    public static function renderSource(string $source, object $overrides, ?object $params = null): string
    {
        if (trim($source) === '' || strlen($source) > 30000) return '';
        $options = self::resolveOptions($overrides, $params);
        $width = $options['width'];
        $align = $options['align'];
        $marginLeft = $align === 'left' ? '0' : 'auto';
        $marginRight = $align === 'right' ? '0' : 'auto';
        $style = 'width:' . $width . ';max-width:100%;margin-left:' . $marginLeft
            . ';margin-right:' . $marginRight;
        $id = 'tytools-mermaid-' . bin2hex(random_bytes(8));
        $showDetails = '0';

        return '<div id="' . $id . '" class="tytools-mermaid" style="' . $style
            . '" data-shortcode-diagram="1" data-show-details="' . $showDetails . '" data-mermaid-look="' . $options['look']
            . '" data-mermaid-theme="' . $options['theme']
            . '" data-mermaid-sketch="' . $options['sketch']
            . '" data-mermaid-wrap-width="' . $options['wrapwidth'] . '">'
            // Attribut booléen sans valeur : la sortie reste identique après un nouveau
            // passage dans le parseur HTML, quelle que soit la version de libxml.
            . '<pre class="tytools-mermaid-source" hidden>'
            . htmlspecialchars($source, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</pre><div class="tytools-mermaid-output" role="img" aria-label="Diagramme Mermaid">Diagramme Mermaid : activez JavaScript pour l’afficher.</div></div>';
    }

}
