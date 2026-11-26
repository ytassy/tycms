<?php

namespace YTassy\Tycms\Extensions;

use YTassy\Tycms\Application;
use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\DB\Migrations\IMigration;
use YTassy\Tycms\Lib\Forms\Form;
use YTassy\Tycms\Lib\Forms\FormFactory;
use YTassy\Tycms\Response\Response;
use YTassy\Tycms\Routing\Controller\BaseController;
use YTassy\Tycms\Routing\Route;
use YTassy\Tycms\Routing\Router;

abstract class BaseExtension extends BaseApplicationItem implements IMigration
{
    abstract public function getName(): string;
    /** Les extensions installées peuvent utiliser version.json comme source de vérité. */
    public function getVersion(): string
    {
        $file = (new \ReflectionClass($this))->getFileName();
        return ExtensionMetadata::read(dirname($file) . '/version.json')['version'];
    }

    public function getIconClass(): string
    {
        return "fa-solid fa-plug";
    }

    public function getDescription(): string
    {
        return "";
    }

    /**
     * Permissions dont une seule suffit pour éditer la configuration.
     * Une extension peut remplacer cette liste pour son propre formulaire.
     *
     * @return array<string>
     */
    public function getConfigEditPermissions(): array
    {
        return ['plg_core__EXTENSION_EDIT'];
    }

    /** @return list<string> Sections masquées dans l'éditeur pour ce Space. */
    public function getConfigHiddenSections(int $spaceId): array
    {
        return [];
    }

    /**
     * Identifiant logique du composant Vue de configuration, ou null pour
     * utiliser l'interface générique du Core.
     *
     * Le composant est enregistré par le frontend de l'extension. Le serveur
     * ne transmet jamais de chemin d'import, de classe ni de template : seul
     * cet identifiant est résolu dans le registre Vue de confiance.
     */
    public function getConfigViewComponentName(): ?string
    {
        return null;
    }

    /** Vérifier les champs soumis avant la validation du formulaire générique. */
    public function assertConfigSubmissionAllowed(int $spaceId, object $params): void {}

    /** @param array<string,mixed> $params @return array<string,mixed> */
    public function prepareConfigForStorage(int $spaceId, array $params): array
    {
        return $params;
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    public function prepareConfigForEditor(int $spaceId, array $params): array
    {
        return $params;
    }

    /** Entrée ESM du frontend installé ; null pour une extension uniquement PHP. */
    public function getFrontendEntry(): ?string
    {
        return null;
    }

    /**
     * Nettoyage spécifique à l’extension avant sa désinstallation.
     *
     * Appelée par le Core avant le retrait du code et des assets. L’extension
     * doit y supprimer ses tables, données, configuration et ACL. Le Core
     * retire ensuite ses versions de migration et sa provenance. Les
     * migrations SQL déjà exécutées ne sont pas annulables ; la méthode doit
     * être idempotente pour permettre une nouvelle tentative après échec.
     */
    public function uninstallExtension(): void
    {
    }

    public function getControllers(): array
    {
        return [];
    }

    public function onAfterLoadExtensions() {}

    public function onBeforeResolveRoute() {}

    public function onAfterResolveRoute(Route $route) {}

    /**
     * Registers form field types once every extension has been loaded.
     * A type id must be qualified by its owning extension, for example
     * "lms.course-selector".
     */
    public function onRegisterFormTypes(FormFactory $formFactory): void {}

    /**
     * Declares the extension's capability catalogues once it is loaded and
     * before any route is registered.
     *
     * This step must not depend on the request, the current Space or the
     * routing: code paths without routes (scenario or MCP execution, tests,
     * migrations) still need those catalogues. The framework names no
     * business concept here; each extension decides what it prepares.
     */
    public function onPrepareExtensions(): void {}

    public function onRegisterRoutes(Router $router) {}

    
    public function onBeforeExecuteController(Route $route, BaseController $controller, string $controllerMethod, array $params) {}

    public function onBeforeSendResponse(Response $response) { }


    /** @return array<string> */
	public function getMigrations(): array
	{
		return array_keys($this->_getMigrations());
	}

    /** @return array<string,array<BaseMigrationVersion>> */
    abstract protected function _getMigrations(): array;
    // Return [
    //      '1.0.0' => [ Mig_1_0_0_p1::class, Mig_1_0_0_p2::class],
    //    //'1.0.2' => [ Mig_1_0_2::class ] 



	public function runMigration(string $version): array
	{
        $res = [];

        $classes = $this->_getMigrations()[$version];

        foreach($classes as $class)
        {
            $mig = new $class($this);
            $res[] = $mig->run();
        }
        
        return $res;
	}
}
