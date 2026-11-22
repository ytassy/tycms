<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\ExtensionApi;

final class AutomationPrincipalMode
{
    public const TRIGGER = 'trigger';
    public const AUTHOR = 'author';
    public const SERVICE = 'service';

    private function __construct()
    {
    }
}
