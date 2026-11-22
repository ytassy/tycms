<?php

namespace YTassy\Tycms\CoreExts\Core\PageComponents;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\{PageRenderContext, PageRenderMode};

final class MyAccount extends CorePageComponent
{
    public static function getId(): string { return 'core.my-account'; }
    public static function getLabel(): string { return 'Mon compte'; }
    public static function getCategory(): string { return 'Authentification'; }
    public static function getFormVueComponentName(): ?string { return 'core/my-account'; }
    public static function getPublicVueComponentName(): ?string { return 'core/my-account'; }

    public static function getDefaultData(): array
    {
        return [
            'readonly' => false, 'layout' => 'simple',
            'show_first_name' => true, 'edit_first_name' => true,
            'show_last_name' => true, 'edit_last_name' => true,
            'show_email' => true, 'edit_email' => true,
            'show_timezone' => true, 'edit_timezone' => true,
            'show_password' => true, 'edit_password' => true,
            'show_sso' => true, 'add_sso' => true, 'remove_sso' => true,
            'show_integrations' => true, 'add_integrations' => true, 'remove_integrations' => true,
        ];
    }

    public static function getPropsSchema(): array
    {
        $properties = array_fill_keys(array_keys(self::getDefaultData()), ['type' => 'boolean']);
        $properties['layout'] = ['type' => 'string', 'enum' => ['simple', 'list', 'table']];

        return ['type' => 'object', 'additionalProperties' => false, 'properties' => $properties];
    }

    public function getPublicProps(object $node, PageRenderContext $context): object
    {
        // Aucun profil dans le HTML : le compte est chargé par l'API privée de la session courante.
        return (object) ['settings' => $node->props, 'authenticated' => $this->session->getUserId() !== null];
    }

    public function render(object $node, PageRenderContext $context): string
    {
        if ($context->mode === PageRenderMode::PUBLIC) {
            return $this->session->getUserId() === null ? '' : '<p role="status">Chargement du compte…</p>';
        }

        // Les aperçus ne chargent jamais les données personnelles de l'éditeur.
        $rows = [];
        foreach (['first_name' => ['Prénom', 'Camille'], 'last_name' => ['Nom', 'Martin'],
            'email' => ['E-mail', 'camille@example.com'], 'timezone' => ['Fuseau horaire', 'Europe/Paris'],
            'password' => ['Mot de passe', '••••••••']] as $field => [$label, $example]) {
            if ($node->props->{'show_' . $field}) $rows[] = [$label, $example];
        }

        // Reprendre le conteneur neutre du rendu public, sans carte ajoutée par l'aperçu.
        $html = '<div class="my-account">' . $this->previewDetails($rows, $node->props->layout ?? 'simple');
        if (!$node->props->readonly) $html .= '<button type="button" class="btn btn-primary mt-3" disabled>Modifier</button>';
        if ($node->props->show_sso) $html .= '<h3 class="h6 mt-4">Connexions SSO</h3><p>Fournisseurs associés au compte.</p>';
        if ($node->props->show_integrations) $html .= '<h3 class="h6 mt-4">Mes intégrations</h3><p>Autorisations OAuth personnelles.</p>';

        return $html . '</div>';
    }

    private function previewDetails(array $rows, string $layout): string
    {
        if ($rows === []) return '';

        // Employer les mêmes balises et classes que le rendu Vue public.
        if ($layout === 'table') {
            $html = '<div class="table-responsive"><table class="table table-sm align-middle mb-3">'
                . '<caption class="visually-hidden">Informations de mon compte</caption>'
                . '<tbody>';
            foreach ($rows as [$label, $value]) {
                $html .= '<tr><th scope="row">' . $this->escape($label) . '</th><td class="text-break">' . $this->escape($value) . '</td></tr>';
            }
            return $html . '</tbody></table></div>';
        }

        $html = '<dl>';
        foreach ($rows as [$label, $value]) {
            if ($layout === 'list') {
                $html .= '<div class="d-flex flex-wrap align-items-baseline gap-2 mb-2"><dt>'
                    . $this->escape($label) . ' :</dt><dd class="text-break mb-0">' . $this->escape($value) . '</dd></div>';
            } else {
                $html .= '<dt>' . $this->escape($label) . '</dt><dd class="text-break">' . $this->escape($value) . '</dd>';
            }
        }
        return $html . '</dl>';
    }
}
