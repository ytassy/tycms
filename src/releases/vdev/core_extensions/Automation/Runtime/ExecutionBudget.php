<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Runtime;

use YTassy\Tycms\CoreExts\Automation\Language\DiagnosticCode;
use YTassy\Tycms\CoreExts\Automation\Language\Limits;
use YTassy\Tycms\CoreExts\Automation\Language\SourceSpan;

final class ExecutionBudget
{
    private int $steps = 0;
    private int $loopIterations = 0;
    private int $actionCalls = 0;
    private readonly float $startedAt;

    public function __construct(
        private readonly Limits $limits,
        ?float $startedAt = null,
    ) {
        $this->startedAt = $startedAt ?? self::monotonicSeconds();
    }

    public function step(SourceSpan $span): void
    {
        $this->steps++;

        if ($this->steps > $this->limits->maxSteps) {
            throw ValueTools::error(
                DiagnosticCode::STEP_LIMIT_EXCEEDED,
                'Nombre maximal d’étapes dépassé (maximum ' . $this->limits->maxSteps . ').',
                $span,
            );
        }

        $this->checkDuration($span);
    }

    public function loopIteration(SourceSpan $span, int $iterationInLoop): void
    {
        $this->loopIterations++;

        if ($iterationInLoop > $this->limits->maxLoopIterationsPerLoop) {
            throw ValueTools::error(
                DiagnosticCode::LOOP_LIMIT_EXCEEDED,
                'Nombre maximal d’itérations pour une boucle dépassé (maximum '
                    . $this->limits->maxLoopIterationsPerLoop . ').',
                $span,
            );
        }

        if ($this->loopIterations > $this->limits->maxLoopIterations) {
            throw ValueTools::error(
                DiagnosticCode::LOOP_LIMIT_EXCEEDED,
                'Nombre maximal d’itérations cumulées dépassé (maximum '
                    . $this->limits->maxLoopIterations . ').',
                $span,
            );
        }

        $this->step($span);
    }

    public function actionCall(SourceSpan $span): void
    {
        $this->actionCalls++;

        if ($this->actionCalls > $this->limits->maxActionCalls) {
            throw ValueTools::error(
                DiagnosticCode::LIMIT_EXCEEDED,
                'Nombre maximal d’appels d’action dépassé (maximum '
                    . $this->limits->maxActionCalls . ').',
                $span,
            );
        }

        $this->step($span);
    }

    public function checkDuration(SourceSpan $span): void
    {
        if ($this->elapsedSeconds() > $this->limits->maxRunSeconds) {
            throw ValueTools::error(
                DiagnosticCode::DURATION_EXCEEDED,
                'Durée maximale du run dépassée (maximum ' . $this->limits->maxRunSeconds . ' s).',
                $span,
            );
        }
    }

    public function steps(): int
    {
        return $this->steps;
    }

    public function loopIterations(): int
    {
        return $this->loopIterations;
    }

    public function actionCalls(): int
    {
        return $this->actionCalls;
    }

    public function elapsedSeconds(): float
    {
        return self::monotonicSeconds() - $this->startedAt;
    }

    private static function monotonicSeconds(): float
    {
        return hrtime(true) / 1_000_000_000;
    }
}
