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
            'call' => $this->callExample($definition),
            'returnType' => $definition->returnType,
            'effect' => $definition->effect,
            'requiredPermissions' => $definition->requiredPermissions,
            'targetSpace' => $definition->targetSpace,
        ];
    }

    /**
     * Exemple d'appel positionnel, composé depuis les paramètres réels.
     *
     * Le langage n'a pas d'arguments nommés : un agent doit voir l'ordre exact
     * des arguments. L'exemple est généré depuis le registre pour suivre
     * automatiquement toute évolution d'arité ou de nom de paramètre.
     */
    private function callExample(AutomationActionDefinition $definition): string
    {
        $arguments = [];

        foreach ($definition->parameters as $parameter) {
            // Un paramètre facultatif est matérialisé par sa valeur par défaut
            // pour montrer le type attendu à cette position.
            $arguments[] = $parameter->required
                ? $this->placeholder($parameter->type, $parameter->name)
                : $this->literal($parameter->default);
        }

        return $definition->id . '(' . implode(', ', $arguments) . ');';
    }

    /**
     * Valeur d'exemple pour un paramètre obligatoire.
     *
     * Le texte produit doit rester du code valide : un agent le copie tel quel.
     * On prend donc de vrais littéraux, commentés par le nom du paramètre.
     */
    private function placeholder(string $type, string $name): string
    {
        return match ($type) {
            'int' => '0 /* ' . $name . ' */',
            'float' => '0.0 /* ' . $name . ' */',
            'bool' => 'true /* ' . $name . ' */',
            'array' => '[] /* ' . $name . ' */',
            default => '"' . $name . '"',
        };
    }

    /** Valeur littérale du langage pour une valeur par défaut PHP. */
    private function literal(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) $value,
            is_array($value) => '[]',
            default => json_encode((string) $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '""',
        };
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
            'callStyle' => 'Arguments strictement positionnels : core::listUsers(1, 20, "", "user"). '
                . 'La forme "nom" => valeur n’est valable que dans une map, jamais en argument.',
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
            // L'agent n'a que ce document : il porte l'architecture et la
            // syntaxe réelle, en plus du catalogue des fonctions et actions.
            'architecture' => McpLanguageGuide::architecture(),
            'language' => [
                'entryPoint' => 'Le programme contient exactement une fonction main() sans paramètre.',
                'values' => ['null', 'bool', 'int', 'float', 'string', 'array', 'date'],
                'operators' => ['+ - * / %', '.', '== != < <= > >=', 'and or not', '??', '?:', 'in'],
                'comment' => 'Aucun objet, aucune méthode, aucun SQL, aucun shell, aucune inclusion.',
            ],
            'syntax' => McpLanguageGuide::syntax(),
            // Le modèle du site : ce sur quoi le programme agit. Sans lui,
            // l'agent sait appeler une action mais ignore les règles métier
            // qui la rendent valide (même Space, statuts, hiérarchie).
            'domain' => [
                'model' => McpDomainGuide::model(),
                'workflows' => McpDomainGuide::workflows(),
            ],
            'functions' => BuiltinFunctionReference::all(),
            'actions' => $this->actions(),
            'context' => $this->context(),
        ];
    }
}