<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\ExtensionApi;

use YTassy\Tycms\CoreExts\Automation\Language\Registry\FunctionEffect;

final readonly class AutomationActionDefinition
{
    /**
     * @param list<AutomationParameterDefinition> $parameters
     * @param list<string> $requiredPermissions
     * @param WorkflowMethodRef|null $workflowMethod Cible de méthode de WF pour
     *        une action déclarée par annotation ; null pour un handler dédié.
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $handlerClass,
        public string $description = '',
        public array $parameters = [],
        public string $returnType = 'mixed',
        public string $effect = FunctionEffect::WRITE,
        public array $requiredPermissions = [],
        public string $targetSpace = 'scenario',
        public int $timeoutSeconds = 10,
        public int $maxOutputBytes = 65536,
        public ?WorkflowMethodRef $workflowMethod = null,
    ) {
    }
}
