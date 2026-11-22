<?php

namespace YTassy\Tycms\CoreExts\Core\ExtensionApi\Mail;

final readonly class EmailTemplateDefinition
{
    /**
     * @param array<string, array{subject:string, bodyHtml:string}> $variants
     * @param list<EmailTemplateVariable> $variables
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $description,
        public bool $customizable = false,
        public string $renderMode = 'default',
        public array $variants = [],
        public array $variables = [],
    ) {}
}
