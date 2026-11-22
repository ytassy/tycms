<?php

namespace YTassy\Tycms\Routing;

use YTassy\Tycms\Routing\Controller\BaseController;

/**
 * Résultat de la résolution d'une requête par le routeur.
 */
final class Route
{
    /**
     * @param array<string, string|null> $params Paramètres extraits du chemin.
     * @param array<string, array<object>> $attributes Attributs indexés par leur nom court.
     * @param array<string, mixed> $inputs Entrées de requête validées.
     * @param array<int, array{param: string, message: string}> $errors Erreurs de validation.
     */
    public function __construct(
        public readonly string $name,
        public readonly BaseController $controller,
        public readonly string $controllerMethod,
        public readonly bool $isApi,
        public readonly array $params,
        public readonly array $attributes,
        public readonly array $inputs,
        public readonly bool $noTransaction,
        public readonly bool $root,
        public readonly bool $testOnly,
        public readonly bool $userLogged,
        public readonly array $errors,
        public readonly ?array $routeData = null,
        public readonly bool $withoutCsrf = false,
    ) {
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }
}
