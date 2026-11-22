<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Runtime\Action;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationActionRegistry;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Expr\Call;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Program;
use YTassy\Tycms\CoreExts\Automation\Language\SourcePosition;
use YTassy\Tycms\CoreExts\Automation\Language\SourceSpan;

/**
 * Recense les actions réellement appelées par un programme compilé.
 *
 * Le parcours est générique sur les propriétés publiques de l'AST : il suit
 * les appels imbriqués dans les arguments, les conditions et les boucles sans
 * dépendre de chaque type de nœud. Sert au contrôle anti-escalade avant
 * publication.
 */
final class ActionReferenceCollector
{
    public function __construct(private readonly AutomationActionRegistry $registry)
    {
    }

    /** @return list<string> */
    public function collect(Program $program): array
    {
        $references = [];
        $this->walk($program, $references);

        return array_keys($references);
    }

    /** @param array<string, true> $references */
    private function walk(mixed $node, array &$references): void
    {
        if ($node instanceof SourceSpan || $node instanceof SourcePosition) {
            return;
        }

        if ($node instanceof Call && $node->namespace !== null) {
            $identifier = $node->identifier();

            if ($this->registry->has($identifier)) {
                $references[$identifier] = true;
            }
        }

        if (is_array($node)) {
            foreach ($node as $value) {
                $this->walk($value, $references);
            }

            return;
        }

        if (!is_object($node)) {
            return;
        }

        foreach (get_object_vars($node) as $value) {
            $this->walk($value, $references);
        }
    }
}
