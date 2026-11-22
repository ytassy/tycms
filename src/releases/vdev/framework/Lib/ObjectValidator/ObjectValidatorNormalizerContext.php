<?php

namespace YTassy\Tycms\Lib\ObjectValidator;

/**
 * Context made available to a server-side normalizer.
 */
final class ObjectValidatorNormalizerContext
{
    /**
     * @param array<string,mixed> $schema
     */
    public function __construct(
        public readonly string $normalizer,
        public readonly string $path,
        public readonly array $schema,
    ) {
    }
}
