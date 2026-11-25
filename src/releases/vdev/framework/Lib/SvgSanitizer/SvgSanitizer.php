<?php

declare(strict_types=1);

namespace YTassy\Tycms\Lib\SvgSanitizer;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use RuntimeException;

/**
 * Contrôle et purification d'un fichier SVG téléversé.
 *
 * Un SVG est un document exécutable : il peut porter du script, charger une
 * ressource distante ou suivre le visiteur. Servi depuis le domaine du site, il
 * s'exécute dans son origine. Ce n'est donc jamais un fichier inerte comme un
 * JPEG.
 *
 * Deux niveaux complémentaires, appliqués dans cet ordre :
 *
 * 1. **Contrôle textuel préalable** sur le contenu brut : déclarations XML
 *    (DTD, entités), balises actives et schémas exécutables. Il travaille avant
 *    l'analyse du document, là où aucune interprétation n'a encore eu lieu.
 * 2. **Purification par liste blanche** : le document est analysé en DOM, les
 *    éléments et attributs inconnus sont retirés, puis le résultat est
 *    entre-temps re-contrôlé par les mêmes motifs.
 *
 * Le DOM est indispensable ici : Symfony HtmlSanitizer met les attributs en
 * minuscules, ce qui détruit `viewBox` — les noms SVG sont sensibles à la
 * casse. Un sanitizer HTML ne peut donc pas traiter un SVG correctement.
 *
 * Politique : un fichier qui devrait être modifié pour devenir sûr est refusé
 * avec un message explicite, plutôt qu'enregistré silencieusement altéré.
 */
final class SvgSanitizer
{
    /** Taille maximale d'un SVG contrôlé, pour borner le coût d'analyse. */
    public const MAX_BYTES = 1024 * 1024;

    /**
     * Éléments de dessin autorisés, avec leurs attributs.
     *
     * Aucun élément d'animation, de script, de style ni de contenu HTML
     * embarqué : `script`, `foreignObject`, `animate`, `set`, `style` et `use`
     * distant sont volontairement absents. La casse des noms est celle de SVG
     * et doit être respectée (d'où `viewBox`, `gradientUnits`, `patternUnits`…).
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED_ELEMENTS = [
        // Structure et conteneurs
        'svg' => ['xmlns', 'viewBox', 'width', 'height', 'preserveAspectRatio', 'role', 'aria-label'],
        'g' => ['transform', 'opacity', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'filter', 'clip-path', 'mask', 'marker-start', 'marker-mid', 'marker-end', 'fill-opacity', 'stroke-opacity', 'stroke-miterlimit', 'vector-effect', 'paint-order', 'display', 'visibility'],
        'defs' => [],
        'symbol' => ['viewBox', 'preserveAspectRatio'],
        'switch' => [],
        'title' => [],
        'desc' => [],

        // Formes
        'path' => ['d', 'fill', 'fill-rule', 'clip-rule', 'stroke', 'stroke-width', 'stroke-linecap',
            'stroke-linejoin', 'stroke-dasharray', 'stroke-dashoffset', 'opacity', 'transform', 'filter', 'clip-path', 'mask', 'marker-start', 'marker-mid', 'marker-end', 'fill-opacity', 'stroke-opacity', 'stroke-miterlimit', 'vector-effect', 'paint-order', 'display', 'visibility'],
        'rect' => ['x', 'y', 'width', 'height', 'rx', 'ry', 'fill', 'stroke', 'stroke-width', 'opacity', 'transform', 'filter', 'clip-path', 'mask', 'marker-start', 'marker-mid', 'marker-end', 'fill-opacity', 'stroke-opacity', 'stroke-miterlimit', 'vector-effect', 'paint-order', 'display', 'visibility'],
        'circle' => ['cx', 'cy', 'r', 'fill', 'stroke', 'stroke-width', 'opacity', 'transform', 'filter', 'clip-path', 'mask', 'marker-start', 'marker-mid', 'marker-end', 'fill-opacity', 'stroke-opacity', 'stroke-miterlimit', 'vector-effect', 'paint-order', 'display', 'visibility'],
        'ellipse' => ['cx', 'cy', 'rx', 'ry', 'fill', 'stroke', 'stroke-width', 'opacity', 'transform', 'filter', 'clip-path', 'mask', 'marker-start', 'marker-mid', 'marker-end', 'fill-opacity', 'stroke-opacity', 'stroke-miterlimit', 'vector-effect', 'paint-order', 'display', 'visibility'],
        'line' => ['x1', 'y1', 'x2', 'y2', 'stroke', 'stroke-width', 'stroke-linecap', 'opacity', 'transform'],
        'polyline' => ['points', 'fill', 'stroke', 'stroke-width', 'opacity', 'transform'],
        'polygon' => ['points', 'fill', 'stroke', 'stroke-width', 'opacity', 'transform'],

        // Texte
        'text' => ['x', 'y', 'dx', 'dy', 'font-family', 'font-size', 'font-weight', 'text-anchor',
            'fill', 'stroke', 'opacity', 'transform'],
        'tspan' => ['x', 'y', 'dx', 'dy', 'font-family', 'font-size', 'font-weight', 'fill'],
        'textPath' => ['href', 'startOffset'],

        // Remplissages, découpes et marqueurs
        'linearGradient' => ['id', 'x1', 'y1', 'x2', 'y2', 'gradientUnits', 'gradientTransform'],
        'radialGradient' => ['id', 'cx', 'cy', 'r', 'fx', 'fy', 'gradientUnits', 'gradientTransform'],
        'stop' => ['offset', 'stop-color', 'stop-opacity'],
        'pattern' => ['id', 'width', 'height', 'patternUnits', 'patternContentUnits', 'patternTransform'],
        'clipPath' => ['id', 'clipPathUnits'],
        'mask' => ['id', 'maskUnits', 'maskContentUnits', 'x', 'y', 'width', 'height'],
        'marker' => ['id', 'markerWidth', 'markerHeight', 'refX', 'refY', 'orient', 'markerUnits'],

        // Images : uniquement des chemins relatifs au site.
        'image' => ['href', 'x', 'y', 'width', 'height', 'preserveAspectRatio'],

        // Filtres : souvent oubliés, leur absence casserait des SVG légitimes.
        'filter' => ['id', 'x', 'y', 'width', 'height', 'filterUnits', 'primitiveUnits'],
        'feGaussianBlur' => ['in', 'stdDeviation', 'result'],
        'feOffset' => ['in', 'dx', 'dy', 'result'],
        'feBlend' => ['in', 'in2', 'mode', 'result'],
        'feColorMatrix' => ['in', 'type', 'values', 'result'],
        'feComposite' => ['in', 'in2', 'operator', 'k1', 'k2', 'k3', 'k4', 'result'],
        'feMerge' => [],
        'feMergeNode' => ['in'],
        'feFlood' => ['flood-color', 'flood-opacity', 'result'],
        'feDropShadow' => ['in', 'dx', 'dy', 'stdDeviation', 'flood-color', 'flood-opacity', 'result'],
    ];

    /**
     * Motifs interdits dans le contenu brut.
     *
     * Appliqués avant l'analyse DOM : ils couvrent ce qu'un analyseur pourrait
     * interpréter (entités externes, balises actives, schémas exécutables).
     *
     * @var list<string>
     */
    private const FORBIDDEN_PATTERNS = [
        '<!doctype',
        '<!entity',
        '<script',
        '<foreignobject',
        '<animate',
        '<set',
        '<style',
        '<iframe',
        '<embed',
        '<object',
        'javascript:',
        'vbscript:',
        'data:text/html',
    ];

    /**
     * Préfixes d'attributs toujours retirés, quel que soit l'élément.
     *
     * Les gestionnaires d'événements (`onclick`, `onload`…) n'ont aucun usage
     * dans un dessin.
     *
     * @var list<string>
     */
    private const FORBIDDEN_ATTRIBUTE_PREFIXES = ['on'];

    /** Attributs portant une référence de ressource. */
    private const LINK_ATTRIBUTES = ['href', 'xlink:href', 'src', 'data'];

    /**
     * Contrôle un SVG brut et renvoie son contenu sûr.
     *
     * @throws RuntimeException Si le fichier est refusé.
     */
    public static function sanitize(string $content): string
    {
        if (trim($content) === '') {
            throw new RuntimeException('Le fichier SVG est vide.');
        }

        if (strlen($content) > self::MAX_BYTES) {
            throw new RuntimeException('Ce fichier SVG est trop volumineux.');
        }

        // Niveau 1 : contrôle textuel du contenu brut, avant toute analyse.
        // Il refuse, il ne corrige pas : un fichier douteux est rejeté entier.
        $lowered = strtolower($content);

        foreach (self::FORBIDDEN_PATTERNS as $pattern) {
            if (str_contains($lowered, $pattern)) {
                throw new RuntimeException(
                    'Ce fichier SVG contient un élément ou une référence non autorisés.',
                );
            }
        }

        // Gestionnaires d'événements : `onload`, `onclick`, `onbegin`… Le
        // contrôle par motifs ne les couvre pas, car ils ne contiennent aucune
        // balise ni schéma exécutable. Un dessin n'en a pas l'usage.
        if (preg_match('~\son[a-z]+\s*=~i', $content) === 1) {
            throw new RuntimeException(
                'Ce fichier SVG contient un gestionnaire d’événement, ce qui n’est pas autorisé.',
            );
        }

        // Toute référence distante est refusée : une ressource chargée depuis
        // un tiers suivrait le visiteur et pourrait changer après coup.
        if (preg_match('~https?://~i', (string) preg_replace('~xmlns\s*=\s*["\'][^"\']*["\']~i', '', $content)) === 1) {
            throw new RuntimeException('Ce fichier SVG référence une ressource externe, ce qui n’est pas autorisé.');
        }

        // Niveau 2 : analyse en DOM, qui préserve la casse des noms SVG.
        $document = self::load($content);
        $root = $document->documentElement;

        if (!$root instanceof DOMElement || $root->nodeName !== 'svg') {
            throw new RuntimeException('Ce fichier n’est pas un document SVG.');
        }

        $changed = self::purify($root);

        // Un fichier qui devrait être modifié pour devenir sûr est refusé :
        // enregistrer une version altérée donnerait une image cassée.
        if ($changed) {
            throw new RuntimeException(
                'Ce fichier SVG contient des éléments ou attributs non autorisés ; '
                . 'retirez-les puis téléversez-le à nouveau.',
            );
        }

        return $document->saveXML() ?: $content;
    }

    /**
     * Charge le XML en interdisant toute entité externe.
     *
     * `loadXML` avec `LIBXML_NONET` bloque les accès réseau, et l'absence de
     * `LIBXML_NOENT` empêche la substitution d'entités : un document hostile ne
     * peut donc ni lire un fichier local ni joindre un service distant.
     */
    private static function load(string $content): DOMDocument
    {
        $document = new DOMDocument();

        $previous = libxml_use_internal_errors(true);

        try {
            // La déclaration XML est conservée pour un SVG autonome réaffiché.
            $loaded = $document->loadXML($content, LIBXML_NONET | LIBXML_NOBLANKS);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($loaded === false) {
            throw new RuntimeException('Ce fichier SVG n’est pas un document XML valide.');
        }

        return $document;
    }

    /**
     * Retire les éléments et attributs hors liste blanche.
     *
     * Parcours récursif d'une copie des enfants : retirer un nœud pendant
     * l'itération d'une `NodeList` vivante décalerait les indices.
     *
     * @return bool Vrai si le document a dû être modifié.
     */
    private static function purify(DOMElement $root): bool
    {
        $changed = false;

        /** @var list<DOMNode> $children */
        $children = [];

        foreach ($root->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            // Texte et commentaires : inoffensifs, conservés.
            if (!($child instanceof DOMElement)) {
                continue;
            }

            // Un élément inconnu est retiré avec tout son contenu : un
            // `<script>` ou un `<foreignObject>` ne doit rien laisser passer.
            if (!array_key_exists($child->nodeName, self::ALLOWED_ELEMENTS)) {
                $root->removeChild($child);
                $changed = true;
                continue;
            }

            if (self::purifyAttributes($child)) {
                $changed = true;
            }

            if (self::purify($child)) {
                $changed = true;
            }
        }

        return $changed;
    }

    /** @return bool Vrai si au moins un attribut a été retiré. */
    private static function purifyAttributes(DOMElement $element): bool
    {
        $allowed = self::ALLOWED_ELEMENTS[$element->nodeName] ?? [];
        $changed = false;

        /** @var list<string> $names */
        $names = [];

        foreach ($element->attributes as $attribute) {
            $names[] = $attribute->nodeName;
        }

        foreach ($names as $name) {
            $lowered = strtolower($name);

            // `xmlns` est nécessaire à l'affichage du document ; les espaces
            // de noms déclarés (`xmlns:xlink`) le sont aussi.
            if ($name === 'xmlns' || str_starts_with($lowered, 'xmlns:')) {
                continue;
            }

            foreach (self::FORBIDDEN_ATTRIBUTE_PREFIXES as $prefix) {
                if (str_starts_with($lowered, $prefix)) {
                    $element->removeAttribute($name);
                    $changed = true;
                    continue 2;
                }
            }

            // Une référence de ressource ne peut viser qu'une ancre du document.
            if (in_array($lowered, self::LINK_ATTRIBUTES, true)) {
                if (!str_starts_with((string) $element->getAttribute($name), '#')) {
                    $element->removeAttribute($name);
                    $changed = true;
                }

                continue;
            }

            if (!in_array($name, $allowed, true) && !in_array($lowered, $allowed, true)) {
                $element->removeAttribute($name);
                $changed = true;
            }
        }

        return $changed;
    }
}
