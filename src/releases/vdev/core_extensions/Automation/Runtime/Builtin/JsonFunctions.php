<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Runtime\Builtin;

use JsonException;
use YTassy\Tycms\CoreExts\Automation\Language\DiagnosticCode;
use YTassy\Tycms\CoreExts\Automation\Language\SourceSpan;
use YTassy\Tycms\CoreExts\Automation\Runtime\RuntimeServices;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;

final class JsonFunctions
{
    /** @return array<string, callable(array<mixed>, RuntimeServices, SourceSpan): mixed> */
    public function definitions(): array
    {
        return [
            'json::encode' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => $this->encode($args, $span),
            'json::decode' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => $this->decode($args, $services, $span),
            'json::isValid' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => $this->isValid($args, $span),
        ];
    }

    /** @param array<mixed> $args */
    private function encode(array $args, SourceSpan $span): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

        if (Args::optionalBool($args, 1, $span, false)) {
            $flags |= JSON_PRETTY_PRINT;
        }

        try {
            return json_encode(ValueTools::toJsonSafe($args[0] ?? null, $span), $flags);
        } catch (JsonException) {
            throw ValueTools::error(DiagnosticCode::INVALID_ARGUMENT, 'Encodage JSON impossible.', $span);
        }
    }

    /** @param array<mixed> $args */
    private function decode(array $args, RuntimeServices $services, SourceSpan $span): mixed
    {
        $json = Args::string($args, 0, $span);
        $depth = Args::optionalInt($args, 1, $span, 32) ?? 32;

        if ($depth < 1 || $depth > 128) {
            throw ValueTools::error(DiagnosticCode::INVALID_ARGUMENT, 'Profondeur JSON attendue entre 1 et 128.', $span);
        }

        try {
            $decoded = json_decode($json, true, $depth, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ValueTools::error(DiagnosticCode::INVALID_ARGUMENT, 'JSON invalide.', $span);
        }

        return ValueTools::assertSafe($decoded, $services->limits, $span);
    }

    /** @param array<mixed> $args */
    private function isValid(array $args, SourceSpan $span): bool
    {
        try {
            json_decode(Args::string($args, 0, $span), true, 32, JSON_THROW_ON_ERROR);

            return true;
        } catch (JsonException) {
            return false;
        }
    }
}
