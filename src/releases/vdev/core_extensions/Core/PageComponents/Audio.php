<?php

namespace YTassy\Tycms\CoreExts\Core\PageComponents;
use RuntimeException;
use YTassy\Tycms\CoreExts\Core\PageComponents\Support\PageColors;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageNormalizationContext;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageRenderContext;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Tools\HtmlTools;

/** Lecteur audio personnalisé, fourni par Core. */
final class Audio extends CorePageComponent
{
    private const COLORS = ['color', 'background_color', 'accent_color', 'track_color'];

    public static function getId(): string { return 'core.audio'; }
    public static function getLabel(): string { return 'Audio'; }
    public static function getCategory(): string { return 'Médias'; }

    public static function getPropsSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'src' => ['type' => 'string'],
            'audio_style' => ['type' => 'object', 'properties' => [
                'height' => ['type' => 'integer', 'minimum' => 44, 'maximum' => 200],
                'color' => ['type' => 'string'],
                'background_color' => ['type' => 'string'],
                'accent_color' => ['type' => 'string'],
                'track_color' => ['type' => 'string'],
                'border_radius' => ['type' => 'string'],
            ], 'additionalProperties' => false],
        ], 'additionalProperties' => false];
    }

    public static function getDefaultData(): array { return ['src' => '', 'audio_style' => (object) []]; }
    public static function getFormVueComponentName(): ?string { return 'core/audio'; }
    public static function getCanvasVueComponentName(): ?string { return 'core/audio'; }
    public static function getPublicVueComponentName(): ?string { return 'core/audio'; }

    public function normalizeData(object $data, PageNormalizationContext $context): object
    {
        self::validate($data);
        return clone $data;
    }

    public function validateData(object $data, PageNormalizationContext $context): void
    {
        if (($data->src ?? '') === '') return;

        // Le WF fournit le Space réel et applique ce contrôle à chaque entrée de document.
        $spaceId = $context->spaceId;
        $this->assert($this->coreAclHelper->hasGlobalPermission(Permissions::MEDIA_READ, $spaceId),
            'La lecture des médias est requise pour choisir un fichier audio.');

        try { $this->assertMediaInSpace($data, $spaceId); }
        catch (RuntimeException) { ExceptionResponse::throwWarning('Le fichier audio est introuvable dans les médias de ce Space ou son type est invalide.'); }
    }

    public static function validate(object $props): void
    {
        $src = $props->src ?? '';
        if (!is_string($src) || strlen($src) > 2048 || ($src !== '' && (
            !str_starts_with($src, '/medias/') || preg_match('/[\x00-\x20\x7f"\x27<>\\\\?#]/', $src)
            || preg_match('/[\x00-\x1f\x7f\\\\]/', rawurldecode($src))
            || array_filter(explode('/', rawurldecode($src)), static fn (string $part): bool => str_starts_with($part, '.')) !== []
        ))) {
            ExceptionResponse::throwWarning('Le fichier audio doit provenir des médias du Space.');
        }

        // Ne jamais accepter de CSS libre dans les paramètres du lecteur.
        if (!property_exists($props, 'audio_style')) return;
        if (!is_object($props->audio_style)) ExceptionResponse::throwWarning('Style audio invalide.');
        foreach (get_object_vars($props->audio_style) as $key => $value) {
            $valid = match ($key) {
                'height' => is_int($value) && $value >= 44 && $value <= 200,
                'border_radius' => is_string($value) && (bool) preg_match('/^(?:0|(?:0|[1-9][0-9]{0,3})(?:px|rem|em|%))$/D', $value),
                default => in_array($key, self::COLORS, true) && is_string($value)
                    && PageColors::isValid($value),
            };
            if (!$valid) ExceptionResponse::throwWarning('Style audio invalide : ' . $key . '.');
        }
    }

    /** Le composant vérifie les droits médias avant cet accès technique. */
    private function assertMediaInSpace(object $props, int $spaceId): void
    {
        $space = $this->coreSpaceHelper->getSpace($spaceId);
        if ($space === null) throw new RuntimeException('Space média introuvable.');
        $this->coreMediaHelper->assertSelectableFile($space, $props->src ?? '', 'audio');
    }

    public function render(object $item, PageRenderContext $context): string
    {
        $props = $item->props;
        $spaceId = $context->spaceId;
        self::validate($props);
        if (($props->src ?? '') === '') return '';

        // Un fichier supprimé, déplacé ou remplacé par un autre type est omis.
        try { $this->assertMediaInSpace($props, $spaceId); }
        catch (RuntimeException) { return ''; }

        // Repli utilisable sans JavaScript et dans l’aperçu fidèle isolé.
        $style = $props->audio_style ?? new \stdClass();
        $css = 'display:flex;align-items:center;width:100%;min-height:' . ($style->height ?? 56) . 'px;'
            . 'background:' . ($style->background_color ?? '#f1f3f5') . ';border-radius:' . ($style->border_radius ?? '8px');
        return '<div style="' . HtmlTools::escape($css) . '"><audio src="' . HtmlTools::escape($props->src)
            . '" controls preload="metadata" aria-label="Lecteur audio" style="display:block;width:100%;max-width:100%">'
            . 'Votre navigateur ne peut pas lire ce fichier audio.</audio></div>';
    }
}
