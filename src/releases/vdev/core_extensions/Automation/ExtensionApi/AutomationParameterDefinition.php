<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\ExtensionApi;

final readonly class AutomationParameterDefinition
{
    public function __construct(
        public string $name,
        public string $type,
        public bool $required = true,
        public mixed $default = null,
        public string $label = '',
        public string $description = '',
    ) {
    }
}
