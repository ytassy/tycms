<?php

namespace YTassy\Tycms\CoreExts\Core;

use Override;
use YTassy\Tycms\CoreExts\Core\Controllers\HomeController;
use YTassy\Tycms\Extensions\BasePlugin;
use YTassy\Tycms\Lib\Forms\Form;
use YTassy\Tycms\Lib\Forms\FormFactory;
use YTassy\Tycms\Lib\Forms\FormFieldType;
use YTassy\Tycms\CoreExts\Core\Controller\ControllerManager;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\IPageComponentProvider;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Security\SecurityCheckRegistry;
use YTassy\Tycms\CoreExts\Core\Security\Checks\ApiTokensCheck;
use YTassy\Tycms\CoreExts\Core\Security\Checks\CronTokenCheck;
use YTassy\Tycms\CoreExts\Core\Security\Checks\DebugModeCheck;
use YTassy\Tycms\CoreExts\Core\Security\Checks\FirewallCheck;
use YTassy\Tycms\CoreExts\Core\Security\Checks\MediaRootOnlyCheck;
use YTassy\Tycms\CoreExts\Core\Security\Checks\RootAccountsCheck;
use YTassy\Tycms\CoreExts\Core\Security\Checks\RootSpaceSegregationCheck;
use YTassy\Tycms\CoreExts\Core\Controllers\Admin\AdminApiController;
use YTassy\Tycms\CoreExts\Core\Controllers\Admin\AdminLogsController;
use YTassy\Tycms\CoreExts\Core\Controllers\Admin\AdminExtensionsController;
use YTassy\Tycms\CoreExts\Core\Controllers\Admin\AdminExtensionSourceController;
use YTassy\Tycms\CoreExts\Core\Controllers\Admin\AdminFontController;
use YTassy\Tycms\CoreExts\Core\Controllers\Admin\AdminMenuApiController;
use YTassy\Tycms\CoreExts\Core\Controllers\Admin\AdminMediaController;
use YTassy\Tycms\CoreExts\Core\Controllers\Admin\AdminPageController;
use YTassy\Tycms\CoreExts\Core\Controllers\Admin\AdminController;
use YTassy\Tycms\CoreExts\Core\Controllers\Admin\AdminSpaceController;
use YTassy\Tycms\CoreExts\Core\Controllers\Admin\AdminRoleController;
use YTassy\Tycms\CoreExts\Core\Controllers\Admin\AdminUserController;
use YTassy\Tycms\CoreExts\Core\Controllers\Admin\AdminUserGroupController;
use YTassy\Tycms\CoreExts\Core\Controllers\Admin\AdminLanguageController;
use YTassy\Tycms\CoreExts\Core\Controllers\Admin\AdminSsoProviderController;
use YTassy\Tycms\CoreExts\Core\Controllers\Admin\AdminServiceAccountController;
use YTassy\Tycms\CoreExts\Core\Controllers\Admin\AdminAiController;
use YTassy\Tycms\CoreExts\Core\Controllers\Admin\AdminEmailTemplateController;
use YTassy\Tycms\CoreExts\Core\Controllers\AiController;
use YTassy\Tycms\CoreExts\Core\Controllers\PageButtonApiController;
use YTassy\Tycms\CoreExts\Core\Controllers\PageController;
use YTassy\Tycms\CoreExts\Core\Controllers\PageCssController;
use YTassy\Tycms\CoreExts\Core\Controllers\CronController;
use YTassy\Tycms\CoreExts\Core\Controllers\SecureVideoController;
use YTassy\Tycms\CoreExts\Core\Controllers\SpaceController;
use YTassy\Tycms\CoreExts\Core\Controllers\UserController;
use YTassy\Tycms\CoreExts\Core\Controllers\PasswordResetController;
use YTassy\Tycms\CoreExts\Core\Controllers\IntegrationController;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageComponentRegistry;
use YTassy\Tycms\CoreExts\Core\Helper\TraitAllHelpers;
use YTassy\Tycms\CoreExts\Core\Migration\Mig_1_0_0_p1;
use YTassy\Tycms\CoreExts\Core\Migration\Mig_1_0_0_p2;
use YTassy\Tycms\CoreExts\Core\Migration\Mig_1_0_0_p3;
use YTassy\Tycms\CoreExts\Core\Migration\Mig_1_0_0_p4;
use YTassy\Tycms\CoreExts\Core\Migration\Mig_1_0_0_p5;
use YTassy\Tycms\CoreExts\Core\Migration\Mig_1_1_3;
use YTassy\Tycms\CoreExts\Core\Migration\Mig_1_1_4;
use YTassy\Tycms\CoreExts\Core\Migration\Mig_1_1_5;
use YTassy\Tycms\CoreExts\Core\Migration\Mig_1_1_9;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Mail\IEmailTemplateProvider;
use YTassy\Tycms\CoreExts\Core\Mail\CoreEmailTemplates;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\BasePermissions;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\IExtensionEvents;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Menu\MenuItemDef;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationActionRegistry;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationTriggerRegistry;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\IAutomationProvider;
use YTassy\Tycms\CoreExts\Core\Automation\CoreAutomationActions;
use YTassy\Tycms\CoreExts\Core\Automation\CoreAutomationTriggers;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Response\RedirectResponse;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Routing\Controller\BaseController;
use YTassy\Tycms\Routing\Route;
use YTassy\Tycms\Routing\Router;

class Plugin extends BasePlugin implements IExtensionEvents, IEmailTemplateProvider, \YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\IPageComponentProvider, IAutomationProvider
{
    use TraitAllHelpers;

    public function getName(): string
    {
        return "Coeur du système";
    }

    public function getVersion(): string
    {
        return APP_VERSION;
    }

    public function getDescription(): string
    {
        return "Plug-in principal du système";
    }

    public function getIconClass(): string
    {
        return "fa-solid fa-gears";
    }

    public function coreOnRegisterAutomationActions(AutomationActionRegistry $registry): void
    {
        // Toutes les actions sont déclarées explicitement : une seule façon de
        // faire, et un test vérifie que le catalogue correspond au registre.
        foreach (CoreAutomationActions::definitions() as $definition) {
            $registry->register($definition);
        }
    }

    public function coreOnRegisterAutomationTriggers(AutomationTriggerRegistry $registry): void
    {
        foreach (CoreAutomationTriggers::definitions() as $definition) {
            $registry->register($definition);
        }
    }

    #[Override]
	protected function _getMigrations(): array
	{
		return [
            '1.0.0' => [
                Mig_1_0_0_p1::class, 
                Mig_1_0_0_p2::class, 
                Mig_1_0_0_p3::class,
                Mig_1_0_0_p4::class,
                Mig_1_0_0_p5::class
            ],
            '1.1.3' => [Mig_1_1_3::class],
            '1.1.4' => [Mig_1_1_4::class],
            '1.1.5' => [Mig_1_1_5::class],
            '1.1.9' => [Mig_1_1_9::class],
        ];
	}

    public function getControllers(): array
    {
        return [
            new HomeController($this),
            new UserController($this),
            new \YTassy\Tycms\CoreExts\Core\Controllers\AuthenticationController($this),
            new PasswordResetController($this),
            new IntegrationController($this),
            new \YTassy\Tycms\CoreExts\Core\Controllers\MyAccountController($this),
            new AiController($this),
            new SpaceController($this),
            new \YTassy\Tycms\CoreExts\Core\Controllers\SpaceSwitchController($this),
            new PageCssController($this),
            new PageButtonApiController($this),
            new SecureVideoController($this),
            new CronController($this),

            // Admin
            new AdminController($this),
            new AdminApiController($this),
            new AdminLogsController($this),
            new AdminMenuApiController($this),
            new AdminMediaController($this),
            new AdminPageController($this),
            new AdminSpaceController($this),
            new AdminRoleController($this),
            new AdminUserController($this),
            new \YTassy\Tycms\CoreExts\Core\Controllers\Admin\AdminUserAssignmentsController($this),
            new AdminUserGroupController($this),
            new AdminLanguageController($this),
            new AdminSsoProviderController($this),
            new AdminServiceAccountController($this),
            new AdminAiController($this),
            new AdminEmailTemplateController($this),
            new AdminExtensionsController($this),
            new AdminExtensionSourceController($this),
            new AdminFontController($this),
        ];
    }

    #[Override]
    public function onAfterLoadExtensions()
    {
        // Le registre reçoit seulement les définitions des extensions chargées.
        foreach ($this->app->getExtensions() as $owner => $extension) {
            if (!$extension instanceof IEmailTemplateProvider) {
                continue;
            }
            foreach ($extension->emailTemplates() as $definition) {
                $this->coreEmailTemplateRegistry->register($owner, $definition);
            }
        }

        // Enregistrer les catalogues fournis par les extensions chargées.
        foreach ($this->app->callExtensionEvent('coreLanguageCatalogFiles') as $owner => $files) {
            if ($files === []) {
                continue;
            }

            $this->coreLanguageCatalogRegistry->registerExtension($owner, $files);
        }

        // Load all permissions
        /** @var array<string,?BasePermissions> */
        $results = $this->app->callExtensionEvent('coreOnRegisterAclPermissions');

        foreach($results as $extName => $permissions)
        {
            if ($permissions instanceof BasePermissions)
            {
                $this->coreAclHelper->definePermissionsDefinition($extName, $permissions->getPermissions());
            }
        }

        // Toutes les extensions sont enregistrées : vérifier le graphe complet avant les routes.
        $this->coreAclHelper->getPermissionRequirements();

        // Core form field types
        $this->coreFormTypesHelper->registerCoreFieldTypes();

        // Load All Form Conf Extensions
        $formsByExtension = array_filter($this->app->callExtensionEvent('coreRegisterConfigForm'));

        $this->coreExtensionsHelper->setFormsByExtension($formsByExtension);

        // Check or Create Conf of extensions
        $this->coreExtensionsHelper->checkAndCreateExtensionConfig();

        return parent::onAfterLoadExtensions();
    }

    /**
     * Prépare les catalogues métier du site, une fois les extensions chargées.
     *
     * Les composants de page et les types de menu décrivent ce que le site sait
     * composer. Ils ne dépendent ni de la requête, ni du Space courant, ni des
     * routes : un parcours sans routage (exécution d'un scénario ou d'un
     * programme MCP) doit pouvoir les consulter et les utiliser.
     *
     * Le remplissage a lieu ici, et non pendant l'enregistrement des routes,
     * pour que l'ordre soit explicite : les types de menu sont connus avant
     * `MenuRouteHelper::registerRoutes()`, qui les transforme en routes.
     */
    #[Override]
    public function onPrepareExtensions(): void
    {
        $this->prepareWebsiteCatalogs();
    }

    private function prepareWebsiteCatalogs(): void
    {
        // Composants de page : chaque fournisseur déclare ses classes, le
        // registre refuse les doublons et les identifiants invalides.
        $registry = $this->app->getOrAddSharedExtensionsClass(PageComponentRegistry::class);

        foreach ($this->app->getExtensions() as $owner => $extension) {
            if ($extension instanceof IPageComponentProvider) {
                $registry->withOwner($owner, fn () => $extension->coreOnRegisterPageComponents($registry));
            }
        }

        // Types d'entrée de menu : déclarés par les extensions, indexés par
        // « extension__type » dans le routeur de menu.
        foreach ($this->app->callExtensionEvent('coreOnRegisterMenuTypes') as $result) {
            foreach ($result as $menuItemDef) {
                $this->coreMenuRouteHelper->addMenuType($menuItemDef);
            }
        }

        // Contrôles du bilan de sécurité. Aucun n'est persisté : ce sont des
        // classes qui lisent l'état du site au moment du rapport. Les
        // extensions en déclarent d'autres via `coreOnRegisterSecurityChecks`.
        $security = $this->app->getOrAddSharedExtensionsClass(SecurityCheckRegistry::class);

        foreach ([
            'plg_core.media-root-only' => MediaRootOnlyCheck::class,
            'plg_core.debug-mode' => DebugModeCheck::class,
            'plg_core.root-accounts' => RootAccountsCheck::class,
            'plg_core.root-space-segregation' => RootSpaceSegregationCheck::class,
            'plg_core.cron-token' => CronTokenCheck::class,
            'plg_core.api-tokens' => ApiTokensCheck::class,
            'plg_core.firewall' => FirewallCheck::class,
        ] as $id => $checkClass) {
            $security->register($id, new $checkClass($this->app), 'plg_core');
        }
    }

    #[Override]
    public function onRegisterRoutes(Router $router)
    {
        // Le Space et ses locales se résolvent au moment de servir une page :
        // ce parcours est propre à la requête HTTP et reste hors de la
        // préparation des catalogues.
        $this->coreSpaceHelper->resolveInitialSpace();

        // Le Space de session gagne toujours ; sa configuration détermine les locales admissibles.
        $spaceId = $this->coreSpaceHelper->getCurrentSpace()->id;
        $this->corePublicLocaleHelper->prepare($this->request, $spaceId);

        // Register Routes
        $this->coreMenuRouteHelper->registerRoutes($router);
    }


    public function onBeforeExecuteController(Route $route, BaseController $controller, string $controllerMethod, array $params) {
        
        new ControllerManager($this)->onControllerCall( $route, $controller, $controllerMethod, $params);

    }

    #[Override]
    public function onAfterResolveRoute(Route $route): void
    {
        // Seules les routes de menu publiques mémorisent une langue explicite.
        if (isset($route->routeData['menu'], $route->routeData['menu_item'])) {
            $this->corePublicLocaleHelper->rememberPublicRoute();
        }
    }

     /**
     * @return array<MenuItemDef>
     */
    public function coreOnRegisterMenuTypes(): array{
        return [
            new MenuItemDef('plg_core', 'text', null, null, 'Texte', '', '',
                ai: true,
                aiMcpAction: 'createTextMenuItem',
                aiMcpDoc: 'Entrée de texte sans destination de navigation : sert de '
                    . 'libellé dans un sous-menu. Aucun paramètre attendu.',
            ),
            new MenuItemDef('plg_core', 'separator', null, null, 'Séparateur', '', '',
                ai: true,
                aiMcpAction: 'createSeparatorMenuItem',
                aiMcpDoc: 'Séparateur visuel entre deux groupes d’entrées. N’a pas de '
                    . 'destination et ne sert pas de parent. Aucun paramètre attendu.',
            ),
            new MenuItemDef(
                'plg_core',
                'page',
                new PageController($this),
                ['GET' => 'page'],
                'Page',
                '',
                '',
                paramsFormDefinition: [
                    'id' => 'core.menu-item.page.params',
                    'hideSingleSectionTab' => true,
                    'sections' => [[
                        'id' => 'params',
                        'label' => 'Paramètres',
                        'fields' => [[
                            'name' => 'page_id',
                            'type' => 'catalog.core_page_item',
                            'label' => 'Page',
                            'required' => true,
                        ]],
                    ]],
                ],
                ai: true,
                aiMcpAction: 'createPageMenuItem',
                aiMcpDoc: 'Entrée ouvrant une page. La page visée doit appartenir au '
                    . 'même Space que le menu, et rester publiée.',
            ),
            new MenuItemDef('plg_core', 'logout', new UserController($this),
                ['GET' => 'logout'], 'Deconnexion', '', '',
                ai: false,
            ),

            // Admin
            new MenuItemDef('plg_core', 'admin', new AdminController($this),
                ['GET' => 'home'], 'Administration', '', '',
                ai: false,
            ),
        ];
    }

    /**
     * @return ?BasePermissions
     */
    public function coreOnRegisterAclPermissions(): ?BasePermissions {
        return new Permissions($this);
    }

    
    public function coreRegisterConfigForm(): ?Form {
        return $this->coreAppConfigFormsHelper->getConfigForm();
    }

    public function getConfigHiddenSections(int $spaceId): array
    {
        return $this->coreAppConfigurationHelper->hiddenSections($spaceId);
    }

    public function assertConfigSubmissionAllowed(int $spaceId, object $params): void
    {
        $this->coreAppConfigurationHelper->assertSubmissionAllowed($spaceId, $params);
    }

    public function prepareConfigForStorage(int $spaceId, array $params): array
    {
        return $this->coreAppConfigurationHelper->prepareForStorage($spaceId, $params);
    }

    public function prepareConfigForEditor(int $spaceId, array $params): array
    {
        return $this->coreAppConfigurationHelper->prepareForEditor($spaceId, $params);
    }

    public function coreOnRegisterPageComponents(PageComponentRegistry $registry): void
    {
        $registry->register(\YTassy\Tycms\CoreExts\Core\PageComponents\Row::class);
        $registry->register(\YTassy\Tycms\CoreExts\Core\PageComponents\Tabs::class);
        $registry->register(\YTassy\Tycms\CoreExts\Core\PageComponents\Accordion::class);
        $registry->register(\YTassy\Tycms\CoreExts\Core\PageComponents\Timeline::class);
        $registry->register(\YTassy\Tycms\CoreExts\Core\PageComponents\Testimonials::class);
        $registry->register(\YTassy\Tycms\CoreExts\Core\PageComponents\Heading::class);
        $registry->register(\YTassy\Tycms\CoreExts\Core\PageComponents\RichText::class);
        $registry->register(\YTassy\Tycms\CoreExts\Core\PageComponents\Html::class);
        $registry->register(\YTassy\Tycms\CoreExts\Core\PageComponents\Image::class);
        $registry->register(\YTassy\Tycms\CoreExts\Core\PageComponents\Video::class);
        $registry->register(\YTassy\Tycms\CoreExts\Core\PageComponents\Iframe::class);
        $registry->register(\YTassy\Tycms\CoreExts\Core\PageComponents\Audio::class);
        $registry->register(\YTassy\Tycms\CoreExts\Core\PageComponents\Mermaid::class);
        $registry->register(\YTassy\Tycms\CoreExts\Core\PageComponents\Diagram::class);
        $registry->register(\YTassy\Tycms\CoreExts\Core\PageComponents\FileList::class);
        $registry->register(\YTassy\Tycms\CoreExts\Core\PageComponents\Button::class);
        $registry->register(\YTassy\Tycms\CoreExts\Core\PageComponents\Separator::class);
        $registry->register(\YTassy\Tycms\CoreExts\Core\PageComponents\Menu::class);
        $registry->register(\YTassy\Tycms\CoreExts\Core\PageComponents\SpaceSwitch::class);
        $registry->register(\YTassy\Tycms\CoreExts\Core\PageComponents\Login::class);
        $registry->register(\YTassy\Tycms\CoreExts\Core\PageComponents\Register::class);
        $registry->register(\YTassy\Tycms\CoreExts\Core\PageComponents\MyAccount::class);
    }

    public function coreLanguageCatalogFiles(): array
    {
        return [
            'fr' => __DIR__ . '/Languages/fr.php',
            'en' => __DIR__ . '/Languages/en.php',
        ];
    }

    public function emailTemplates(): array
    {
        return CoreEmailTemplates::all();
    }


    public function onBeforeResolveRoute(){

        // Le protocole public des catalogues est indépendant du Space et de la langue :
        // aucune redirection vers le choix d'espace ou la connexion.
        if (str_starts_with($this->request->path, '/tycms-catalog/')) return;

        // Le secours et les retours d’authentification restent accessibles sur un hôte partagé.
        // Les WF contrôlent les identifiants, le CSRF et les états SSO avant toute modification.
        if ($this->request->path === '/login-fb' || $this->request->path === '/sso/start'
            || $this->request->path === '/sso/callback' || $this->request->path === '/forgot-password'
            || str_starts_with($this->request->path, '/reset-password/')) {
            $recoveryRoute = $this->router->resolve($this->request);
            if ($recoveryRoute?->controller instanceof PasswordResetController
                || $recoveryRoute?->controller instanceof UserController) {
                return;
            }
        }

        if ($this->request->isHtmlExpected())
        {
            if ($this->session->getSpaceId() == null)
            {
                $spaces = $this->coreSpaceHelper->getDefaultSpacesFromContext();

                if (count($spaces) > 1) {

                    $choosePath = $this->router->url("SpaceChoose");
                    if ($this->request->path == $choosePath) return;

                    $allowRootPage = false;

                    if ($this->request->path != '/')
                    {
                        // Check if simple page like Mention Legales
                        $routeData = $this->router->resolve($this->request)?->routeData
                            ?? $this->coreMenuRouteHelper->getLegacyPublicRouteData($this->request->path);

                        if ($routeData != null && isset($routeData['menu']) && isset($routeData['menu_item'])){
                            $menu = $routeData['menu'];
                            $menuItem = $routeData['menu_item'];
                            if ($menu->space_id == 1
                                && $menuItem->extension == 'plg_core'
                                && (in_array($menuItem->type, ['page', 'logout'])))
                            {
                                // Une ancienne URL autorisée passe ensuite par la redirection de langue.
                                if ($this->corePublicLocaleHelper->hasPrefix()) return;
                                $allowRootPage = true;
                            }
                        }

                    }

                    if (!$allowRootPage) {
                        throw new ExceptionResponse(new RedirectResponse($choosePath));
                    }
                }
            }
        }

        // Seuls les anciens chemins de pages publiques sont redirigés.
        // Les routes d'administration, API et callbacks gardent leurs chemins techniques.
        if (in_array($this->request->method, [RequestMethod::GET, RequestMethod::HEAD], true)
            && !$this->corePublicLocaleHelper->hasPrefix()
            && $this->coreMenuRouteHelper->isLegacyPublicPath($this->request->path)
            && $this->router->resolve($this->request) === null) {
            $path = $this->corePublicLocaleHelper->publicPath($this->request->path);

            if ($path !== null) {
                $query = strstr($this->request->uri, '?') ?: '';
                $response = RedirectResponse::found($path . $query);
                $response->setHeaders([
                    'Vary' => 'Accept-Language',
                    'Cache-Control' => 'private, no-store',
                ]);

                throw new ExceptionResponse($response);
            }
        }

        // L'accueil possède une barre oblique canonique après son préfixe.
        $locale = $this->corePublicLocaleHelper->locale();
        if ($locale !== null && $this->request->path === '/' . $locale
            && in_array($this->request->method, [RequestMethod::GET, RequestMethod::HEAD], true)) {
            $query = strstr($this->request->uri, '?') ?: '';
            throw new ExceptionResponse(RedirectResponse::found('/' . $locale . '/' . $query));
        }

        
    }


}
