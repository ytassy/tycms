<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Runtime;

use YTassy\Tycms\CoreExts\Automation\Language\SourcePosition;
use YTassy\Tycms\CoreExts\Automation\Language\SourceSpan;

/** Encodage borné des valeurs du journal, sans objet PHP. */
final class AutomationValueJson
{
    private const MAX_JSON_BYTES = 1048576;

    public static function encode(mixed $value): string
    {
        try {
            $safe = ValueTools::toJsonSafe($value, self::neutralSpan());
            $json = json_encode(
                $safe,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );

            return strlen($json) > self::MAX_JSON_BYTES ? 'null' : $json;
        } catch (\Throwable) {
            return 'null';
        }
    }

    private static function neutralSpan(): SourceSpan
    {
        $position = new SourcePosition(0, 1, 1);

        return new SourceSpan($position, $position);
    }
}
