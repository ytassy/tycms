<?php

declare(strict_types=1);

namespace YTassy\Tycms\Lib\Forms;

/** Context passed only to trusted, server-side option providers. */
final class FormOptionContext
{
    /**
     * @param array<string,mixed> $field
     * @param array<string,mixed> $values
     * @param array<string,mixed> $attributes
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
