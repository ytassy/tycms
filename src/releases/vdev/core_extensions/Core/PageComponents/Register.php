<?php

namespace YTassy\Tycms\CoreExts\Core\PageComponents;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageEditContext;
use YTassy\Tycms\Lib\Forms\Form;

final class Register extends AuthenticationComponent
{
    public static function getId(): string { return 'core.register'; }
    public static function getLabel(): string { return 'Register'; }
    public static function getPublicVueComponentName(): ?string { return 'core/authentication'; }

    public static function getDefaultData(): array
    {
        return ['form_enabled' => true, 'sso_enabled' => true, 'terms_enabled' => false,
            'terms_text' => 'J’accepte les conditions générales d’utilisation.'];
    }

    public static function getPropsSchema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'properties' => [
            'form_enabled' => ['type' => 'boolean'], 'sso_enabled' => ['type' => 'boolean'],
            'terms_enabled' => ['type' => 'boolean'], 'terms_text' => ['type' => 'string', 'maxLength' => 20000],
        ]];
    }

    public static function getEditorFields(): array
    {
        return [['name' => 'terms_text', 'type' => 'rich', 'localized' => true, 'maxLength' => 20000]];
    }

    public function getGeneralForm(PageEditContext $context): ?Form
    {
        return (new Form($this))->setId('core.page.register')->setHideSingleSectionTab()
            ->addSection('general', 'Inscription')
            ->addField('general', 'form_enabled', 'core.switch', ['label' => 'Activer l’inscription par formulaire', 'default' => true])
            ->addField('general', 'sso_enabled', 'core.switch', ['label' => 'Activer l’inscription SSO si disponible', 'default' => true])
            ->addField('general', 'terms_enabled', 'core.switch', ['label' => 'Exiger l’acceptation des CGU (formulaire et SSO)', 'default' => false])
            ->addField('general', 'terms_text', 'core.localized_text', ['label' => 'Texte de la case CGU', 'mode' => 'rich', 'maxLength' => 20000,
                'default' => 'J’accepte les conditions générales d’utilisation.']);
    }
}
