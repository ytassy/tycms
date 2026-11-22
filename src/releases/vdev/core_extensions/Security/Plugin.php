<?php

namespace YTassy\Tycms\CoreExts\Security;

use Override;
use YTassy\Tycms\Extensions\BasePlugin;
use YTassy\Tycms\Lib\Forms\Form;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\BasePermissions;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\IExtensionEvents;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Menu\MenuItemDef;
use YTassy\Tycms\Response\Response;
use YTassy\Tycms\Response\HtmlResponse;
use YTassy\Tycms\CoreExts\Core\Controllers\Admin\AdminController;
use YTassy\Tycms\CoreExts\Security\Traits\TraitSecurityHelpers;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Traits\TraitCoreSharedHelpers;
use YTassy\Tycms\CoreExts\Security\Migration\Mig_1_0_1;
use YTassy\Tycms\CoreExts\Security\Migration\Mig_1_0_2;
use YTassy\Tycms\Routing\Route;

class Plugin extends BasePlugin implements IExtensionEvents
{
    use TraitSecurityHelpers;
    use TraitCoreSharedHelpers;

    public function getName(): string
    {
        return "Sécurité";
    }

    public function getVersion(): string
    {
        return APP_VERSION;
    }

    public function getDescription(): string
    {
        return "Paramètres de sécurité du site";
    }


    public function getConfigEditPermissions(): array
    {
        // Les droits génériques d’édition des extensions ne donnent aucun accès à la sécurité.
        return [Permissions::ROOT];
    }

    public function getIconClass(): string
    {
        return "fa-solid fa-shield-halved";
    }

    #[Override]
    protected function _getMigrations(): array
    {
        return ['1.0.1' => [Mig_1_0_1::class], '1.0.2' => [Mig_1_0_2::class]];
    }

    public function coreOnRegisterAclPermissions(): ?BasePermissions {
        return null;
    }


    public function coreLanguageCatalogFiles(): array { return []; }

    public function onAfterResolveRoute(Route $route): void
    {
        // Ce hook permet un vrai 403 HTML/JSON, sans redirection des messages de formulaire.
        $this->securityWafHelper->check($route);
    }

    public function onBeforeSendResponse(Response $response)
    {
        $route = $this->app->getRoute();
        $administration = $route?->controller instanceof AdminController
            && $route->controllerMethod === 'home' && !$route->isApi;
        $sources = $this->securityPolicyHelper->responseSources($administration);
        // Les documents HTML peuvent porter le formulaire SSO ; les autres réponses n’en ont pas besoin.
        $formActions = $response instanceof HtmlResponse
            ? $this->securityPolicyHelper->formActionsForCurrentSpace()
            : [];
        $headers = ResponseHeaders::build($sources, $this->app->getConf()->dev === true, $administration,
            $this->coreFrontendAssets->developmentOrigins(), $this->coreFrontendAssets->cspNonce(), $formActions);

        // Une réponse spécialisée garde les en-têtes qu’elle a explicitement définis.
        foreach ($headers as $name => $value) {
            if (!array_key_exists($name, $response->httpHeaders)) $response->setHeaders([$name => $value]);
        }
    }

    public function prepareConfigForStorage(int $spaceId, array $params): array
    {
        $values = Configuration::normalize($params);
        if ($spaceId === 1 && $values['waf_use_root']) {
            throw new \RuntimeException('Le Space Root ne peut pas hériter de lui-même.');
        }
        // La réponse à l’enregistrement doit relire les nouvelles valeurs de la même requête.
        $this->securityPolicyHelper->forget($spaceId);
        return $values;
    }

    /**
     * @return array<MenuItemDef>
     */
    public function coreOnRegisterMenuTypes(): array{
        return [];
    }

    public function coreRegisterConfigForm(): ?Form
    {
        return ConfigurationForm::build($this);
    }
}
