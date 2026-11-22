<?php

namespace YTassy\Tycms\CoreExts\Core\Views;

use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseView;
use YTassy\Tycms\CoreExts\Core\Helper\TraitCoreAdditionalHelpers;

/** Connexion de secours indépendante de la configuration des pages et sans inscription. */
class Login extends BaseView
{
    use TraitCoreAdditionalHelpers;

    protected array $authProps;

    public function __construct(BaseApplicationItem $app)
    {
        parent::__construct($app);
        $space = $this->coreSpaceHelper->getCurrentSpace();
        $locale = $this->corePublicLocaleHelper->locale() ?? $this->coreSpaceLanguageHelper->getDefaultLocale($space->id);
        $this->authProps = [
            'initialCredentials' => $this->coreAuthenticationHelper->developmentCredentials(),
            'kind' => 'login', 'configToken' => $this->coreAuthenticationHelper->token($space, 'fallback', $locale),
            'settings' => \YTassy\Tycms\CoreExts\Core\PageComponents\Login::getDefaultData(),
            'available' => true, 'authenticated' => false, 'alreadyMember' => false, 'loginUrl' => '/login-fb',
            'destination' => $this->coreAuthenticationHelper->destination(),
            'providers' => array_map(static fn ($provider) => [
                'id' => (int) $provider->id, 'text' => trim($provider->login_button_text) ?: 'Continuer avec ' . $provider->name,
            ], $this->coreSsoProviderHelper->getLoginProviders($space)),
        ];
    }
}
