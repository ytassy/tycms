<?php

namespace YTassy\Tycms\CoreExts\Core\PageComponents;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageEditContext;
use YTassy\Tycms\Lib\Forms\Form;

final class Login extends AuthenticationComponent
{
    public static function getId(): string { return 'core.login'; }
    public static function getLabel(): string { return 'Login'; }
    public static function getPublicVueComponentName(): ?string { return 'core/authentication'; }

    public static function getDefaultData(): array
    {
        return ['form_enabled' => true, 'sso_enabled' => true, 'form_text' => '', 'sso_text' => '',
            'show_forgot_password' => true, 'forgot_password_text' => 'Mot de passe oublié ?'];
    }

    public static function getPropsSchema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'properties' => [
            'form_enabled' => ['type' => 'boolean'], 'sso_enabled' => ['type' => 'boolean'],
            'form_text' => ['type' => 'string', 'maxLength' => 20000],
            'sso_text' => ['type' => 'string', 'maxLength' => 20000],
            'show_forgot_password' => ['type' => 'boolean'],
            'forgot_password_text' => ['type' => 'string', 'maxLength' => 200],
        ]];
    }

    public static function getEditorFields(): array
    {
        return [
            ['name' => 'form_text', 'type' => 'rich', 'localized' => true, 'maxLength' => 20000],
            ['name' => 'sso_text', 'type' => 'rich', 'localized' => true, 'maxLength' => 20000],
            ['name' => 'forgot_password_text', 'type' => 'text', 'localized' => true, 'maxLength' => 200],
        ];
    }

    public function getGeneralForm(PageEditContext $context): ?Form
    {
        return (new Form($this))->setId('core.page.login')->setHideSingleSectionTab()
            ->addSection('general', 'Connexion')
            ->addField('general', 'form_enabled', 'core.switch', ['label' => 'Activer la connexion par e-mail et mot de passe', 'default' => true])
            ->addField('general', 'sso_enabled', 'core.switch', ['label' => 'Activer la connexion SSO si disponible', 'default' => true])
            ->addField('general', 'form_text', 'core.localized_text', ['label' => 'Texte entre les champs et le bouton Se connecter', 'mode' => 'rich', 'maxLength' => 20000, 'default' => ''])
            ->addField('general', 'sso_text', 'core.localized_text', ['label' => 'Texte avant les boutons SSO', 'mode' => 'rich', 'maxLength' => 20000, 'default' => ''])
            ->addField('general', 'show_forgot_password', 'core.switch', ['label' => 'Afficher le lien Mot de passe oublié', 'default' => true])
            ->addField('general', 'forgot_password_text', 'core.localized_text', ['label' => 'Texte du lien Mot de passe oublié', 'maxLength' => 200, 'default' => 'Mot de passe oublié ?']);
    }
}
