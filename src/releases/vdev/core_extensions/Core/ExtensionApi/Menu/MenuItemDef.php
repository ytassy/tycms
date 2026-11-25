<?php

namespace YTassy\Tycms\CoreExts\Core\ExtensionApi\Menu;

use YTassy\Tycms\Routing\Controller\BaseController;

class MenuItemDef
{
    public readonly ?string $controllerUrlDefined;
    public readonly ?string $controllerRouteName;

    public function __construct(
        public readonly string $extension,
        public readonly string $type,
        public readonly ?BaseController $controller,

        /**
         * @var ?array<string,string>
         */
        public readonly ?array $methodsByHttpMethods,
        public readonly string $ui_name,
        public readonly string $ui_description,
        public readonly string $ui_form, // Todo
        /** @var array<string,mixed>|null */
        public readonly ?array $paramsFormDefinition = null,

        /**
         * Exposé comme action MCP dédiée.
         *
         * Un type `true` fournit une action d'écriture nommée par `aiMcpAction`,
         * pour que l'IA n'ait pas à assembler un couple extension/type/params à
         * l'aveugle. Un type `false` n'est pas proposé au MCP, même s'il reste
         * disponible dans l'éditeur.
         */
        public readonly bool $ai = false,

        /**
         * Nom de l'action MCP de création, par exemple `createPageMenuItem`.
         *
         * Obligatoire dès que `ai` est vrai : le nom est ainsi visible dans le
         * registre, au lieu d'être deviné par le client. Le schéma des `params`
         * n'est pas recopié ici : il est dérivé de `paramsFormDefinition`, qui
         * reste la seule source de vérité de la validation.
         */
        public readonly ?string $aiMcpAction = null,

        /**
         * Complément de documentation pour l'IA.
         *
         * Ne remplace pas le schéma : il porte seulement les règles qu'un
         * schéma ne peut pas exprimer (même Space que la ressource, titre
         * repris, prérequis de navigation).
         */
        public readonly ?string $aiMcpDoc = null,
    ) {
        $url = null;
        $controllerRouteName = null;

        $method = $methodsByHttpMethods['GET'] ?? $methodsByHttpMethods['get'] ?? null;
        if ($method !== null && $controller !== null) {
            $reflection = new \ReflectionClass($controller);
            $controllerAttribute = $reflection->getAttributes(\YTassy\Tycms\Routing\Annotation\Doc\Controller::class)[0] ?? null;
            $methodAttribute = $reflection->getMethod($method)
                ->getAttributes(\YTassy\Tycms\Routing\Annotation\Doc\ControllerMethod::class)[0] ?? null;

            if ($controllerAttribute !== null && $methodAttribute !== null) {
                $ctrlAttrInst = $controllerAttribute->newInstance();
                $controllerPath = trim($ctrlAttrInst->path, '/');

                $methoAttrInst = $methodAttribute->newInstance();
                $methodPath = trim($methoAttrInst->path, '/');

                $url = '/' . implode('/', array_filter([$controllerPath, $methodPath]));
                $controllerRouteName = $ctrlAttrInst->name . $methoAttrInst->name;
            }
        }

        $this->controllerUrlDefined = $url;
        $this->controllerRouteName = $controllerRouteName;

        // Un type exposé à l'IA sans nom d'action serait inutilisable : le
        // client devrait deviner l'identifiant. On refuse à la déclaration
        // plutôt que de produire un catalogue ambigu.
        if ($this->ai && ($this->aiMcpAction === null || trim($this->aiMcpAction) === '')) {
            throw new \InvalidArgumentException(
                'Le type de menu ' . $extension . '__' . $type
                . ' est exposé à l’IA (ai: true) sans aiMcpAction.',
            );
        }
    }
}
