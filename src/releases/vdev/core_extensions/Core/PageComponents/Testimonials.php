<?php

namespace YTassy\Tycms\CoreExts\Core\PageComponents;

use RuntimeException;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageNormalizationContext;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageRenderContext;
use YTassy\Tycms\CoreExts\Core\PageComponents\Support\PageCollectionStyle;
use YTassy\Tycms\CoreExts\Core\PageComponents\Support\PageLinks;

/** Une instance conserve toute la liste de témoignages, sans source externe. */
final class Testimonials extends CorePageComponent
{
    public const STYLE_FIELDS = ['background_color', 'color', 'border_color', 'accent_color', 'author_color', 'muted_color',
        'gap', 'padding', 'radius', 'border_width', 'portrait_size', 'text_size', 'author_size', 'shadow'];

    public static function getId(): string { return 'core.testimonials'; }
    public static function getLabel(): string { return 'Testimonials (témoignages)'; }
    public static function getCategory(): string { return 'Contenu'; }
    public static function getFormVueComponentName(): ?string { return 'core/testimonials'; }
    public static function getCanvasVueComponentName(): ?string { return 'core/testimonials'; }

    public static function getDefaultData(): array
    {
        return ['mode' => 'carousel', 'columns_desktop' => 3, 'columns_tablet' => 2, 'columns_mobile' => 1,
            'align' => 'center', 'author_position' => 'outside', 'portrait_position' => 'author', 'portrait_shape' => 'circle',
            'equal_height' => true, 'show_quotes' => false, 'show_arrows' => true, 'show_dots' => true,
            'autoplay' => false, 'interval' => 6000, 'testimonials_style' => (object) [],
            'testimonials' => [(object) ['text' => '', 'author' => '', 'role' => '', 'portrait' => '', 'rating' => 5]]];
    }

    public static function getPropsSchema(): array
    {
        $properties = [];
        foreach (['mode' => ['grid', 'carousel'], 'align' => ['left', 'center'], 'author_position' => ['inside', 'outside'],
            'portrait_position' => ['top', 'author'], 'portrait_shape' => ['circle', 'rounded', 'square']] as $key => $values) {
            $properties[$key] = ['type' => 'string', 'enum' => $values];
        }
        foreach (['columns_desktop', 'columns_tablet', 'columns_mobile'] as $key) {
            $properties[$key] = ['type' => 'integer', 'minimum' => 1, 'maximum' => 6];
        }
        foreach (['equal_height', 'show_quotes', 'show_arrows', 'show_dots', 'autoplay'] as $key) $properties[$key] = ['type' => 'boolean'];
        $properties['interval'] = ['type' => 'integer', 'minimum' => 2000, 'maximum' => 60000];
        $properties['testimonials'] = ['type' => 'array', 'minItems' => 1, 'maxItems' => 100];
        $properties['testimonials_style'] = ['type' => 'object', 'additionalProperties' => true];
        return ['type' => 'object', 'properties' => $properties, 'additionalProperties' => false];
    }

    public function normalizeData(object $data, PageNormalizationContext $context): object
    {
        PageCollectionStyle::validate($data->testimonials_style ?? new \stdClass(), self::STYLE_FIELDS);
        $entries = $data->testimonials ?? null;
        $this->assert(is_array($entries) && array_is_list($entries) && count($entries) >= 1 && count($entries) <= 100,
            'Saisissez entre 1 et 100 témoignages.');
        $result = clone $data;
        $result->testimonials = [];

        // Les textes imbriqués ont leur propre contrat multilingue et de purification.
        foreach ($entries as $entry) {
            $this->assert($entry instanceof \stdClass, 'Témoignage invalide.');
            $this->assert(array_diff(array_keys(get_object_vars($entry)), ['text', 'author', 'role', 'portrait', 'rating']) === [],
                'Champ de témoignage inconnu.');
            $portrait = $entry->portrait ?? '';
            $this->assert(is_string($portrait) && ($portrait === '' || (str_starts_with($portrait, '/medias/')
                && PageLinks::safeUrl($portrait) !== null && !str_contains($portrait, '?') && !str_contains($portrait, '#'))),
                'Le portrait doit provenir des médias de ce Space.');
            $rating = $entry->rating ?? null;
            $this->assert($rating === null || (is_int($rating) && $rating >= 0 && $rating <= 5), 'Note invalide (0 à 5 ou aucune).');
            $result->testimonials[] = (object) [
                'text' => $this->normalizeText($entry->text ?? '', 20000, $context->isInput()),
                'author' => $this->normalizeText($entry->author ?? '', 300),
                'role' => $this->normalizeText($entry->role ?? '', 500),
                'portrait' => $portrait, 'rating' => $rating,
            ];
        }
        return $result;
    }

    public function validateData(object $data, PageNormalizationContext $context): void
    {
        $portraits = array_unique(array_filter(array_column($data->testimonials, 'portrait')));
        if ($portraits === []) return;

        // Le catalogue client ne prouve ni les droits ni le Space réel du fichier.
        $spaceId = $context->spaceId;
        $this->assert($this->coreAclHelper->hasGlobalPermission(Permissions::MEDIA_READ, $spaceId),
            'La lecture des médias est requise pour choisir les portraits.');
        $space = $this->coreSpaceHelper->getSpace($spaceId);
        $this->assert($space !== null && $space->id === $spaceId, 'Space média introuvable.');
        foreach ($portraits as $portrait) {
            try { $this->coreMediaHelper->assertSelectableFile($space, $portrait, 'image'); }
            catch (RuntimeException) { $this->assert(false, 'Portrait introuvable dans les images des médias de ce Space.'); }
        }
    }

    public function render(object $item, PageRenderContext $context): string
    {
        $props = $item->props;
        $style = PageCollectionStyle::variables($props->testimonials_style, self::STYLE_FIELDS);
        foreach (['desktop', 'tablet', 'mobile'] as $screen) $style .= '--pc-columns-' . $screen . ':' . $props->{'columns_' . $screen} . ';';
        $classes = 'page-testimonials page-testimonials--' . $props->mode . ' page-testimonials--' . $props->align
            . ' page-testimonials--portrait-' . $props->portrait_shape . ($props->equal_height ? ' page-testimonials--equal' : '')
            . ($props->show_quotes ? ' page-testimonials--quotes' : '');
        $english = str_starts_with($context->locale, 'en');
        $html = '<section class="' . $this->escape($classes) . '" style="' . $style . '" aria-label="'
            . ($english ? 'Testimonials' : 'Témoignages') . '" data-testimonials data-autoplay="' . ($props->autoplay ? '1' : '0')
            . '" data-interval="' . $props->interval . '"><div class="page-testimonials-track" tabindex="0" role="list" aria-label="'
            . ($english ? 'Testimonials' : 'Liste des témoignages') . '">';

        foreach ($props->testimonials as $entry) {
            $portrait = $this->portrait($entry->portrait, $context->spaceId);
            $author = '<footer class="page-testimonials-author">'
                . ($props->portrait_position === 'author' ? $portrait : '')
                . '<div><div class="page-testimonials-name">' . $this->escape($context->text($entry->author)) . '</div>'
                . '<div class="page-testimonials-role">' . $this->escape($context->text($entry->role)) . '</div></div></footer>';
            $html .= '<article class="page-testimonials-entry" role="listitem"><div class="page-testimonials-card">'
                . ($props->portrait_position === 'top' ? $portrait : '');
            if ($entry->rating !== null) {
                $html .= '<div class="page-testimonials-rating" role="img" aria-label="' . $entry->rating . ($english ? ' out of 5' : ' sur 5') . '">';
                for ($star = 1; $star <= 5; $star++) $html .= '<span aria-hidden="true"' . ($star > $entry->rating ? ' class="page-testimonials-star-empty"' : '') . '>★</span>';
                $html .= '</div>';
            }
            $html .= '<div class="page-testimonials-text">' . $context->text($entry->text) . '</div>'
                . ($props->author_position === 'inside' ? $author : '') . '</div>'
                . ($props->author_position === 'outside' ? $author : '') . '</article>';
        }
        $html .= '</div>';
        if ($props->mode === 'carousel') {
            $html .= '<div class="page-testimonials-controls" hidden>';
            if ($props->show_arrows) {
                $html .= '<button type="button" data-testimonials-prev aria-label="' . ($english ? 'Previous' : 'Précédent') . '">‹</button>';
            }
            if ($props->show_dots) $html .= '<div class="page-testimonials-dots" data-testimonials-dots></div>';
            if ($props->show_arrows) {
                $html .= '<button type="button" data-testimonials-next aria-label="' . ($english ? 'Next' : 'Suivant') . '">›</button>';
            }
            if ($props->autoplay) {
                $html .= '<button type="button" data-testimonials-pause aria-label="' . ($english ? 'Pause autoplay' : 'Suspendre le défilement automatique') . '" aria-pressed="false">Ⅱ</button>';
            }
            $html .= '</div>';
        }
        return $html . '</section>';
    }

    private function portrait(string $src, int $spaceId): string
    {
        if ($src === '') return '';
        $space = $this->coreSpaceHelper->getSpace($spaceId);
        if ($space === null || $space->id !== $spaceId) return '';

        // Une image retirée ou remplacée ne doit pas casser le rendu de la page.
        try { $this->coreMediaHelper->assertSelectableFile($space, $src, 'image'); }
        catch (RuntimeException) { return ''; }
        return '<img class="page-testimonials-portrait" src="' . $this->escape($src) . '" alt="" loading="lazy">';
    }
}
