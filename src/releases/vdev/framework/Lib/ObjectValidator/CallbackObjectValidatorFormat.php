<?php

namespace YTassy\Tycms\Lib\ObjectValidator;

use Closure;

/**
 * Internal adapter for the framework's standard formats.
 *
 * Applications should usually create a dedicated ObjectValidatorFormat class
 * so it can receive its own helper or database dependency.
 */
final class CallbackObjectValidatorFormat implements ObjectValidatorFormat
{
    /** @var Closure(mixed,ObjectValidatorFormatContext): bool|ObjectValidatorFormatResult */
    private readonly Closure $callback;

    /**
     * @param callable(mixed,ObjectValidatorFormatContext): bool|ObjectValidatorFormatResult $callback
     */
    public function __construct(callable $callback)
    {
        $this->callback = Closure::fromCallable($callback);
    }

    public function validate(mixed $value, ObjectValidatorFormatContext $context): bool|ObjectValidatorFormatResult
    {
        return ($this->callback)($value, $context);
    }
}
