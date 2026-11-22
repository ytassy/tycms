<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Runtime;

use YTassy\Tycms\CoreExts\Automation\Language\Diagnostic;
use YTassy\Tycms\CoreExts\Automation\Language\DiagnosticCode;
use YTassy\Tycms\CoreExts\Automation\Language\Limits;
use YTassy\Tycms\CoreExts\Automation\Language\SourceSpan;

/** Conversions et contrôles de la frontière de valeurs. */
final class ValueTools
{
    private const MAX_VALUE_DEPTH = 32;

    public static function type(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => 'bool',
            is_int($value) => 'int',
            is_float($value) => 'float',
            is_string($value) => 'string',
            is_array($value) => 'array',
            $value instanceof DateValue => 'date',
            default => 'invalid',
        };
    }

    /**
     * Refuse tout objet PHP, ressource ou callable et borne la taille des
     * chaînes et des tableaux. Seuls les scalaires, tableaux et DateValue
     * traversent la frontière du langage.
     */
    public static function assertSafe(mixed $value, Limits $limits, SourceSpan $span, int $depth = 0): mixed
    {
        if ($depth > self::MAX_VALUE_DEPTH) {
            throw self::error(DiagnosticCode::LIMIT_EXCEEDED, 'Profondeur de valeur dépassée.', $span);
        }

        if ($value === null || is_bool($value) || is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            if (!is_finite($value)) {
                throw self::error(DiagnosticCode::INVALID_VALUE, 'Nombre flottant non fini.', $span);
            }

            return $value;
        }

        if (is_string($value)) {
            if (strlen($value) > $limits->maxStringBytes) {
                throw self::error(
                    DiagnosticCode::LIMIT_EXCEEDED,
                    'Taille de chaîne dépassée (maximum ' . $limits->maxStringBytes . ' octets).',
                    $span,
                );
            }

            return $value;
        }

        if ($value instanceof DateValue) {
            return $value;
        }

        if (is_array($value)) {
            if (count($value) > $limits->maxArrayItems) {
                throw self::error(
                    DiagnosticCode::LIMIT_EXCEEDED,
                    'Taille de tableau dépassée (maximum ' . $limits->maxArrayItems . ' éléments).',
                    $span,
                );
            }

            foreach ($value as $key => $item) {
                if (!is_int($key) && !is_string($key)) {
                    throw self::error(DiagnosticCode::INVALID_VALUE, 'Clé de tableau invalide.', $span);
                }

                self::assertSafe($item, $limits, $span, $depth + 1);
            }

            return $value;
        }

        throw self::error(
            DiagnosticCode::INVALID_VALUE,
            'Valeur de type interdit : ' . get_debug_type($value) . '.',
            $span,
        );
    }

    public static function assertString(mixed $value, SourceSpan $span): string
    {
        if (!is_string($value)) {
            self::typeError('string', $value, $span);
        }

        return $value;
    }

    public static function assertBool(mixed $value, SourceSpan $span): bool
    {
        if (!is_bool($value)) {
            self::typeError('bool', $value, $span);
        }

        return $value;
    }

    public static function assertInt(mixed $value, SourceSpan $span): int
    {
        if (!is_int($value)) {
            self::typeError('int', $value, $span);
        }

        return $value;
    }

    public static function assertFloat(mixed $value, SourceSpan $span): float
    {
        if (!is_float($value)) {
            self::typeError('float', $value, $span);
        }

        return $value;
    }

    public static function assertNumber(mixed $value, SourceSpan $span): int|float
    {
        if (!is_int($value) && !is_float($value)) {
            self::typeError('int|float', $value, $span);
        }

        if (is_float($value) && !is_finite($value)) {
            throw self::error(DiagnosticCode::TYPE_ERROR, 'Nombre non fini.', $span);
        }

        return $value;
    }

    /** @return array<mixed> */
    public static function assertArray(mixed $value, SourceSpan $span): array
    {
        if (!is_array($value)) {
            self::typeError('array', $value, $span);
        }

        return $value;
    }

    public static function assertDate(mixed $value, SourceSpan $span): DateValue
    {
        if (!$value instanceof DateValue) {
            self::typeError('date', $value, $span);
        }

        return $value;
    }

    public static function toConcatString(mixed $value, SourceSpan $span): string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value) => (string) $value,
            is_float($value) => (string) $value,
            is_bool($value) => $value ? 'true' : 'false',
            default => throw self::error(
                DiagnosticCode::TYPE_ERROR,
                'Concaténation impossible avec une valeur de type ' . self::type($value) . '.',
                $span,
            ),
        };
    }

    public static function toString(mixed $value, SourceSpan $span): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            is_float($value) => (string) $value,
            is_string($value) => $value,
            $value instanceof DateValue => $value->toIso(),
            default => throw self::error(
                DiagnosticCode::TYPE_ERROR,
                'Conversion en chaîne impossible pour le type ' . self::type($value) . '.',
                $span,
            ),
        };
    }

    public static function toInt(mixed $value, SourceSpan $span): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) && is_finite($value)) {
            $integer = (int) $value;

            if ((float) $integer === $value) {
                return $integer;
            }
        }

        if (is_string($value) && preg_match('/\A[+-]?[0-9]+\z/', $value) === 1) {
            $filtered = filter_var($value, FILTER_VALIDATE_INT);

            if (is_int($filtered)) {
                return $filtered;
            }
        }

        throw self::error(
            DiagnosticCode::TYPE_ERROR,
            'Conversion en entier impossible pour la valeur fournie.',
            $span,
        );
    }

    public static function toFloat(mixed $value, SourceSpan $span): float
    {
        if (is_int($value) || is_float($value)) {
            $float = (float) $value;

            if (is_finite($float)) {
                return $float;
            }
        }

        if (is_string($value)) {
            $filtered = filter_var($value, FILTER_VALIDATE_FLOAT);

            if (is_float($filtered) && is_finite($filtered)) {
                return $filtered;
            }
        }

        throw self::error(
            DiagnosticCode::TYPE_ERROR,
            'Conversion en flottant impossible pour la valeur fournie.',
            $span,
        );
    }

    public static function toBool(mixed $value, SourceSpan $span): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }

        if (is_string($value)) {
            return match ($value) {
                'true', '1' => true,
                'false', '0' => false,
                default => throw self::error(
                    DiagnosticCode::TYPE_ERROR,
                    'Conversion en booléen impossible pour la chaîne fournie.',
                    $span,
                ),
            };
        }

        throw self::error(
            DiagnosticCode::TYPE_ERROR,
            'Conversion en booléen impossible pour le type ' . self::type($value) . '.',
            $span,
        );
    }

    public static function equals(mixed $left, mixed $right): bool
    {
        if ($left instanceof DateValue || $right instanceof DateValue) {
            return $left instanceof DateValue
                && $right instanceof DateValue
                && $left->equals($right);
        }

        if (is_int($left) && is_int($right)) {
            return $left === $right;
        }

        if ((is_int($left) || is_float($left)) && (is_int($right) || is_float($right))) {
            return (float) $left === (float) $right;
        }

        if (is_string($left) && is_string($right)) {
            return $left === $right;
        }

        if (is_bool($left) && is_bool($right)) {
            return $left === $right;
        }

        if ($left === null || $right === null) {
            return $left === null && $right === null;
        }

        if (is_array($left) && is_array($right)) {
            if (count($left) !== count($right)) {
                return false;
            }

            foreach ($left as $key => $value) {
                if (!array_key_exists($key, $right) || !self::equals($value, $right[$key])) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    public static function compare(mixed $left, mixed $right, SourceSpan $span): int
    {
        if ((is_int($left) || is_float($left)) && (is_int($right) || is_float($right))) {
            return $left <=> $right;
        }

        if (is_string($left) && is_string($right)) {
            return strcmp($left, $right) <=> 0;
        }

        if ($left instanceof DateValue && $right instanceof DateValue) {
            return $left->compare($right);
        }

        throw self::error(
            DiagnosticCode::TYPE_ERROR,
            'Comparaison impossible entre ' . self::type($left) . ' et ' . self::type($right) . '.',
            $span,
        );
    }

    public static function toJsonSafe(mixed $value, SourceSpan $span): mixed
    {
        if ($value instanceof DateValue) {
            return $value->toIso();
        }

        if (is_array($value)) {
            $result = [];

            foreach ($value as $key => $item) {
                $result[$key] = self::toJsonSafe($item, $span);
            }

            return $result;
        }

        if ($value === null || is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
            return $value;
        }

        throw self::error(
            DiagnosticCode::INVALID_VALUE,
            'Valeur non sérialisable en JSON : ' . get_debug_type($value) . '.',
            $span,
        );
    }

    public static function typeError(string $expected, mixed $actual, SourceSpan $span): never
    {
        throw self::error(
            DiagnosticCode::TYPE_ERROR,
            'Type ' . $expected . ' attendu, ' . self::type($actual) . ' fourni.',
            $span,
        );
    }

    public static function error(string $code, string $message, SourceSpan $span): ExecutionException
    {
        return new ExecutionException(new Diagnostic($code, $message, $span));
    }
}
