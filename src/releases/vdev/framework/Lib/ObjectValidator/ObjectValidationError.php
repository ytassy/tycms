<?php

namespace YTassy\Tycms\Lib\ObjectValidator;

use JsonSerializable;

/**
 * One validation error. Path follows the JSON Pointer notation.
 */
final class ObjectValidationError implements JsonSerializable
{
    public function __construct(
        public readonly string $path,
        public readonly string $keyword,
        public readonly string $message,
        public readonly mixed $expected = null,
        public readonly mixed $actual = null,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'path' => $this->path,
            'keyword' => $this->keyword,
            'message' => $this->message,
            'expected' => $this->expected,
            'actual' => $this->actual,
        ];
    }
}
