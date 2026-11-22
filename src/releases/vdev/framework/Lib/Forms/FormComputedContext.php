<?php

declare(strict_types=1);

namespace YTassy\Tycms\Lib\Forms;

/** Context passed only to trusted, server-side computed value providers. */
final class FormComputedContext
{
    /**
     * @param array<string,mixed> $field
     * @param array<string,mixed> $values Values normalized from the form plus
     *        computed values already resolved earlier in declaration order.
     * @param array<string,mixed> $attributes Trusted workflow context.
     */
    public function __construct(
        public readonly Form $form,
        public readonly array $field,
        public readonly array $values,
        public readonly array $attributes = [],
    ) {
    }

    public function getValue(string $name, mixed $default = null): mixed
    {
        return $this->values[$name] ?? $default;
    }

    public function getAttribute(string $name, mixed $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }
}
