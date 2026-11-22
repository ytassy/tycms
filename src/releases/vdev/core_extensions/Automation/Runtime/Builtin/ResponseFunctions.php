<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Runtime\Builtin;

use YTassy\Tycms\CoreExts\Automation\Language\SourceSpan;
use YTassy\Tycms\CoreExts\Automation\Runtime\RuntimeServices;

/**
 * Réponses normalisées qu'un scénario peut retourner à son producteur.
 *
 * Le format reprend celui d'`ApiResponse` : `error`, `is_message`,
 * `message_level` et `message`. Le bouton de page l'interprète pour choisir
 * la modale ; les autres producteurs peuvent ignorer le niveau.
 */
final class ResponseFunctions
{
    /** @return array<string, callable(array<mixed>, RuntimeServices, SourceSpan): mixed> */
    public function definitions(): array
    {
        return [
            'res::confirm' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => $this->response($args, $span, 'SUCCESS', false),
            'res::info' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => $this->response($args, $span, 'INFO', false),
            'res::warning' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => $this->response($args, $span, 'WARNING', false),
            'res::danger' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => $this->response($args, $span, 'DANGER', false),
            'res::error' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => $this->response($args, $span, 'ERROR', true),
        ];
    }

    /** @param array<mixed> $args */
    private function response(array $args, SourceSpan $span, string $level, bool $error): array
    {
        return [
            'error' => $error,
            'is_message' => true,
            'message_level' => $level,
            'message' => Args::string($args, 0, $span),
        ];
    }
}
