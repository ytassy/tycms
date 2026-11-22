<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Runtime\Builtin;

use YTassy\Tycms\CoreExts\Automation\Language\DiagnosticCode;
use YTassy\Tycms\CoreExts\Automation\Language\SourceSpan;
use YTassy\Tycms\CoreExts\Automation\Runtime\RuntimeServices;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;

final class MathFunctions
{
    /** @return array<string, callable(array<mixed>, RuntimeServices, SourceSpan): mixed> */
    public function definitions(): array
    {
        return [
            'math::abs' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => abs(Args::number($args, 0, $span)),
            'math::min' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => $this->minMax($args, $span, true),
            'math::max' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => $this->minMax($args, $span, false),
            'math::round' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => round(
                Args::number($args, 0, $span),
                Args::optionalInt($args, 1, $span, 0) ?? 0,
            ),
            'math::floor' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => floor(Args::number($args, 0, $span)),
            'math::ceil' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => ceil(Args::number($args, 0, $span)),
            'math::clamp' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => $this->clamp($args, $span),
            'math::sum' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => $this->sum($args, $span),
            'math::avg' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => $this->avg($args, $span),
            'math::pow' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => Args::number($args, 0, $span) ** Args::number($args, 1, $span),
            'math::sqrt' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => $this->sqrt($args, $span),
            'math::sign' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => Args::number($args, 0, $span) <=> 0,
            'math::randomInt' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => $this->randomInt($args, $services, $span),
            'math::randomFloat' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => $services->context->random->float(),
        ];
    }

    /** @param array<mixed> $args */
    private function minMax(array $args, SourceSpan $span, bool $minimum): int|float
    {
        Args::count($args, 1, null, $span);
        $numbers = [];

        foreach (array_keys($args) as $index) {
            $numbers[] = Args::number($args, $index, $span);
        }

        return $minimum ? min($numbers) : max($numbers);
    }

    /** @param array<mixed> $args */
    private function clamp(array $args, SourceSpan $span): int|float
    {
        Args::count($args, 3, 3, $span);
        $value = Args::number($args, 0, $span);
        $minimum = Args::number($args, 1, $span);
        $maximum = Args::number($args, 2, $span);

        if ($minimum > $maximum) {
            throw ValueTools::error(DiagnosticCode::INVALID_ARGUMENT, 'math::clamp attend un minimum inférieur ou égal au maximum.', $span);
        }

        return max($minimum, min($maximum, $value));
    }

    /** @param array<mixed> $args */
    private function sum(array $args, SourceSpan $span): int|float
    {
        $total = 0;

        foreach (Args::array($args, 0, $span) as $value) {
            $total += ValueTools::assertNumber($value, $span);
        }

        return $total;
    }

    /** @param array<mixed> $args */
    private function avg(array $args, SourceSpan $span): float
    {
        $values = Args::array($args, 0, $span);

        if ($values === []) {
            throw ValueTools::error(DiagnosticCode::INVALID_ARGUMENT, 'math::avg attend un tableau non vide.', $span);
        }

        $total = 0.0;

        foreach ($values as $value) {
            $total += ValueTools::assertNumber($value, $span);
        }

        return $total / count($values);
    }

    /** @param array<mixed> $args */
    private function sqrt(array $args, SourceSpan $span): float
    {
        $value = Args::number($args, 0, $span);

        if ($value < 0) {
            throw ValueTools::error(DiagnosticCode::INVALID_ARGUMENT, 'math::sqrt attend un nombre positif.', $span);
        }

        return sqrt($value);
    }

    /** @param array<mixed> $args */
    private function randomInt(array $args, RuntimeServices $services, SourceSpan $span): int
    {
        $minimum = Args::int($args, 0, $span);
        $maximum = Args::int($args, 1, $span);

        if ($minimum > $maximum) {
            throw ValueTools::error(DiagnosticCode::INVALID_ARGUMENT, 'math::randomInt attend un minimum inférieur ou égal au maximum.', $span);
        }

        return $services->context->random->int($minimum, $maximum);
    }
}
