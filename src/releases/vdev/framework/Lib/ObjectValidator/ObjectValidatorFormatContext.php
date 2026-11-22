<?php

namespace YTassy\Tycms\Lib\ObjectValidator;

/**
 * Context made available to a server-side format validator.
 */
final class ObjectValidatorFormatContext
{
    /**
     * @param array<string,mixed> $schema
     */
    public function __construct(
        public readonly string $format,
        public readonly string $path,
        public readonly mixed $rootData,
        public readonly array $schema,
    ) {
    }
}
