<?php

namespace YTassy\Tycms\CoreExts\Core\ExtensionApi\Mail;

final readonly class EmailTemplateVariable
{
    public function __construct(
        public string $name,
        public string $type = 'text',
        public bool $required = true,
        public string $example = '',
        public bool $allowedInSubject = true,
        public bool $requiredInBody = false,
    ) {}
}
