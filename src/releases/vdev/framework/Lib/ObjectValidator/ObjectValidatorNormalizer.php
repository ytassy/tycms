<?php

namespace YTassy\Tycms\Lib\ObjectValidator;

/**
 * A trusted server-side transformation selected by a schema normalizer keyword.
 */
interface ObjectValidatorNormalizer
{
    public function normalize(mixed $value, ObjectValidatorNormalizerContext $context): mixed;
}
