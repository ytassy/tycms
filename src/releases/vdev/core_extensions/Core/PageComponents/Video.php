<?php

namespace YTassy\Tycms\CoreExts\Core\PageComponents;
use RuntimeException;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageNormalizationContext;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageRenderContext;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\CoreExts\Core\Helper\Video\SecureVideoHelper;
use YTassy\Tycms\Lib\HtmlSanitizer\VideoEmbedUrl;
use YTassy\Tycms\Tools\HtmlTools;

/** Composant Vidéo fourni par Core. */
final class Video extends CorePageComponent
{
    public static function getId(): string
    {
        return 'core.video';
    }

    public static function getLabel(): string
    {
        return 'Vidéo';
    }

    public static function getCategory(): string
    {
        return 'Médias';
    }

    public static function getPropsSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'source' => ['type' => 'string'],
                'src' => ['type' => 'string'],
                'library_id' => ['type' => 'string'],
                'video_id' => ['type' => 'string'],
            ],
            'additionalProperties' => false,
        ];
    }

    public static function getDefaultData(): array
    {
        return ['source' => 'media', 'src' => '', 'library_id' => '', 'video_id' => ''];
    }

    public static function getFormVueComponentName(): ?string
    {
        return 'core/video';
    }

    public static function getCanvasVueComponentName(): ?string
    {
        return 'core/video';
    }

    public function normalizeData(object $data, PageNormalizationContext $context): object
    {
        self::validate($data);
        return clone $data;
    }

    public function validateData(object $data, PageNormalizationContext $context): void
    {
        $spaceId = $context->spaceId;
        if (($data->source ?? 'media') === 'media' && ($data->src ?? '') !== '') {
            $this->assert($this->coreAclHelper->hasGlobalPermission(Permissions::MEDIA_READ, $spaceId),
                'La lecture des médias est requise pour choisir une vidéo.');
            try { $this->assertMediaInSpace($data, $spaceId); }
            catch (RuntimeException) { ExceptionResponse::throwWarning('La vidéo est introuvable dans les médias de ce Space.'); }
        }
        if (($data->source ?? '') === 'hls' && ($data->src ?? '') !== '') {
            try { $this->coreSecureVideoHelper->assertSourceInSpace($spaceId, $data->src); }
            catch (RuntimeException) { ExceptionResponse::throwWarning('La vidéo HLS est introuvable dans ce Space.'); }
        }
    }

    public static function validate(object $props): void
    {
        if (!in_array($props->source ?? 'media', ['media', 'url', 'bunny', 'hls'], true)) {
            ExceptionResponse::throwWarning('Type de vidéo invalide.');
        }
        foreach (['src' => 2048, 'library_id' => 15, 'video_id' => 100] as $field => $limit) {
            $value = $props->{$field} ?? '';
            if (!is_string($value) || strlen($value) > $limit || preg_match('/[\x00-\x20\x7f"\x27<>\\\\]/', $value)) {
                ExceptionResponse::throwWarning('Paramètre vidéo invalide : ' . $field . '.');
            }
        }
        $source = $props->source ?? 'media';
        $url = $props->src ?? '';
        if ($source === 'url' && $url !== '' && VideoEmbedUrl::normalize($url) === null) {
            ExceptionResponse::throwWarning('Saisissez une URL HTTPS YouTube ou Vimeo valide.');
        }
        if ($source === 'hls' && $url !== '') {
            try { SecureVideoHelper::validateSource($url); }
            catch (RuntimeException) { ExceptionResponse::throwWarning('Saisissez une référence HLS valide.'); }
        }
        if ($source === 'media' && $url !== '' && (!str_starts_with($url, '/medias/') || preg_match('/[?#]/', $url)
            || array_filter(explode('/', rawurldecode($url)), static fn (string $part): bool => str_starts_with($part, '.')) !== [])) {
            ExceptionResponse::throwWarning('La vidéo doit provenir des médias du Space.');
        }
        if (($props->library_id ?? '') !== '' && !preg_match('/^[1-9][0-9]{0,14}$/D', $props->library_id)) {
            ExceptionResponse::throwWarning('Bibliothèque Bunny invalide.');
        }
        if (($props->video_id ?? '') !== '' && !preg_match('/^[a-z0-9-]{1,100}$/iD', $props->video_id)) {
            ExceptionResponse::throwWarning('Identifiant vidéo Bunny invalide.');
        }
    }

    /** La validation du composant contrôle les droits médias avant cet accès aux fichiers. */
    private function assertMediaInSpace(object $props, int $spaceId): void
    {
        $space = $this->coreSpaceHelper->getSpace($spaceId);
        if ($space === null) throw new RuntimeException('Space média introuvable.');
        $this->coreMediaHelper->assertSelectableFile($space, $props->src ?? '', 'video');
    }

    public function render(object $item, PageRenderContext $context): string
    {
        $props = $item->props;
        $spaceId = $context->spaceId;
        self::validate($props);
        $source = $props->source ?? 'media';
        if ($source === 'hls') {
            if (($props->src ?? '') === '') return '';
            try { return $this->coreSecureVideoHelper->render($props->src, $spaceId); }
            catch (RuntimeException) { return ''; }
        }
        if ($source === 'media') {
            if (($props->src ?? '') === '') return '';
            try { $this->assertMediaInSpace($props, $spaceId); }
            catch (RuntimeException) { return ''; }

            return '<video src="' . HtmlTools::escape($props->src) . '" controls playsinline preload="metadata" style="display:block;width:100%;max-width:100%">Votre navigateur ne peut pas lire cette vidéo.</video>';
        }
        if ($source === 'url') {
            $url = VideoEmbedUrl::normalize($props->src ?? '');
            if ($url === null) return '';
        } else {
            if (($props->library_id ?? '') === '' || ($props->video_id ?? '') === '') return '';
            $url = $this->coreShortCodeHelper->bunnyUrl($props->library_id, $props->video_id, $spaceId);
        }

        // Les URL des lecteurs sont reconstruites depuis leurs seuls identifiants validés.
        return '<div class="ratio ratio-16x9"><iframe src="' . HtmlTools::escape($url)
            . '" title="Lecteur vidéo" loading="lazy" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"'
            . ' allow="fullscreen; picture-in-picture; encrypted-media"></iframe></div>';
    }
}
