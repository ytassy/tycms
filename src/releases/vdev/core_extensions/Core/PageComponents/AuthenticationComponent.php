<?php

namespace YTassy\Tycms\CoreExts\Core\PageComponents;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageRenderContext;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageRenderMode;
use YTassy\Tycms\Lib\HtmlSanitizer\HtmlSanitizer;

/** Habillage commun ; les aperçus restent sans action ni jeton d'authentification. */
abstract class AuthenticationComponent extends CorePageComponent
{
    public static function getCategory(): string { return 'Authentification'; }

    public function getPublicProps(object $node, PageRenderContext $context): object
    {
        $kind = static::getId() === 'core.login' ? 'login' : 'register';
        $space = $this->coreSpaceHelper->getSpace($context->spaceId);
        $token = $this->coreAuthenticationHelper->token($space, $kind, $context->locale, $node->id, $context->page->id);
        $settings = clone $node->props;

        // Les traductions et les anciennes valeurs passent aussi par la purification au rendu.
        foreach (static::getEditorFields() as $field) {
            if ($field['type'] === 'rich') {
                $settings->{$field['name']} = HtmlSanitizer::sanitize((string) $settings->{$field['name']});
            }
        }
        $authenticated = $this->session->getUserId() !== null;
        $enabled = $kind !== 'register' || $this->coreAuthenticationHelper->registrationEnabled($space);
        $providers = [];
        if ($settings->sso_enabled && $enabled && !$authenticated && $token !== null) {
            foreach ($this->coreSsoProviderHelper->getLoginProviders($space) as $provider) {
                $providers[] = ['id' => (int) $provider->id,
                    'text' => trim($kind === 'login' ? $provider->login_button_text : $provider->register_button_text)
                        ?: ($kind === 'login' ? 'Continuer avec ' : 'S’inscrire avec ') . $provider->name];
            }
        }
        return (object) ['kind' => $kind, 'configToken' => $token ?? '', 'settings' => $settings,
            ...($kind === 'login' ? ['initialCredentials' => $this->coreAuthenticationHelper->developmentCredentials()] : []),
            'available' => $enabled && $token !== null, 'authenticated' => $authenticated,
            'alreadyMember' => $authenticated && $this->coreAclHelper->hasGlobalPermission(
                \YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions::MEMBER, $space->id),
            'providers' => $providers,
            'loginUrl' => $this->coreUserHelper->getLoginUrl(),
            'registerUrl' => $kind === 'login' && $this->coreAuthenticationHelper->registrationEnabled($space)
                ? ($this->coreAuthenticationHelper->pageUrl($space, 'register', $context->locale) ?? '') : '',
            'destination' => $this->coreAuthenticationHelper->destination()];
    }

    public function render(object $node, PageRenderContext $context): string
    {
        if ($context->mode === PageRenderMode::PUBLIC) {
            return '<p role="status">Chargement du formulaire…</p><noscript>Activez JavaScript pour utiliser ce formulaire.</noscript>';
        }

        $register = static::getId() === 'core.register';
        $html = '<div class="card"><div class="card-body">';
        if ($node->props->form_enabled) {
            foreach ($register ? ['Prénom', 'Nom', 'Adresse e-mail', 'Mot de passe', 'Confirmer le mot de passe']
                : ['Adresse e-mail', 'Mot de passe'] as $label) {
                $html .= '<div class="mb-2"><label class="form-label">' . $label . '</label><input class="form-control" disabled></div>';
            }
            if (!$register) $html .= HtmlSanitizer::sanitize($context->text($node->props->form_text));
            if ($register && $node->props->terms_enabled) {
                $html .= '<div class="d-flex gap-2"><input type="checkbox" disabled><div>'
                    . HtmlSanitizer::sanitize($context->text($node->props->terms_text)) . '</div></div>';
            }
            $html .= '<button type="button" class="btn btn-primary" disabled>' . ($register ? 'Créer mon compte' : 'Se connecter') . '</button>';
            if (!$register && $node->props->show_forgot_password) {
                $html .= '<p class="mt-2 mb-0">' . $this->escape($context->text($node->props->forgot_password_text)) . '</p>';
            }
        }
        if ($node->props->sso_enabled) {
            if (!$register) $html .= HtmlSanitizer::sanitize($context->text($node->props->sso_text));
            $html .= '<p class="text-muted mt-3 mb-0">Boutons SSO selon les fournisseurs disponibles du Space.</p>';
        }
        return $html . '</div></div>';
    }
}
