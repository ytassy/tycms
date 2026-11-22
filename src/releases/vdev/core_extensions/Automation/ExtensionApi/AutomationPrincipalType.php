<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\ExtensionApi;

final class AutomationPrincipalType
{
    public const USER = 'user';
    public const SERVICE = 'service';
    public const ANONYMOUS = 'anonymous';

    private function __construct()
    {
    }
}
