<?php

namespace YTassy\Tycms\Lib\ObjectValidator;

use JsonSerializable;

/**
 * Immutable result of an ObjectValidator call.
 */
final class ObjectValidationResult implements JsonSerializable
{
    /**
     * @param list<ObjectValidationError> $errors
     */
    public function __construct(
        public readonly mixed $value,
        private readonly array $errors,
    ) {
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /**
     * @return list<ObjectValidationError>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getFirstError(): ?ObjectValidationError
    {
        return $this->errors[0] ?? null;
    }

    public function getErrorsAsString(): string
    {
        $messages = [];

        foreach ($this->errors as $error) {
            $messages[] = $error->message;
        }

        return implode("\n", $messages);
    }

    public function jsonSerialize(): array
    {
        return [
            'valid' => $this->isValid(),
            'errors' => $this->errors,
        ];
    }
}
