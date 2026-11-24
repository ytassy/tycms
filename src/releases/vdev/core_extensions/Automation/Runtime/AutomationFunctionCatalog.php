<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Runtime;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationActionRegistry;
use YTassy\Tycms\CoreExts\Automation\Language\Registry\BuiltinFunctionCatalog;
use YTassy\Tycms\CoreExts\Automation\Language\Registry\FunctionCatalog;
use YTassy\Tycms\CoreExts\Automation\Language\Registry\FunctionSignature;

/** Catalogue runtime : fonctions intégrées + actions déclarées par les extensions. */
final class AutomationFunctionCatalog implements FunctionCatalog
{
    public function __construct(
        private readonly BuiltinFunctionCatalog $builtins,
        private readonly ?AutomationActionRegistry $actions = null,
        /** @var list<string> $excludedEffects Effets exclus : une telle action devient inconnue. */
        private readonly array $excludedEffects = [],
    ) {
    }

    public function find(string $identifier): ?FunctionSignature
    {
        $signature = $this->builtins->find($identifier);

        if ($signature !== null) {
            return $signature;
        }

        $definition = $this->actions?->find($identifier);

        if ($definition === null || in_array($definition->effect, $this->excludedEffects, true)) {
            return null;
        }

        $required = 0;

        foreach ($definition->parameters as $parameter) {
            if ($parameter->required) {
                $required++;
            }
        }

        return new FunctionSignature(
            $definition->id,
            $required,
            count($definition->parameters),
            $definition->effect,
        );
    }
}
