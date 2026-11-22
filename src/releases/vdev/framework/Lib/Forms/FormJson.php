<?php

declare(strict_types=1);

namespace YTassy\Tycms\Lib\Forms;

use YTassy\Tycms\Exceptions\TechException;

/** Internal guard ensuring the RIA contract contains only JSON values. */
final class FormJson
{
    public static function assertValue(mixed $value, string $path): void
    {
        if ($value === null || is_scalar($value)) {
            return;
        }

        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        } elseif (!is_array($value)) {
            throw new TechException("The RIA form definition contains a non-JSON value at '$path'.");
        }

        foreach ($value as $key => $child) {
            if (!is_string($key) && !is_int($key)) {
                throw new TechException("The RIA form definition contains an invalid key at '$path'.");
            }

            self::assertValue($child, $path . '/' . $key);
        }
    }
}
