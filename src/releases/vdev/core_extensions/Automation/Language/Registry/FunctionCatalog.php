<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Language\Registry;

/** Catalogue consulté par la validation statique et, plus tard, par l'exécution. */
interface FunctionCatalog
{
    public function find(string $identifier): ?FunctionSignature;
}
