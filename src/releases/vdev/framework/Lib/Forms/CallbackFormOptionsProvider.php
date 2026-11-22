<?php

declare(strict_types=1);

namespace YTassy\Tycms\Lib\Forms;

/** Executes a trusted callback while the form is materialized for Vue. */
final class CallbackFormOptionsProvider implements FormOptionsProvider
{
    /** @var \Closure(FormOptionContext):array<int|string,mixed> */
    private readonly \Closure $callback;

    /** @param \Closure(FormOptionContext):array<int|string,mixed> $callback */
    public function __construct(\Closure $callback)
    {
        $this->callback = $callback;
    }

    public function resolve(FormOptionContext $context): array
    {
        return ($this->callback)($context);
    }
}
