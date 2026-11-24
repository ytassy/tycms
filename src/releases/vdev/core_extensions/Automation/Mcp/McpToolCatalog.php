<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Mcp;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationActionRegistry;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationActionDefinition;
use YTassy\Tycms\CoreExts\Automation\Language\Limits;
use YTassy\Tycms\CoreExts\Automation\Language\Registry\BuiltinFunctionReference;
use YTassy\Tycms\CoreExts\Automation\Language\Registry\ContextVariableCatalog;
use YTassy\Tycms\CoreExts\Automation\Language\Registry\FunctionEffect;

/**
 * Catalogue exposé au client MCP.
 *
 * Il ne montre que les actions réellement exécutables par le serveur MCP :
 * les effets `external` sont masqués, comme ils le sont à la compilation et au
 * dispatch. Le client ne peut donc pas composer un appel qui échouerait.
 */
final class McpToolCatalog
{
    /** @var list<string> */
    private const EXCLUDED_EFFECTS = [FunctionEffect::EXTERNAL];

    public function __construct(private readonly ?AutomationActionRegistry $registry)
    {
    }

    /** @return list<array<string, mixed>> */
    public function actions(): array
    {
        $actions = [];

        foreach ($this->registry?->all() ?? [] as $definition) {
            if (in_array($definition->effect, self::EXCLUDED_EFFECTS, true)) {
                continue;
            }

            $actions[] = $this->actionPayload($definition);
        }

        return $actions;
    }

    /** @return array<string, mixed> */
    private function actionPayload(AutomationActionDefinition $definition): array
    {
        $parameters = [];

        foreach ($definition->parameters as $parameter) {
            $parameters[] = [
                'name' => $parameter->name,
                'type' => $parameter->type,
                'required' => $parameter->required,
                'default' => $parameter->default,
                'label' => $parameter->label,
                'description' => $parameter->description,
            ];
        }

        return [
            'id' => $definition->id,
            'label' => $definition->label,
            'description' => $definition->description,
            'parameters' => $parameters,
            'returnType' => $definition->returnType,
            'effect' => $definition->effect,
            'requiredPermissions' => $definition->requiredPermissions,
            'targetSpace' => $definition->targetSpace,
        ];
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        $variables = [];

        // Les trois variables stables du moteur, sans `$input` qui dépend d'un
        // déclencheur de scénario.
        foreach (ContextVariableCatalog::base() as $variable) {
            $variables[] = $variable;
        }

        return [
            'variables' => $variables,
            'input' => [
                'name' => '$input',
                'description' => 'Entrée libre du client MCP, toujours une map non fiable.',
                'readonly' => true,
            ],
            'sourceBytesLimit' => (new Limits())->maxSourceBytes,
            'statements' => [
                'if / elseif / else', 'foreach', 'for', 'while', 'return', 'break',
                'continue', 'stop', 'fail', 'affectation',
            ],
            'entryPoint' => 'function main()',
        ];
    }

    /** @return array<string, mixed> */
    public function reference(): array
    {
        return [
            'language' => [
                'entryPoint' => 'Le programme contient exactement une fonction main() sans paramètre.',
                'values' => ['null', 'bool', 'int', 'float', 'string', 'array', 'date'],
                'operators' => ['+ - * / %', '.', '== != < <= > >=', 'and or not', '??', '?:', 'in'],
                'comment' => 'Aucun objet, aucune méthode, aucun SQL, aucun shell, aucune inclusion.',
            ],
            'functions' => BuiltinFunctionReference::all(),
            'actions' => $this->actions(),
            'context' => $this->context(),
        ];
    }
}