<?php

namespace YTassy\Tycms\Lib\HtmlSanitizer;

/** Référence CSS simple utilisable dans une propriété de couleur, sans expression ni repli. */
final class CssColorVariable
{
    public static function name(string $value): ?string
    {
        if (!preg_match('/^var\((--[a-zA-Z_][a-zA-Z0-9_-]{0,63})\)$/D', $value, $matches)) return null;
        return $matches[1];
    }
}
