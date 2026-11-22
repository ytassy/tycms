<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\ExtensionApi;

/** Champ documenté de la charge utile d'un déclencheur. */
final readonly class AutomationPayloadFieldDefinition
{
    public function __construct(
        public string $name,
        public string $type,
        public bool $required = true,
        public string $description = '',
    ) {
    }
}
