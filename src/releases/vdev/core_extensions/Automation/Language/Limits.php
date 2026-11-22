<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Language;

/** Budgets du parser et de la validation statique. */
final readonly class Limits
{
    public function __construct(
        public int $maxSourceBytes = 65536,
        public int $maxTokens = 20000,
        public int $maxAstDepth = 64,
        public int $maxBlockDepth = 32,
        public int $maxFunctions = 64,
        public int $maxParameters = 16,
        public int $maxSteps = 100000,
        public int $maxLoopIterations = 50000,
        public int $maxLoopIterationsPerLoop = 10000,
        public int $maxCallDepth = 32,
        public int $maxActionCalls = 200,
        public float $maxRunSeconds = 10.0,
        public int $maxStringBytes = 1048576,
        public int $maxArrayItems = 10000,
        public int $maxJournalEntries = 1000,
    ) {
    }
}
