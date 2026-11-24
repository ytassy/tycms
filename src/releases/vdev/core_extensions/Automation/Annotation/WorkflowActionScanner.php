<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Annotation;

use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationActionDefinition;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationActionRegistry;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationParameterDefinition;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\WorkflowMethodAction;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\WorkflowMethodRef;
use YTassy\Tycms\Exceptions\TechException;

/**
 * Convertit les annotations d'une classe de WorkFlow en définitions d'actions.
 *
 * Chaque extension liste explicitement les classes à analyser ; la découverte
 * de classes par balayage de namespace n'est pas nécessaire et resterait
 * fragile sur hébergement mutualisé.
 */
final class WorkflowActionScanner
{
    /**
     * @param list<class-string> $workflowClasses
     * @return list<AutomationActionDefinition>
     */
    public static function definitionsFor(array $workflowClasses): array
    {
        $definitions = [];

        foreach ($workflowClasses as $workflowClass) {
            foreach (self::definitionsForClass($workflowClass) as $definition) {
                $definitions[] = $definition;
            }
        }

        return $definitions;
    }

    /** @param list<class-string> $workflowClasses */
    public static function register(AutomationActionRegistry $registry, array $workflowClasses): void
    {
        foreach (self::definitionsFor($workflowClasses) as $definition) {
            $registry->register($definition);
        }
    }

    /** @param class-string $workflowClass @return list<AutomationActionDefinition> */
    private static function definitionsForClass(string $workflowClass): array
    {
        $class = new ReflectionClass($workflowClass);

        $expose = $class->getAttributes(ExposeAutomationActions::class)[0] ?? null;

        if ($expose === null) {
            throw new TechException('Classe de WorkFlow sans ExposeAutomationActions : ' . $workflowClass);
        }

        $namespace = $expose->newInstance()->namespace;

        $definitions = [];

        foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            // Ne traiter que les méthodes déclarées dans la classe, munies de
            // l'attribut, et non statiques.
            if ($method->getDeclaringClass()->getName() !== $workflowClass
                || $method->isStatic()) {
                continue;
            }

            $action = $method->getAttributes(AutomationAction::class)[0] ?? null;

            if ($action === null) {
                continue;
            }

            $definitions[] = self::definitionFor($namespace, $method, $action->newInstance());
        }

        return $definitions;
    }

    private static function definitionFor(
        string $namespace,
        ReflectionMethod $method,
        AutomationAction $action,
    ): AutomationActionDefinition {
        $id = $action->id ?? ($namespace . '::' . $method->getName());

        return new AutomationActionDefinition(
            id: $id,
            label: $action->label !== '' ? $action->label : self::humanize($method->getName()),
            handlerClass: WorkflowMethodAction::class,
            description: $action->description,
            parameters: self::parametersFor($method),
            returnType: $action->returnType,
            effect: $action->effect,
            requiredPermissions: self::permissionsFor($method),
            targetSpace: $action->targetSpace,
            timeoutSeconds: $action->timeoutSeconds,
            maxOutputBytes: $action->maxOutputBytes,
            workflowMethod: new WorkflowMethodRef(
                $method->getDeclaringClass()->getName(),
                $method->getName(),
                self::transformFor($method),
            ),
        );
    }

    /** @return list<AutomationParameterDefinition> */
    private static function parametersFor(ReflectionMethod $method): array
    {
        $annotated = [];

        foreach ($method->getAttributes(ActionParam::class) as $attribute) {
            $param = $attribute->newInstance();
            $annotated[$param->name] = $param;
        }

        $parameters = [];

        foreach ($method->getParameters() as $parameter) {
            $name = $parameter->getName();
            $annotation = $annotated[$name] ?? null;
            unset($annotated[$name]);

            $hasPhpDefault = $parameter->isDefaultValueAvailable();

            $parameters[] = new AutomationParameterDefinition(
                name: $name,
                type: $annotation?->type ?? self::languageType($parameter->getType()),
                required: $annotation?->required ?? !$hasPhpDefault,
                default: $annotation?->default ?? ($hasPhpDefault ? $parameter->getDefaultValue() : null),
                label: $annotation?->label ?? '',
                description: $annotation?->description ?? '',
            );
        }

        // Un ActionParam restant ne correspond à aucun argument de la méthode.
        if ($annotated !== []) {
            throw new TechException(
                'ActionParam inconnu sur ' . $method->getDeclaringClass()->getName()
                . '::' . $method->getName() . ' : ' . implode(', ', array_keys($annotated)),
            );
        }

        return $parameters;
    }

    /** @return list<string> */
    private static function permissionsFor(ReflectionMethod $method): array
    {
        $permissions = [];

        foreach ($method->getAttributes(ActionPermission::class) as $attribute) {
            $permissions[] = $attribute->newInstance()->permission;
        }

        return $permissions;
    }

    /** @return array{class-string, string}|null */
    private static function transformFor(ReflectionMethod $method): ?array
    {
        $attribute = $method->getAttributes(ActionTransform::class)[0] ?? null;

        if ($attribute === null) {
            return null;
        }

        $transform = $attribute->newInstance();

        return [$transform->class, $transform->method];
    }

    private static function languageType(?ReflectionType $type): string
    {
        if ($type instanceof ReflectionNamedType) {
            return match ($type->getName()) {
                'int' => 'int',
                'string' => 'string',
                'bool' => 'bool',
                'float' => 'float',
                'array' => 'array',
                default => 'mixed',
            };
        }

        return 'mixed';
    }

    private static function humanize(string $name): string
    {
        return ucfirst((string) preg_replace('/(?<!^)[A-Z]/', ' $0', $name));
    }
}