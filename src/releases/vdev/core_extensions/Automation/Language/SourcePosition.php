<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Language;

/** Position d'un caractère dans la source, en octets et en ligne/colonne. */
final readonly class SourcePosition
{
    public function __construct(
        public int $offset,
        public int $line,
        public int $column,
    ) {
    }
}
