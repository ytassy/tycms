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
    }
}
