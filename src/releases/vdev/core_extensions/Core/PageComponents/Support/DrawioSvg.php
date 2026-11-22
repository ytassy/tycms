<?php

namespace YTassy\Tycms\CoreExts\Core\PageComponents\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;
use YTassy\Tycms\Response\ExceptionResponse;

/** Contrôle des SVG rééditables, destinés exclusivement à une balise img. */
final class DrawioSvg
{
    private const SVG_NS = 'http://www.w3.org/2000/svg';
    private const HTML_NS = 'http://www.w3.org/1999/xhtml';
    private const SVG_ELEMENTS = ['svg', 'g', 'defs', 'title', 'desc', 'path', 'rect', 'circle', 'ellipse',
        'line', 'polyline', 'polygon', 'text', 'tspan', 'textPath', 'image', 'use', 'symbol', 'switch',
        'foreignObject', 'a', 'clipPath', 'mask', 'pattern', 'marker', 'linearGradient', 'radialGradient',
        'stop', 'filter', 'feGaussianBlur', 'feOffset', 'feFlood', 'feComposite', 'feBlend',
        'feColorMatrix', 'feMerge', 'feMergeNode', 'feDropShadow', 'style', 'view'];
    private const HTML_ELEMENTS = ['div', 'span', 'p', 'br', 'b', 'strong', 'i', 'em', 'u', 's', 'strike',
        'font', 'small', 'big', 'sub', 'sup', 'a', 'ul', 'ol', 'li', 'table', 'thead', 'tbody', 'tfoot',
        'tr', 'td', 'th', 'hr', 'pre', 'code', 'blockquote', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'img'];

    public static function validate(string $svg, int $maximum): void
    {
        if ($svg === '') return;
        self::assert(strlen($svg) <= $maximum, 'Le diagramme dépasse la limite de '
            . number_format($maximum, 0, ',', ' ') . ' octets (SVG et données de réédition).');

        // Borner aussi l'expansion cumulée : un petit SVG ne doit pas cacher des mégaoctets de XML.
        $budget = min(5_000_000, strlen($svg) * 20);
        $document = self::xml($svg, $budget);
        self::svgTree($document, $budget, 0);
        $source = $document->documentElement->getAttribute('content');
        self::assert($source !== '', 'Le SVG doit contenir les données de réédition diagrams.net.');
        self::source(self::xml($source, $budget), $budget);
    }

    private static function xml(string $xml, int &$budget): DOMDocument
    {
        $budget -= strlen($xml);
        self::assert($xml !== '' && $budget >= 0 && !preg_match('/<!DOCTYPE|<!ENTITY/i', $xml),
            'Données XML de diagramme interdites ou trop volumineuses.');

        // Ne charger ni DTD ni entités, et ne jamais résoudre une ressource externe.
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument();
            $valid = $document->loadXML($xml, LIBXML_NONET);
            self::assert($valid && $document->documentElement !== null && $document->doctype === null,
                'Le diagramme contient un XML invalide.');
            self::assert((new DOMXPath($document))->query('//processing-instruction()')->length === 0,
                'Les instructions XML sont interdites dans les diagrammes.');
            return $document;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private static function svgTree(DOMDocument $document, int &$budget, int $depth): void
    {
        self::assert($depth <= 4 && $document->documentElement->localName === 'svg'
            && $document->documentElement->namespaceURI === self::SVG_NS, 'Image SVG invalide.');
        foreach ($document->getElementsByTagName('*') as $element) {
            $html = $element->namespaceURI === self::HTML_NS;
            self::assert(($html && in_array($element->localName, self::HTML_ELEMENTS, true))
                || ($element->namespaceURI === self::SVG_NS && in_array($element->localName, self::SVG_ELEMENTS, true)),
                'Le SVG contient un élément non autorisé.');
            self::attributes($element, $budget, $depth);
            if ($element->localName === 'style') self::css($element->textContent);
        }
        if ($depth > 0 && $document->documentElement->hasAttribute('content')) {
            self::source(self::xml($document->documentElement->getAttribute('content'), $budget), $budget);
        }
    }

    private static function attributes(DOMElement $element, int &$budget, int $depth): void
    {
        foreach ($element->attributes as $attribute) {
            $name = strtolower($attribute->localName);
            $value = trim($attribute->value);
            // La source embarquée a son propre parcours XML ; ne pas la traiter comme du CSS.
            if ($name === 'content' && $element->namespaceURI === self::SVG_NS) continue;
            self::assert(!str_starts_with($name, 'on') && !in_array($name, ['srcdoc', 'base'], true),
                'Les contenus actifs sont interdits dans les diagrammes.');
            if ($name === 'style' || preg_match('/url\s*\(/i', $value)) self::css($value);
            if (!in_array($name, ['href', 'src'], true)) continue;
            if (in_array(strtolower($element->localName), ['image', 'img'], true)) {
                self::image($value, $budget, $depth + 1);
            } elseif ($element->localName === 'a') {
                self::assert(!preg_match('/[\x00-\x20\\\\]/', $value)
                    && preg_match('~^(?:https?://|mailto:|#|/(?!/))~i', $value), 'Lien de diagramme interdit.');
            } else {
                self::assert(preg_match('/^#[a-zA-Z0-9_.:-]+$/D', $value) === 1,
                    'Les ressources externes sont interdites dans les diagrammes de page.');
            }
        }
    }

    private static function css(string $css): void
    {
        // Refuser les échappements et les chargements CSS ; seules les références locales sont utiles au SVG.
        self::assert(!preg_match('~[\\\\]|/\*|@import|@font-face|expression\s*\(|(?:javascript|https?|data)\s*:~i', $css),
            'Le diagramme contient un style externe ou actif interdit.');
        $withoutLocalUrls = preg_replace('~url\(\s*[\'"]?#[a-zA-Z0-9_.:-]+[\'"]?\s*\)~i', '', $css);
        self::assert(!preg_match('/url\s*\(/i', $withoutLocalUrls), 'Les ressources CSS externes sont interdites.');
    }

    private static function image(string $uri, int &$budget, int $depth): void
    {
        self::assert(preg_match('~^data:image/(svg\+xml|gif|webp|avif)(;base64)?,(.*)$~isD', $uri, $parts) === 1,
            'Les images PNG/JPEG et les images externes sont interdites dans les diagrammes de page.');
        $decoded = ($parts[2] ?? '') === '' ? rawurldecode($parts[3]) : base64_decode($parts[3], true);
        self::assert(is_string($decoded) && !str_starts_with($decoded, "\x89PNG\r\n\x1a\n")
            && !str_starts_with($decoded, "\xff\xd8"), 'Les images PNG/JPEG sont interdites dans les diagrammes de page.');
        if (strtolower($parts[1]) === 'svg+xml') self::svgTree(self::xml($decoded, $budget), $budget, $depth);
    }

    private static function source(DOMDocument $document, int &$budget, bool $stencil = false): void
    {
        $root = $document->documentElement;
        self::assert(in_array($root->tagName, $stencil ? ['shape'] : ['mxfile', 'mxGraphModel'], true), 'Source diagrams.net invalide.');

        // Inspecter toutes les pages, y compris celles qui ne figurent pas dans l'image exportée.
        foreach ($document->getElementsByTagName('diagram') as $diagram) {
            if ($diagram->getElementsByTagName('mxGraphModel')->length !== 0) continue;
            $compressed = base64_decode(trim($diagram->textContent), true);
            self::assert($compressed !== false && $budget > 0, 'Source diagrams.net compressée invalide.');
            $decoded = @gzinflate($compressed, $budget);
            self::assert(is_string($decoded), 'Source diagrams.net compressée invalide ou trop volumineuse.');
            $page = self::xml(rawurldecode($decoded), $budget);
            self::assert($page->documentElement->tagName === 'mxGraphModel', 'Page de diagramme invalide.');
            self::source($page, $budget);
        }

        foreach ($document->getElementsByTagName('*') as $element) {
            $style = $element->getAttribute('style');
            if (in_array(strtolower($element->localName), ['image', 'img'], true)) self::attributes($element, $budget, 0);
            if (preg_match_all('~(?:^|;)image=([^;]+)(;base64,[^;]*)?~i', $style, $images, PREG_SET_ORDER)) {
                foreach ($images as $image) self::image($image[1] . ($image[2] ?? ''), $budget, 1);
            }
            if (preg_match('~(?:^|;)shape=stencil\(([^)]*)\)~', $style, $match)) {
                $compressed = base64_decode($match[1], true);
                self::assert($compressed !== false && $budget > 0, 'Forme de diagramme invalide.');
                $decoded = @gzinflate($compressed, $budget);
                self::assert(is_string($decoded), 'Forme de diagramme invalide ou trop volumineuse.');
                self::source(self::xml(rawurldecode($decoded), $budget), $budget, true);
            }
            if ($element->hasAttribute('backgroundImage')) {
                $image = json_decode($element->getAttribute('backgroundImage'));
                self::assert(is_object($image) && is_string($image->src ?? null), 'Image de fond du diagramme invalide.');
                self::image($image->src, $budget, 1);
            }
            foreach ($element->attributes as $attribute) {
                if (!str_contains($attribute->value, '<')) continue;
                // Le texte enrichi de la source sera interprété à la réouverture dans l'éditeur.
                $previous = libxml_use_internal_errors(true);
                try {
                    $html = new DOMDocument();
                    $html->loadHTML('<?xml encoding="UTF-8"><html><body>' . $attribute->value . '</body></html>', LIBXML_NONET);
                    foreach ($html->getElementsByTagName('*') as $tag) {
                        if (in_array($tag->tagName, ['html', 'body'], true)) continue;
                        self::assert(in_array($tag->tagName, self::HTML_ELEMENTS, true), 'Texte enrichi de diagramme interdit.');
                        self::attributes($tag, $budget, 0);
                    }
                } finally {
                    libxml_clear_errors();
                    libxml_use_internal_errors($previous);
                }
            }
        }
    }

    private static function assert(bool $condition, string $message): void
    {
        if (!$condition) ExceptionResponse::throwWarning($message);
    }
}
