<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Runtime\Builtin;

use YTassy\Tycms\CoreExts\Automation\Language\DiagnosticCode;
use YTassy\Tycms\CoreExts\Automation\Language\SourceSpan;
use YTassy\Tycms\CoreExts\Automation\Runtime\DateValue;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;

/** Lecture typée des arguments d'une fonction intégrée. */
final class Args
{
    /** @param array<mixed> $args */
    public static function count(array $args, int $min, ?int $max, SourceSpan $span): void
    {
        $given = count($args);

        if ($given < $min || ($max !== null && $given > $max)) {
            $expected = $max === null ? $min . ' argument(s) ou plus' : $min . ' à ' . $max . ' arguments';

            throw ValueTools::error(
                DiagnosticCode::INVALID_ARGUMENT,
                'Nombre d’arguments invalide : ' . $expected . ' attendu(s), ' . $given . ' fourni(s).',
                $span,
            );
        }
    }

    /** @param array<mixed> $args */
    public static function string(array $args, int $index, SourceSpan $span): string
    {
        return ValueTools::assertString($args[$index] ?? null, $span);
    }

    /** @param array<mixed> $args */
    public static function optionalString(array $args, int $index, SourceSpan $span, ?string $default = null): ?string
    {
        if (!array_key_exists($index, $args) || $args[$index] === null) {
            return $default;
        }

        return ValueTools::assertString($args[$index], $span);
    }

    /** @param array<mixed> $args */
    public static function int(array $args, int $index, SourceSpan $span): int
    {
        return ValueTools::assertInt($args[$index] ?? null, $span);
    }

    /** @param array<mixed> $args */
    public static function optionalInt(array $args, int $index, SourceSpan $span, ?int $default = null): ?int
    {
        if (!array_key_exists($index, $args) || $args[$index] === null) {
            return $default;
        }

        return ValueTools::assertInt($args[$index], $span);
    }

    /** @param array<mixed> $args */
    public static function number(array $args, int $index, SourceSpan $span): int|float
    {
        return ValueTools::assertNumber($args[$index] ?? null, $span);
    }

    /** @param array<mixed> $args */
    public static function bool(array $args, int $index, SourceSpan $span): bool
    {
        return ValueTools::assertBool($args[$index] ?? null, $span);
    }

    /** @param array<mixed> $args */
    public static function optionalBool(array $args, int $index, SourceSpan $span, bool $default = false): bool
    {
        if (!array_key_exists($index, $args) || $args[$index] === null) {
            return $default;
        }

        return ValueTools::assertBool($args[$index], $span);
    }

    /** @param array<mixed> $args @return array<mixed> */
    public static function array(array $args, int $index, SourceSpan $span): array
    {
        return ValueTools::assertArray($args[$index] ?? null, $span);
    }

    /** @param array<mixed> $args */
    public static function date(array $args, int $index, SourceSpan $span): DateValue
    {
        return ValueTools::assertDate($args[$index] ?? null, $span);
    }

    /** @param array<mixed> $args */
    public static function key(array $args, int $index, SourceSpan $span): int|string
    {
        $key = $args[$index] ?? null;

        if (!is_int($key) && !is_string($key)) {
            throw ValueTools::error(
                DiagnosticCode::TYPE_ERROR,
                'Clé de tableau attendue (int|string), ' . ValueTools::type($key) . ' fourni.',
                $span,
            );
        }

        return $key;
    }

}
