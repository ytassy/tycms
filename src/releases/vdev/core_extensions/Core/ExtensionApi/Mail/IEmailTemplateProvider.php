<?php

namespace YTassy\Tycms\CoreExts\Core\ExtensionApi\Mail;

interface IEmailTemplateProvider
{
    /** @return list<EmailTemplateDefinition> */
    public function emailTemplates(): array;
}
