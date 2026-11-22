<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\ExtensionApi;

final readonly class AutomationTriggerDefinition
{
    /**
     * @param list<AutomationPayloadFieldDefinition> $payloadFields
     * @param array<string, mixed>|null $payloadExample
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $description = '',
        public string $defaultPrincipalMode = AutomationPrincipalMode::TRIGGER,
        public array $payloadFields = [],
        public ?array $payloadExample = null,
    ) {
    }
}
