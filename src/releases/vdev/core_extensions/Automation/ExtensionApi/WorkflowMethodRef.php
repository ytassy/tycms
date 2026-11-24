<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\ExtensionApi;

/**
 * Cible d'exécution d'une action portée par une méthode de WorkFlow.
 *
 * `transform` est un couple optionnel `[class-string, string]` désignant une
 * méthode statique qui normalise le résultat brut avant la frontière de
 * valeurs.
 */
final readonly class WorkflowMethodRef
{
    /**
     * @param class-string $workflowClass
     * @param array{class-string, string}|null $transform
     */
    public function __construct(
        public string $workflowClass,
        public string $methodName,
        public ?array $transform = null,
    ) {
    }
}