<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Runtime\Action;

use YTassy\Tycms\CoreExts\Automation\Language\Diagnostic;
use YTassy\Tycms\CoreExts\Automation\Language\DiagnosticCode;
use YTassy\Tycms\CoreExts\Automation\Language\SourceSpan;
use YTassy\Tycms\CoreExts\Automation\Runtime\ExecutionException;
use YTassy\Tycms\CoreExts\Automation\Runtime\RuntimeServices;

/** Répartiteur par défaut : les actions ne sont pas encore branchées. */
final class UnavailableActionDispatcher implements ActionDispatcher
{
    public function dispatch(
        string $identifier,
        array $arguments,
        SourceSpan $span,
        RuntimeServices $services,
    ): mixed {
        throw new ExecutionException(new Diagnostic(
            DiagnosticCode::ACTION_UNAVAILABLE,
            'Action indisponible : « ' . $identifier . ' ».',
            $span,
        ));
    }
}
