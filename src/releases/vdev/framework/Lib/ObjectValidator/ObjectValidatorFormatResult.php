<?php

namespace YTassy\Tycms\Lib\ObjectValidator;

/**
 * Optional detailed result returned by a custom format.
 */
final class ObjectValidatorFormatResult
{
    private function __construct(
        private readonly bool $valid,
        private readonly ?string $message = null,
    ) {
    }

    public static function valid(): self
    {
        return new self(true);
    }

    public static function invalid(?string $message = null): self
    {
        return new self(false, $message);
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }
}
