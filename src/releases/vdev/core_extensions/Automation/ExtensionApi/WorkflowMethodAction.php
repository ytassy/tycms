<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\ExtensionApi;

use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use YTassy\Tycms\Application;
use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\CoreExts\Automation\Language\DiagnosticCode;
use YTassy\Tycms\CoreExts\Automation\Language\Limits;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;

/**
 * Handler générique qui exécute une méthode de WorkFlow marquée comme action.
 *
 * Il relie les valeurs sûres du langage aux arguments typés de la méthode,
 * applique la transformation de sortie éventuelle, puis ramène le résultat à
 * la frontière de valeurs du langage.
 */
final class WorkflowMethodAction extends BaseAutomationAction
{
    public function __construct(
        BaseApplicationItem|Application $app,
        private readonly WorkflowMethodRef $ref,
    ) {
        parent::__construct($app);
    }

    /** @param array<string, mixed> $params */
    public function execute(ActionContext $context, array $params): mixed
    {
        $method = new ReflectionMethod($this->ref->workflowClass, $this->ref->methodName);

        // Reconstruit la liste ordonnée des arguments à partir de la signature
        // PHP, en validant chaque valeur fournie par le langage.
        $arguments = [];

        foreach ($method->getParameters() as $parameter) {
            $name = $parameter->getName();
            $arguments[] = array_key_exists($name, $params)
                ? $this->coerce($params[$name], $parameter, $context)
                : $this->defaultOrFail($parameter, $name, $context);
        }

        try {
            $workflow = $this->getSharedExtensionsClass($this->ref->workflowClass);
            $result = $method->invokeArgs($workflow, $arguments);
        } catch (\Throwable $exception) {
            throw $this->actionException($exception, $context);
        }

        // Normalisation optionnelle (projection vers des valeurs sûres),
        // puis contrôle de la frontière de valeurs du langage.
        if ($this->ref->transform !== null) {
            [$transformClass, $transformMethod] = $this->ref->transform;
            $result = $transformClass::$transformMethod($result);
        }

        return ValueTools::assertSafe($result, new Limits(), $context->span);
    }

    /** Valide une valeur selon le type déclaré de l'argument PHP. */
    private function coerce(mixed $value, ReflectionParameter $parameter, ActionContext $context): mixed
    {
        $type = $parameter->getType();

        if (!$type instanceof ReflectionNamedType) {
            return $value;
        }

        if ($value === null && $type->allowsNull()) {
            return null;
        }

        return match ($type->getName()) {
            'int' => ValueTools::assertInt($value, $context->span),
            'string' => ValueTools::assertString($value, $context->span),
            'bool' => ValueTools::assertBool($value, $context->span),
            'float' => ValueTools::assertFloat($value, $context->span),
            'array' => ValueTools::assertArray($value, $context->span),
            // Les types restants (object, mixed, etc.) repartent tels quels ;
            // la frontière de valeurs les refusera en sortie si nécessaire.
            default => $value,
        };
    }

    private function defaultOrFail(ReflectionParameter $parameter, string $name, ActionContext $context): mixed
    {
        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        throw ValueTools::error(
            DiagnosticCode::INVALID_ARGUMENT,
            'Argument obligatoire manquant : ' . $name . '.',
            $context->span,
        );
    }
}