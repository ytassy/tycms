<?php

namespace YTassy\Tycms;

use YTassy\Tycms\Logging\Logger;

use BadMethodCallException;
use Configuration;
use ReflectionMethod;
use YTassy\Tycms\CoreExts\Core\Plugin as PluginCore;
use YTassy\Tycms\CoreExts\MainTemplate\Template as TemplateMain;
use YTassy\Tycms\CoreExts\Security\Plugin as PluginSecurity;
use YTassy\Tycms\CoreExts\Tools\Plugin as PluginTools;
use YTassy\Tycms\CoreExts\Matrix\Plugin as PluginMatrix;
use YTassy\Tycms\CoreExts\TyCatalog\Plugin as PluginTyCatalog;
use YTassy\Tycms\CoreExts\Automation\Plugin as PluginAutomation;
use YTassy\Tycms\CoreExts\Test\Plugin as PluginTest;

use YTassy\Tycms\DB\Database;
use YTassy\Tycms\DB\Migrations\Migration;
use YTassy\Tycms\ErrorPages\ErrorPages;
use YTassy\Tycms\Exceptions\TechException;
use YTassy\Tycms\Extensions\BaseExtension;
use YTassy\Tycms\Extensions\BaseTemplate;
use YTassy\Tycms\Lib\ObjectValidator\ObjectValidator;
use YTassy\Tycms\Lib\Forms\FormFactory;
use YTassy\Tycms\Request\Request;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Request\WebRequest;
use YTassy\Tycms\Response\ApiMessageResponse;
use YTassy\Tycms\Response\ApiResponse;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Response\HtmlResponse;
use YTassy\Tycms\Response\RedirectResponse;
use YTassy\Tycms\Response\RespLevel;
use YTassy\Tycms\Response\Response;
use YTassy\Tycms\Routing\Controller\BaseController;
use YTassy\Tycms\Routing\Route;
use YTassy\Tycms\Routing\Router;
use YTassy\Tycms\Session\DatabaseSession;
use YTassy\Tycms\Session\PhpSession;
use YTassy\Tycms\Session\Session;
use YTassy\Tycms\Tools\StringTools;
use YTassy\Tycms\User\PendingMessageQueue;
use YTassy\Tycms\WF\BaseHelper;

class Application
{
    private array $extensions = [];

    /** Identifiants livrés avec la release, distincts des extensions installées. */
    private array $coreExtensionIds = [];
    private ?Request $request = null;
    private ?Router $router = null;
    private Database $database;
    private Configuration $conf;
    private ?Logger $logger = null;
    private Session $session;
    private PendingMessageQueue $pendingMessageQueue;
    private ?Route $route = null;
    private ObjectValidator $objectValidator;

    /**
     * @var array<BaseHelper>
     */
    private array $sharedExtensionsClasses = [];
    
    public function __construct(Configuration $conf)
    {
        $this->extensions = [];
        $this->coreExtensionIds = [];
        $this->request = null;
        $this->router = null;
        $this->conf = $conf;
    }

    public function getRequest(): ?Request
    {
        return $this->request;
    }

    /** @return array<BaseExtension> */
    public function getExtensions(): array
    {
        return $this->extensions;
    }

    /**
     * Une extension Core est livrée avec la release ; elle n'est pas installée
     * depuis un paquet dans extensions/. L'origine vient du chargeur, jamais
     * d'une valeur fournie par le navigateur.
     */
    public function isCoreExtension(string $extensionName): bool
    {
        return isset($this->coreExtensionIds[$extensionName]);
    }

    /** Accessible avant toute connexion SQL, session ou extension. */
    public function getLogger(): Logger
    {
        return $this->logger ??= new Logger($this->conf, APP_ROOT);
    }

    /** Métadonnées déjà disponibles, sans initialiser de service pour les logs. */
    public function getLogContext(): array
    {
        $context = ['route' => $this->route?->name, 'method' => $this->request?->method->value];
        if (isset($this->session)) {
            $context['space_id'] = $this->session->getSpaceId();
            $context['user_id'] = $this->session->getUserId();
        }
        return $context;
    }

    public function getConf(): Configuration
    {
        return $this->conf;
    }

    public function getMediasRoot(): string
    {
        // Existing local configuration.php files may not declare this new option.
        $root = $this->conf->medias_root ?? APP_ROOT . '/public/medias';
        if (!is_string($root) || trim($root) === '') {
            throw new TechException('The configured media root must be a non-empty path.');
        }

        return $root;
    }

    public function getDatabase() : Database{
        return $this->database;
    }

    public function getSession() : Session{
        return $this->session;
    }

    public function getRouter() : Router{
        return $this->router;
    }

    public function getRoute() : ?Route{
        return $this->route;
    }

    public function getPendingMessageQueue() : PendingMessageQueue{
        return $this->pendingMessageQueue;
    }

    public function getObjectValidator() : ObjectValidator{
        if (!isset($this->objectValidator)) {
            $this->objectValidator = new ObjectValidator($this);
        }

        return $this->objectValidator;
    }

    public function initialise(?Request $request = null)
    {
        try {
            $this->initialiseOrThrow($request);
        } catch(\Throwable $ex) {
            $this->getLogger()->capture($ex);
            ErrorPages::printErrorPageAndExit("Erreur dans l'application", "Une erreur innatendue est survenue. Contactez le Webmaster", 500, $this->conf, $ex);
        }
    }

    /**
     * Initialise l'application en propageant les erreurs ; l'Updater peut
     * omettre la session HTTP. Une session de run peut être injectée pour
     * isoler une exécution d'automation du contexte HTTP courant.
     */
    public function initialiseOrThrow(
        ?Request $request = null,
        bool $initializeSession = true,
        ?Session $session = null,
        bool $registerRoutes = true,
    ): void {
        $this->getLogger()->registerShutdown();

        // PendingMessageQueue
        $this->pendingMessageQueue = new PendingMessageQueue($this);

        // Database
        $this->database = new Database($this);

        // Object Validator
        $this->objectValidator = new ObjectValidator($this);

        // Request
        $this->request = $request ?? new WebRequest();

        // Une base neuve conserve l'installation automatique. Sur une base
        // existante, une action explicite déclenche les migrations restantes.
        $migration = null;
        if (!Migration::tableExists($this->database)) {
            $migration = new Migration($this);
            $migration->runCoreMigrations();
        }

        $this->loadConfiguredExtensions($migration);

        // Load Session AFTER. Une session de run injectée évite toute
        // dépendance à la session HTTP de la requête courante.
        if ($session !== null) {
            $this->session = $session;
        } elseif ($this->conf->session_handler == 'database') {
            $this->session = new DatabaseSession($this);
        } else {
            $this->session = new PhpSession($this);
        }

        if ($initializeSession) {
            $this->session->initialize();
        }

        // All extension Loaded
        $this->callExtensionEvent('onAfterLoadExtensions');

        // Form field types must be known before a controller can build a
        // form definition. This explicit event also includes components,
        // modules and templates, which are loaded after plug-ins.
        /** @var FormFactory $formFactory */
        $formFactory = $this->getOrAddSharedExtensionsClass(FormFactory::class);
        $this->callExtensionEvent('onRegisterFormTypes', [$formFactory]);
        // Router. Un run d'automation n'a pas besoin de routes publiques et
        // évite ainsi les dépendances de langue, de menu et de WAF du Space.
        $this->router = new Router($this);

        if ($registerRoutes) {
            $this->router->cleanAndRegisterRoutes();
            $this->callExtensionEvent('onRegisterRoutes', [$this->router]);
        }

        $this->callExtensionEvent('onApplicationInitialised', [$this]);
    }

    /** Exécute les migrations de la release chargée, sans session ni routage. */
    public function runMigrationsOrThrow(): void
    {
        if ($this->conf->RESET_DATABASE) {
            throw new TechException('La remise à zéro de la base est interdite pendant une mise à jour.');
        }

        // Une nouvelle instance évite tout mélange avec une application déjà démarrée.
        if (isset($this->database) || $this->extensions !== []) {
            throw new TechException('Une nouvelle instance Application est requise pour les migrations.');
        }

        $this->pendingMessageQueue = new PendingMessageQueue($this);
        $this->request = new Request();
        $this->database = new Database($this);
        $this->objectValidator = new ObjectValidator($this);

        $migration = new Migration($this);
        $migration->runCoreMigrations();

        $this->loadConfiguredExtensions($migration);
    }

    /** Charge les extensions dans le même ordre pour le site et les migrations. */
    private function loadConfiguredExtensions(?Migration $migration): void
    {
        $this->loadCoreExtensions($migration);

        // Les extensions locales restent partagées entre les releases.
        // *************** Load Plug-ins
        $this->loadExtensions(APP_ROOT . '/extensions/plugins', 'Plugin.php', 'YTassy\\Tycms\\Plugins', 'plg_', $migration);
        $this->callExtensionEvent('onApplicationPrepare', [$this]);

        // *************** Components / Modules ******************
        $this->loadExtensions(APP_ROOT . '/extensions/modules', 'Module.php', 'YTassy\\Tycms\\Modules', 'mod_', $migration);
        $this->loadExtensions(APP_ROOT . '/extensions/templates', 'Template.php', 'YTassy\\Tycms\\Templates', 'tpl_', $migration);
    }

    /** Charger le socle obligatoire et les extensions système facultatives. */
    private function loadCoreExtensions(?Migration $migration): void
    {
        // Revalider aussi les réglages éventuellement changés depuis le constructeur.
        $this->conf->normalize();

        $this->loadExtention('plg_core', PluginCore::class, $migration, true);
        $this->loadExtention('plg_security', PluginSecurity::class, $migration, true);

        // Une extension désactivée ne reçoit ni migration, ni événement, ni route.
        if ($this->conf->core_extensions_enabled['plg_tools']) {
            $this->loadExtention('plg_tools', PluginTools::class, $migration, true);
        }

        if ($this->conf->core_extensions_enabled['plg_matrix']) {
            $this->loadExtention('plg_matrix', PluginMatrix::class, $migration, true);
        }

        // Un choix explicite prime ; sinon TyCatalog est actif seulement en DEV.
        if ($this->conf->core_extensions_enabled['plg_ty_catalog'] ?? $this->conf->dev) {
            $this->loadExtention('plg_ty_catalog', PluginTyCatalog::class, $migration, true);
        }

        // Un choix explicite prime ; sinon l'automatisation est active par défaut.
        if ($this->conf->core_extensions_enabled['plg_automation'] ?? true) {
            $this->loadExtention('plg_automation', PluginAutomation::class, $migration, true);
        }

        $this->loadExtention('tpl_main', TemplateMain::class, $migration, true);

        if ($this->conf->dev) {
            $this->loadExtention('plg_test', PluginTest::class, $migration, true);
        }
    }

    private function loadExtensions(string $dir, string $fileName, string $nameSpaceBase, string $prefixName, ?Migration $migration): array
    {
        $res = [];
        $extensions = glob($dir . '/*', GLOB_ONLYDIR);

        foreach ($extensions as $extensionDir) {
            $extName = basename($extensionDir);
            $extensionFile = $extensionDir . '/' . $fileName;

            $extentionFullName = $prefixName . StringTools::toSnakeCase($extName);

            //if (file_exists($extensionFile) == false) throw new TechException('Unable to find: ' . $extensionFile);
                  
            require_once $extensionFile;

            $className = $nameSpaceBase . '\\' . $extName . '\\' . str_replace('.php', '', $fileName);
            if (class_exists($className) == false)  throw new TechException('Unable to find Class: ' . $className);

            $res[] = $this->loadExtention($extentionFullName, $className, $migration);
        }

        return $res;
    }


    private function loadExtention(string $extentionFullName, string $className, ?Migration $migration, bool $isCore = false){
        // Refuser la collision avant tout constructeur ou migration de l'extension entrante.
        if (array_key_exists($extentionFullName, $this->extensions)) {
            throw new TechException(sprintf(
                'Duplicate extension identifier "%s": "%s" is already loaded; cannot load "%s".',
                $extentionFullName, $this->extensions[$extentionFullName]::class, $className,
            ));
        }

        // Enregistrer uniquement une nouvelle extension, puis appliquer ses migrations.
        $obj = new $className($this);
        $this->extensions[$extentionFullName] = $obj;

        // L'origine est fixée par ce chargeur, pas par la classe ni par l'interface.
        if ($isCore) {
            $this->coreExtensionIds[$extentionFullName] = true;
        }

        $migration?->runMigrations($extentionFullName, $obj);

        return $obj;
    }

    public function callExtensionEvent(string $eventName, array $params = []): array
    {
        $res = [];

        foreach ($this->extensions as $extName => $extension) {
            if (method_exists($extension, $eventName)) {
                $res[$extName] = call_user_func_array([$extension, $eventName], $params);
            }
        }

        return $res;
    }

    public function executeAndGetResponse(): Response
    {
        try {
            $this->callExtensionEvent('onBeforeResolveRoute', [$this]);

            $route = $this->router->resolve($this->request);
            $this->route = $route;
            
            if ($route == null) {
                ErrorPages::printErrorPageAndExit("Page introuvable",
                    "Cette page est introuvable sur le site.", 404, $this->conf);
            }

            $this->callExtensionEvent('onAfterResolveRoute', [ $route ]);

            // Build runner
            $runner = new ApplicationRouteRunner($this);

            // Run
            return $runner->run($route);

        } catch (ExceptionResponse $ex) {
            $this->getLogger()->capture($ex);
            return $ex->getResponse();
        } catch(\Throwable $ex) {
            $this->getLogger()->capture($ex);
            ErrorPages::printErrorPageAndExit("Erreur dans l'application", "Une erreur innatendue est survenue. Contactez le Webmaster", 500, $this->conf, $ex);
        }
    }

    public function sendResponse(Response $response)
    {
        // Build runner
        $runner = new ApplicationRouteRunner($this);

        // Send Response
        try {
            $runner->sendResponse($response);
        } catch (\Throwable $error) {
            $this->getLogger()->capture($error);
            throw $error;
        }
    }
    
    public function getOrAddSharedExtensionsClass(string $classeName) : BaseApplicationItem {
        
        $obj = $this->sharedExtensionsClasses[$classeName] ?? null;
        if ($obj == null) {
            $obj = new $classeName($this);
            $this->sharedExtensionsClasses[$classeName] = $obj;
        }
        
        if ($obj instanceof BaseApplicationItem == false) {
            throw new TechException("$classeName is not a BaseApplicationItem.");
        }

        return $obj;
    }

}
