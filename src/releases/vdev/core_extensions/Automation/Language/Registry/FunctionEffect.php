<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Language\Registry;

/** Classe d'effet déclarée par une fonction du registre. */
final class FunctionEffect
{
    public const PURE = 'pure';
    public const READ = 'read';
    public const WRITE = 'write';
    public const EXTERNAL = 'external';

    private function __construct()
    {
    }
}
