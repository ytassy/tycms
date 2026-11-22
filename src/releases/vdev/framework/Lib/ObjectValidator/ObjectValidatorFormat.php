<?php

namespace YTassy\Tycms\Lib\ObjectValidator;

/**
 * A trusted server-side semantic validator used by the schema "format" keyword.
 */
interface ObjectValidatorFormat
{
    public function validate(mixed $value, ObjectValidatorFormatContext $context): bool|ObjectValidatorFormatResult;
}
