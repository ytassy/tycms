<?php

namespace YTassy\Tycms\CoreExts\Core\PageComponents;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageEditContext;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageNormalizationContext;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageRenderContext;
use YTassy\Tycms\Lib\Forms\Form;
use YTassy\Tycms\Response\ExceptionResponse;

/** Intégration HTTPS isolée : aucun HTML ni permission sandbox ne vient du document. */
final class Iframe extends CorePageComponent
{
    use \YTassy\Tycms\CoreExts\Security\Traits\TraitSecurityHelpers;

    public static function getId(): string { return 'core.iframe'; }
    public static function getLabel(): string { return 'Iframe'; }
    public static function getCategory(): string { return 'Médias'; }
    public static function getCanvasVueComponentName(): ?string { return 'core/iframe'; }

    public static function getDefaultData(): array
    {
        return ['src' => '', 'title' => 'Contenu intégré', 'height' => 480, 'fullscreen' => true];
    }

    public static function getPropsSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'src' => ['type' => 'string', 'maxLength' => 4096],
            'title' => ['type' => 'string', 'maxLength' => 200],
            'height' => ['type' => 'integer', 'minimum' => 80, 'maximum' => 4000],
            'fullscreen' => ['type' => 'boolean'],
        ], 'additionalProperties' => false];
    }

    public static function getEditorFields(): array
    {
        return [['name' => 'title', 'label' => 'Titre accessible', 'type' => 'text', 'localized' => true, 'maxLength' => 200]];
    }

    public function getGeneralForm(PageEditContext $context): ?Form
    {
        $allowed = $this->securityPolicyHelper->forSpace($context->spaceId)->iframeDescription();

        return (new Form($this))->setId('core.page.iframe')->setHideSingleSectionTab()
            ->addSection('general', 'Général')
            ->addField('general', 'src', 'core.text', [
                'label' => 'URL d’intégration HTTPS', 'default' => '',
                'description' => 'Collez uniquement l’URL d’intégration du service, par exemple l’URL publiée de Google Slides. ' . $allowed,
                'validation' => ['type' => 'string', 'maxLength' => 4096],
            ])
            ->addField('general', 'title', 'core.localized_text', ['label' => 'Titre accessible', 'default' => 'Contenu intégré', 'maxLength' => 200])
            ->addField('general', 'height', 'core.number', [
                'label' => 'Hauteur (px)', 'default' => 480, 'min' => 80, 'max' => 4000, 'step' => 1,
                'validation' => ['type' => 'integer', 'minimum' => 80, 'maximum' => 4000],
            ])
            ->addField('general', 'fullscreen', 'core.switch', ['label' => 'Autoriser le plein écran', 'default' => true]);
    }

    public function normalizeData(object $data, PageNormalizationContext $context): object
    {
        self::validate($data);
        return clone $data;
    }

    public function validateData(object $data, PageNormalizationContext $context): void
    {
        if ($data->src !== '') {
            $this->assert($this->securityPolicyHelper->allowsFrame($context->spaceId, $data->src),
                'Cette origine d’iframe n’est pas autorisée par la configuration Sécurité de ce Space.');
        }
    }

    public static function validate(object $props): void
    {
        // Ne jamais récupérer l’URL au serveur : le navigateur seul charge le contenu distant.
        $url = $props->src ?? '';
        if (!is_string($url) || strlen($url) > 4096 || ($url !== '' && (
            preg_match('/[\x00-\x20\x7f"\x27<>\\\\]/', $url)
            || filter_var($url, FILTER_VALIDATE_URL) === false
        ))) {
            ExceptionResponse::throwWarning('Saisissez une URL d’intégration HTTPS valide.');
        }
        if ($url !== '') {
            $parts = parse_url($url);
            if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
                || isset($parts['user']) || isset($parts['pass'])) {
                ExceptionResponse::throwWarning('L’iframe accepte uniquement une URL HTTPS sans identifiants.');
            }
        }

        // Borner les dimensions et les permissions même hors du formulaire déclaratif.
        if (!is_int($props->height ?? null) || $props->height < 80 || $props->height > 4000) {
            ExceptionResponse::throwWarning('La hauteur de l’iframe doit être comprise entre 80 et 4000 pixels.');
        }
        if (!is_bool($props->fullscreen ?? null)) {
            ExceptionResponse::throwWarning('Le réglage plein écran de l’iframe est invalide.');
        }
    }

    public function render(object $item, PageRenderContext $context): string
    {
        $props = $item->props;
        self::validate($props);
        // Relire la politique du Space réel : une restriction ROOT s’applique aussi aux pages existantes.
        if ($props->src === '' || !$this->securityPolicyHelper->allowsFrame($context->spaceId, $props->src)) return '';

        // Sans allow-same-origin, un contenu du même site reste lui aussi isolé.
        // Les popups et la navigation du parent restent interdites, y compris par formulaire.
        $title = $context->text($props->title ?? '');
        if (trim($title) === '') $title = 'Contenu intégré';
        return '<iframe src="' . $this->escape($props->src) . '" title="' . $this->escape($title)
            . '" style="display:block;width:100%;height:' . $props->height . 'px;border:0" loading="lazy"'
            . ' sandbox="allow-scripts allow-forms allow-presentation" referrerpolicy="no-referrer"'
            . ($props->fullscreen ? ' allow="fullscreen" allowfullscreen' : '') . '></iframe>';
    }
}
