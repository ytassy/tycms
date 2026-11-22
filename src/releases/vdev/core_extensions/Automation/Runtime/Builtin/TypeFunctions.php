<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Runtime\Builtin;

use YTassy\Tycms\CoreExts\Automation\Language\SourceSpan;
use YTassy\Tycms\CoreExts\Automation\Runtime\RuntimeServices;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;

final class TypeFunctions
{
    /** @return array<string, callable(array<mixed>, RuntimeServices, SourceSpan): mixed> */
    public function definitions(): array
    {
        return [
            'type::of' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => ValueTools::type($args[0] ?? null),
            'type::isNull' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => ($args[0] ?? null) === null,
            'type::isBool' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => is_bool($args[0] ?? null),
            'type::isInt' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => is_int($args[0] ?? null),
            'type::isFloat' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => is_float($args[0] ?? null),
            'type::isNumber' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => is_int($args[0] ?? null) || is_float($args[0] ?? null),
            'type::isString' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => is_string($args[0] ?? null),
            'type::isArray' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => is_array($args[0] ?? null),
            'type::isDate' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => ($args[0] ?? null) instanceof \YTassy\Tycms\CoreExts\Automation\Runtime\DateValue,
            'type::toString' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => ValueTools::toString($args[0] ?? null, $span),
            'type::toInt' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => ValueTools::toInt($args[0] ?? null, $span),
            'type::toFloat' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => ValueTools::toFloat($args[0] ?? null, $span),
            'type::toBool' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => ValueTools::toBool($args[0] ?? null, $span),
        ];
    }
}
