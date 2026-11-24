<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Annotation;

use Attribute;
use YTassy\Tycms\CoreExts\Automation\Language\Registry\FunctionEffect;

/**
 * Déclare une méthode de WorkFlow comme action métier du langage de scénarios.
 *
 * L'identifiant `id`, lorsqu'il est omis, est déduit du namespace de la classe
 * (`ExposeAutomationActions`) et du nom de la méthode : `namespace::méthode`.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class AutomationAction
{
    public function __construct(
        public ?string $id = null,
        public string $label = '',
        public string $description = '',
        public string $returnType = 'mixed',
        public string $effect = FunctionEffect::WRITE,
        public string $targetSpace = 'scenario',
        public int $timeoutSeconds = 10,
        public int $maxOutputBytes = 65536,
    ) {
    }
}