<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Runtime;

use YTassy\Tycms\CoreExts\Automation\Language\Diagnostic;
use YTassy\Tycms\CoreExts\Automation\Language\Limits;
use YTassy\Tycms\CoreExts\Automation\Language\SourceSpan;
use YTassy\Tycms\CoreExts\Automation\Runtime\Action\ActionDispatcher;
use YTassy\Tycms\CoreExts\Automation\Runtime\Action\UnavailableActionDispatcher;
use YTassy\Tycms\CoreExts\Automation\Runtime\Clock\RuntimeClock;
use YTassy\Tycms\CoreExts\Automation\Runtime\Clock\SystemClock;
use YTassy\Tycms\CoreExts\Automation\Runtime\Identity\IdGenerator;
use YTassy\Tycms\CoreExts\Automation\Runtime\Identity\SystemIdGenerator;
use YTassy\Tycms\CoreExts\Automation\Runtime\Random\RandomSource;
use YTassy\Tycms\CoreExts\Automation\Runtime\Random\SystemRandom;

final readonly class ExecutionContext
{
    /**
     * @param array<mixed> $input
     * @param array<mixed> $principal
     * @param array<mixed> $space
     * @param array<mixed> $run
     */
    public function __construct(
        public array $input,
        public array $principal,
        public array $space,
        public array $run,
        public RuntimeClock $clock,
        public RandomSource $random,
        public IdGenerator $ids,
        public ActionDispatcher $actions,
    ) {
    }

    /**
     * @param array<mixed> $input
     * @param array<mixed> $principal
     * @param array<mixed> $space
     * @param array<mixed> $run
     */
    public static function create(
        array $input = [],
        array $principal = [],
        array $space = [],
        array $run = [],
        ?ActionDispatcher $actions = null,
        ?RuntimeClock $clock = null,
        ?RandomSource $random = null,
        ?IdGenerator $ids = null,
    ): self {
        $timezone = is_string($space['timezone'] ?? null) ? $space['timezone'] : 'UTC';
        $clock ??= new SystemClock($timezone);
        $random ??= new SystemRandom();
        $ids ??= new SystemIdGenerator();

        if (!isset($run['startedAt'])) {
            $run['startedAt'] = $clock->now();
        }

        if (!isset($run['id'])) {
            $run['id'] = $ids->uuid();
        }

        return new self(
            $input,
            $principal,
            $space,
            $run,
            $clock,
            $random,
            $ids,
            $actions ?? new UnavailableActionDispatcher(),
        );
    }

    public function timezone(): string
    {
        return is_string($this->space['timezone'] ?? null) ? $this->space['timezone'] : 'UTC';
    }
}
