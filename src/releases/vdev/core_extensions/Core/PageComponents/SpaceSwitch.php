<?php

namespace YTassy\Tycms\CoreExts\Core\PageComponents;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageEditContext;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageNormalizationContext;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageRenderContext;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageRenderMode;

/** Sélecteur public Vue ; les sources et les affichages restent des paramètres éditoriaux. */
final class SpaceSwitch extends CorePageComponent
{
    public static function getId(): string { return 'core.space-switch'; }
    public static function getLabel(): string { return 'Changer de Space'; }
    public static function getCategory(): string { return 'Navigation'; }
    public static function getFormVueComponentName(): ?string { return 'core/space-switch'; }
    public static function getPublicVueComponentName(): ?string { return 'core/space-switch'; }

    public static function getDefaultData(): array
    {
        return ['from_domain' => true, 'from_member' => true, 'from_list' => false, 'space_ids' => [],
            'show_logo' => true, 'logo_alignment' => 'left', 'logo_max_width' => 0,
            'show_name' => true, 'show_short_description' => true,
            'show_description' => false, 'destination' => 'user', 'hide_closed' => false,
            'closed_text' => 'Inscription fermée'];
    }

    public static function getPropsSchema(): array
    {
        $properties = [
            'space_ids' => ['type' => 'array', 'maxItems' => 100, 'uniqueItems' => true,
                'items' => ['type' => 'integer', 'minimum' => 1]],
            'destination' => ['type' => 'string', 'enum' => ['home', 'user']],
            'logo_alignment' => ['type' => 'string', 'enum' => ['left', 'center', 'right']],
            'logo_max_width' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 1000],
            'closed_text' => ['type' => 'string', 'maxLength' => 200],
        ];
        foreach (['from_domain', 'from_member', 'from_list', 'show_logo', 'show_name',
            'show_short_description', 'show_description', 'hide_closed'] as $key) {
            $properties[$key] = ['type' => 'boolean'];
        }
        return ['type' => 'object', 'properties' => $properties, 'additionalProperties' => false];
    }

    public static function getEditorFields(): array
    {
        return [['name' => 'closed_text', 'type' => 'text', 'localized' => true, 'maxLength' => 200]];
    }

    public function getEditContext(PageEditContext $context): array
    {
        return ['plg_core_switch_spaces' => $this->coreSpaceSwitchHelper->editorOptions($context->spaceId)];
    }

    public function validateData(object $data, PageNormalizationContext $context): void
    {
        $allowed = array_column($context->editor?->get('plg_core_switch_spaces', []) ?? [], 'value');
        foreach ($data->space_ids ?? [] as $id) {
            $this->assert(in_array($id, $allowed, true), 'Espace de la liste indisponible pour cet éditeur.');
        }
    }

    public function getPublicProps(object $node, PageRenderContext $context): object
    {
        return (object) [...array_intersect_key(get_object_vars($node->props), array_flip([
            'show_logo', 'show_name', 'show_short_description', 'show_description', 'closed_text',
        ])),
            // Les réglages du logo sont bornés ici, même pour un document historique incomplet.
            'logo_alignment' => $this->logoAlignment($node->props),
            'logo_max_width' => $this->logoMaxWidth($node->props),
            'configToken' => $this->coreSpaceSwitchHelper->configurationToken($node->props)];
    }

    public function render(object $node, PageRenderContext $context): string
    {
        if ($context->mode === PageRenderMode::PUBLIC) {
            return '<p class="text-body-secondary" role="status">Chargement des espaces…</p>'
                . '<noscript>Activez JavaScript pour choisir un espace.</noscript>';
        }

        // L’aperçu est informatif : aucun changement de session depuis le canevas.
        $html = '<div class="d-flex flex-wrap gap-3">';
        foreach ($this->coreSpaceSwitchHelper->candidates($node->props) as $space) {
            $data = $this->coreSpaceSwitchHelper->present($space, $node->props);
            if ($node->props->hide_closed && !$data['member'] && !$data['registration_enabled']) continue;
            $html .= '<div class="card flex-fill"><div class="card-body">';
            if ($data['logo'] !== '') $html .= '<img src="' . $this->escape($data['logo']) . '" alt="" style="' . $this->previewLogoStyle($node->props) . '" loading="lazy">';
            if ($node->props->show_name) $html .= '<h3 class="h5">' . $this->escape($data['title']) . '</h3>';
            if ($data['short_description'] !== '') $html .= '<p>' . $this->escape($data['short_description']) . '</p>';
            $html .= $data['description'];
            $label = $data['member'] ? 'Entrer' : ($data['registration_enabled'] ? 'S’inscrire' : $node->props->closed_text);
            $html .= '<button type="button" class="btn btn-outline-primary" disabled>' . $this->escape($label) . '</button></div></div>';
        }
        return $html . '</div>';
    }

    private function logoAlignment(object $props): string
    {
        // Une valeur absente ou historique retombe sur l'affichage à gauche.
        $alignment = $props->logo_alignment ?? 'left';
        return in_array($alignment, ['left', 'center', 'right'], true) ? $alignment : 'left';
    }

    private function logoMaxWidth(object $props): int
    {
        // 0 désigne la pleine largeur de la carte ; la borne protège le style inline.
        return max(0, min(1000, (int) ($props->logo_max_width ?? 0)));
    }

    private function previewLogoStyle(object $props): string
    {
        $maxWidth = $this->logoMaxWidth($props);
        $style = 'max-width:' . ($maxWidth > 0 ? $maxWidth . 'px' : '100%') . ';max-height:80px';

        // L'aperçu ne centre que le logo, comme le composant public.
        if ($this->logoAlignment($props) === 'center') {
            $style .= ';display:block;margin-left:auto;margin-right:auto';
        } elseif ($this->logoAlignment($props) === 'right') {
            $style .= ';display:block;margin-left:auto;margin-right:0';
        }

        return $style;
    }
}
