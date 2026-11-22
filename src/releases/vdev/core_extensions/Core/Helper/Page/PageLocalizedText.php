<?php

namespace YTassy\Tycms\CoreExts\Core\Helper\Page;

use stdClass;
use YTassy\Tycms\Lib\HtmlSanitizer\HtmlSanitizer;
use YTassy\Tycms\Response\ExceptionResponse;

/** Contrat des textes simples ou déclinés par langue dans un document de page. */
final class PageLocalizedText
{
    public static function normalize(mixed $value, int $maxLength, bool $html = false): string|stdClass
    {
        // Un texte simple continue de s'afficher dans toutes les langues.
        if (is_string($value)) {
            return self::normalizeString($value, $maxLength, $html);
        }

        // Chaque traduction est validée et purifiée avant l'enregistrement.
        if (!$value instanceof stdClass || count(get_object_vars($value)) < 1
            || count(get_object_vars($value)) > 20) {
            ExceptionResponse::throwWarning('Texte multilingue invalide.');
        }

        $normalized = new stdClass();

        foreach (get_object_vars($value) as $locale => $text) {
            if (preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/', $locale) !== 1 || !is_string($text)) {
                ExceptionResponse::throwWarning('Langue ou valeur de traduction invalide.');
            }

            $normalized->{$locale} = self::normalizeString($text, $maxLength, $html);
        }

        return $normalized;
    }

    public static function select(mixed $value, string $locale): string
    {
        // Une langue absente ou un champ vide ne déclenche aucun repli implicite.
        if (is_string($value)) {
            return $value;
        }

        return $value instanceof stdClass && is_string($value->{$locale} ?? null)
            ? $value->{$locale}
            : '';
    }

    private static function normalizeString(string $value, int $maxLength, bool $html): string
    {
        if (mb_strlen($value) > $maxLength) {
            ExceptionResponse::throwWarning('Texte de page trop long.');
        }

        return $html ? HtmlSanitizer::sanitize($value) : $value;
    }
}
