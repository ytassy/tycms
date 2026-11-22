<?php

namespace YTassy\Tycms\CoreExts\Core\Helper\Page;

use YTassy\Tycms\Response\ExceptionResponse;

/** Limites communes aux entrées de l'éditeur ; la lecture garde le plafond absolu. */
final class PageSize
{
    public const ABSOLUTE_MAX = 5_000_000;
    public const DEFAULT_MAX = 1_000_000;
    public const DEFAULT_DIAGRAM_MAX = 250_000;

    public static function pageMaximum(object $configuration): int
    {
        return self::configured($configuration->page_max_size ?? null, self::DEFAULT_MAX);
    }

    public static function diagramMaximum(object $configuration): int
    {
        return min(self::pageMaximum($configuration),
            self::configured($configuration->page_diagram_max_size ?? null, self::DEFAULT_DIAGRAM_MAX));
    }

    public static function assertDocument(mixed $document, int $maximum = self::ABSOLUTE_MAX): void
    {
        // Mesurer la sérialisation réellement enregistrée, échappements des slash compris.
        if (strlen(self::encodeDocument($document)) > min($maximum, self::ABSOLUTE_MAX)) {
            ExceptionResponse::throwWarning('Le contenu de la page dépasse la limite de '
                . number_format(min($maximum, self::ABSOLUTE_MAX), 0, ',', ' ') . ' octets.');
        }
    }

    public static function encodeDocument(mixed $document): string
    {
        return json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private static function configured(mixed $value, int $default): int
    {
        return is_int($value) && $value > 0 ? min($value, self::ABSOLUTE_MAX) : $default;
    }
}
