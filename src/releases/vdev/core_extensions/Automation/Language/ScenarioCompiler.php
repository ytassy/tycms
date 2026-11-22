<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Language;

use YTassy\Tycms\CoreExts\Automation\Language\Registry\BuiltinFunctionCatalog;
use YTassy\Tycms\CoreExts\Automation\Language\Registry\FunctionCatalog;

/** Point d'entrée du lot 1 : analyse puis validation statique. */
final class ScenarioCompiler
{
    private readonly Limits $limits;
    private readonly FunctionCatalog $catalog;
    private readonly Parser $parser;

    public function __construct(
        ?FunctionCatalog $catalog = null,
        ?Limits $limits = null,
        ?Parser $parser = null,
    ) {
        $this->limits = $limits ?? new Limits();
        $this->catalog = $catalog ?? BuiltinFunctionCatalog::create();
        $this->parser = $parser ?? new Parser(new Lexer(), $this->limits);
    }

    public function compile(string $source): CompilationResult
    {
        $parsed = $this->parser->parse($source);

        if (!$parsed->isSuccessful()) {
            return new CompilationResult($parsed->program, $parsed->diagnostics);
        }

        $validator = new Validator($this->catalog, $this->limits);

        return new CompilationResult($parsed->program, $validator->validate($parsed->program));
    }
}
