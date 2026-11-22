<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Runtime\Action;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationActionRegistry;
use YTassy\Tycms\CoreExts\Automation\Language\DiagnosticCode;
use YTassy\Tycms\CoreExts\Automation\Language\Registry\FunctionEffect;
use YTassy\Tycms\CoreExts\Automation\Language\SourceSpan;
use YTassy\Tycms\CoreExts\Automation\Runtime\RuntimeServices;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;

/**
 * Simulation : exécute les lectures et refuse toute écriture ou effet externe.
 *
 * Les permissions des lectures restent vérifiées par le répartiteur réel. Une
 * action refusée produit un diagnostic explicite au lieu d'un effet partiel.
 */
final class DryRunActionDispatcher implements ActionDispatcher
{
    public function __construct(
        private readonly ActionDispatcher $inner,
        private readonly AutomationActionRegistry $registry,
    ) {
    }

    public function dispatch(
        string $identifier,
        array $arguments,
        SourceSpan $span,
        RuntimeServices $services,
    ): mixed {
        $definition = $this->registry->find($identifier);

        if ($definition === null) {
            throw ValueTools::error(
                DiagnosticCode::ACTION_UNAVAILABLE,
                'Action indisponible : « ' . $identifier . ' ».',
                $span,
            );
        }

        if (!in_array($definition->effect, [FunctionEffect::PURE, FunctionEffect::READ], true)) {
            throw ValueTools::error(
                DiagnosticCode::ACTION_UNAVAILABLE,
                'Action non exécutée en simulation : « ' . $definition->label . ' ».',
                $span,
            );
        }

        return $this->inner->dispatch($identifier, $arguments, $span, $services);
    }
}
