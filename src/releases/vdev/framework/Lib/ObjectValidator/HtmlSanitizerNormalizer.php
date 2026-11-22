<?php

namespace YTassy\Tycms\Lib\ObjectValidator;

use YTassy\Tycms\Lib\HtmlSanitizer\HtmlSanitizer;

/**
 * Sanitizes an HTML string without coercing values of another type.
 */
final class HtmlSanitizerNormalizer implements ObjectValidatorNormalizer
{
    public function normalize(mixed $value, ObjectValidatorNormalizerContext $context): mixed
    {
        return is_string($value) ? HtmlSanitizer::sanitize($value) : $value;
    }
}
