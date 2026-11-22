<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Runtime\Builtin;

use YTassy\Tycms\CoreExts\Automation\Language\DiagnosticCode;
use YTassy\Tycms\CoreExts\Automation\Language\SourceSpan;
use YTassy\Tycms\CoreExts\Automation\Runtime\RuntimeServices;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;

final class ArrayFunctions
{
    /** @return array<string, callable(array<mixed>, RuntimeServices, SourceSpan): mixed> */
    public function definitions(): array
    {
        return [
            'arr::length' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => count(Args::array($args, 0, $span)),
            'arr::isEmpty' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => count(Args::array($args, 0, $span)) === 0,
            'arr::keys' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => array_keys(Args::array($args, 0, $span)),
            'arr::values' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => array_values(Args::array($args, 0, $span)),
            'arr::has' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => array_key_exists(
                Args::key($args, 1, $span),
                Args::array($args, 0, $span),
            ),
            'arr::get' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => $this->get($args, $span),
            'arr::set' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => $this->set($args, $span),
            'arr::remove' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => $this->remove($args, $span),
            'arr::push' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => $this->push($args, $span),
            'arr::prepend' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => $this->prepend($args, $span),
            'arr::concat' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => array_merge(
                array_values(Args::array($args, 0, $span)),
                array_values(Args::array($args, 1, $span)),
            ),
            'arr::merge' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => array_merge(
                Args::array($args, 0, $span),
                Args::array($args, 1, $span),
            ),
            'arr::slice' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => $this->slice($args, $span),
            'arr::first' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => $this->first($args, $span),
            'arr::last' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => $this->last($args, $span),
            'arr::contains' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => $this->contains($args, $span),
            'arr::indexOf' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => $this->indexOf($args, $span),
            'arr::unique' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => $this->unique($args, $span),
            'arr::reverse' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => $this->reverse($args, $span),
            'arr::column' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => $this->column($args, $span),
            'arr::sortBy' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => $this->sortBy($args, $span),
            'arr::fromPairs' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => $this->fromPairs($args, $span),
            'arr::toPairs' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => $this->toPairs($args, $span),
            'arr::range' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => $this->range($args, $services, $span),
        ];
    }

    /** @param array<mixed> $args */
    private function get(array $args, SourceSpan $span): mixed
    {
        $array = Args::array($args, 0, $span);
        $key = Args::key($args, 1, $span);

        return array_key_exists($key, $array) ? $array[$key] : ($args[2] ?? null);
    }

    /** @param array<mixed> $args */
    private function set(array $args, SourceSpan $span): array
    {
        $array = Args::array($args, 0, $span);
        $array[Args::key($args, 1, $span)] = $args[2] ?? null;

        return $array;
    }

    /** @param array<mixed> $args */
    private function remove(array $args, SourceSpan $span): array
    {
        $array = Args::array($args, 0, $span);
        unset($array[Args::key($args, 1, $span)]);

        return $array;
    }

    /** @param array<mixed> $args */
    private function push(array $args, SourceSpan $span): array
    {
        $array = Args::array($args, 0, $span);
        $array[] = $args[1] ?? null;

        return $array;
    }

    /** @param array<mixed> $args */
    private function prepend(array $args, SourceSpan $span): array
    {
        $array = Args::array($args, 0, $span);
        array_unshift($array, $args[1] ?? null);

        return $array;
    }

    /** @param array<mixed> $args */
    private function slice(array $args, SourceSpan $span): array
    {
        $array = Args::array($args, 0, $span);
        $offset = Args::int($args, 1, $span);
        $length = Args::optionalInt($args, 2, $span);

        if (array_is_list($array)) {
            return array_slice($array, $offset, $length);
        }

        return array_slice($array, $offset, $length, true);
    }

    /** @param array<mixed> $args */
    private function first(array $args, SourceSpan $span): mixed
    {
        $array = Args::array($args, 0, $span);
        $key = array_key_first($array);

        return $key === null ? null : $array[$key];
    }

    /** @param array<mixed> $args */
    private function last(array $args, SourceSpan $span): mixed
    {
        $array = Args::array($args, 0, $span);
        $key = array_key_last($array);

        return $key === null ? null : $array[$key];
    }

    /** @param array<mixed> $args */
    private function contains(array $args, SourceSpan $span): bool
    {
        $needle = $args[1] ?? null;

        foreach (Args::array($args, 0, $span) as $value) {
            if (ValueTools::equals($value, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<mixed> $args */
    private function indexOf(array $args, SourceSpan $span): int
    {
        $needle = $args[1] ?? null;

        foreach (Args::array($args, 0, $span) as $key => $value) {
            if (ValueTools::equals($value, $needle)) {
                return is_int($key) ? $key : -1;
            }
        }

        return -1;
    }

    /** @param array<mixed> $args */
    private function unique(array $args, SourceSpan $span): array
    {
        $result = [];

        foreach (Args::array($args, 0, $span) as $value) {
            $exists = false;

            foreach ($result as $seen) {
                if (ValueTools::equals($seen, $value)) {
                    $exists = true;
                    break;
                }
            }

            if (!$exists) {
                $result[] = $value;
            }
        }

        return $result;
    }

    /** @param array<mixed> $args */
    private function reverse(array $args, SourceSpan $span): array
    {
        $array = Args::array($args, 0, $span);

        return array_is_list($array) ? array_reverse($array) : array_reverse($array, true);
    }

    /** @param array<mixed> $args */
    private function column(array $args, SourceSpan $span): array
    {
        $list = Args::array($args, 0, $span);
        $key = Args::key($args, 1, $span);
        $result = [];

        foreach ($list as $item) {
            $result[] = is_array($item) && array_key_exists($key, $item) ? $item[$key] : null;
        }

        return $result;
    }

    /** @param array<mixed> $args */
    private function sortBy(array $args, SourceSpan $span): array
    {
        $list = Args::array($args, 0, $span);
        $key = Args::key($args, 1, $span);
        $direction = strtolower(Args::optionalString($args, 2, $span, 'asc') ?? 'asc');

        if ($direction !== 'asc' && $direction !== 'desc') {
            throw ValueTools::error(
                DiagnosticCode::INVALID_ARGUMENT,
                'Direction de tri attendue : « asc » ou « desc ».',
                $span,
            );
        }

        $indexed = [];

        foreach ($list as $index => $item) {
            $value = is_array($item) && array_key_exists($key, $item) ? $item[$key] : null;
            $indexed[] = ['index' => $index, 'value' => $value, 'item' => $item];
        }

        usort($indexed, function (array $left, array $right) use ($direction, $span): int {
            $leftValue = $left['value'];
            $rightValue = $right['value'];

            if ($leftValue === null && $rightValue === null) {
                return $left['index'] <=> $right['index'];
            }

            if ($leftValue === null) {
                return $direction === 'asc' ? -1 : 1;
            }

            if ($rightValue === null) {
                return $direction === 'asc' ? 1 : -1;
            }

            $comparison = ValueTools::compare($leftValue, $rightValue, $span);

            if ($comparison === 0) {
                return $left['index'] <=> $right['index'];
            }

            return $direction === 'asc' ? $comparison : -$comparison;
        });

        return array_map(static fn (array $entry): mixed => $entry['item'], $indexed);
    }

    /** @param array<mixed> $args */
    private function fromPairs(array $args, SourceSpan $span): array
    {
        $pairs = Args::array($args, 0, $span);
        $result = [];

        foreach ($pairs as $pair) {
            if (!is_array($pair) || !array_key_exists(0, $pair) || !array_key_exists(1, $pair)) {
                throw ValueTools::error(
                    DiagnosticCode::INVALID_ARGUMENT,
                    'arr::fromPairs attend une liste de paires [clé, valeur].',
                    $span,
                );
            }

            $key = $pair[0];
            if (!is_int($key) && !is_string($key)) {
                throw ValueTools::error(
                    DiagnosticCode::INVALID_ARGUMENT,
                    'Clé de paire invalide : int ou string attendu.',
                    $span,
                );
            }

            $result[$key] = $pair[1];
        }

        return $result;
    }

    /** @param array<mixed> $args */
    private function toPairs(array $args, SourceSpan $span): array
    {
        $result = [];

        foreach (Args::array($args, 0, $span) as $key => $value) {
            $result[] = [$key, $value];
        }

        return $result;
    }

    /** @param array<mixed> $args */
    private function range(array $args, RuntimeServices $services, SourceSpan $span): array
    {
        $start = Args::int($args, 0, $span);
        $end = Args::int($args, 1, $span);
        $step = Args::optionalInt($args, 2, $span, 1) ?? 1;

        if ($step === 0) {
            throw ValueTools::error(
                DiagnosticCode::INVALID_ARGUMENT,
                'Le pas de arr::range ne peut pas être nul.',
                $span,
            );
        }

        $result = [];

        if ($step > 0) {
            for ($value = $start; $value <= $end; $value += $step) {
                $result[] = $value;
                $this->assertRangeSize($result, $services, $span);
            }
        } else {
            for ($value = $start; $value >= $end; $value += $step) {
                $result[] = $value;
                $this->assertRangeSize($result, $services, $span);
            }
        }

        return $result;
    }

    /** @param array<mixed> $result */
    private function assertRangeSize(array $result, RuntimeServices $services, SourceSpan $span): void
    {
        if (count($result) > $services->limits->maxArrayItems) {
            throw ValueTools::error(
                DiagnosticCode::LIMIT_EXCEEDED,
                'Taille de tableau dépassée (maximum ' . $services->limits->maxArrayItems . ' éléments).',
                $span,
            );
        }
    }
}
