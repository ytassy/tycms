<?php

declare(strict_types=1);

namespace YTassy\Tycms\Lib\Forms;

/** Executes a trusted callback when a computed field is persisted. */
final class CallbackFormComputedProvider implements FormComputedProvider
{
    /** @var \Closure(FormComputedContext):mixed */
    private readonly \Closure $callback;

    /** @param \Closure(FormComputedContext):mixed $callback */
    public function __construct(\Closure $callback)
    {
        $this->callback = $callback;
    }

    public function compute(FormComputedContext $context): mixed
    {
        return ($this->callback)($context);
    }
}
