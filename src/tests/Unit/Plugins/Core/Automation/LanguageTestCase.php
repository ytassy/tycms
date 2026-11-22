<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\Automation;

use PHPUnit\Framework\TestCase;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\FunctionDecl;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Program;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Stmt\AssignmentStatement;
use YTassy\Tycms\CoreExts\Automation\Language\CompilationResult;
use YTassy\Tycms\CoreExts\Automation\Language\Diagnostic;
use YTassy\Tycms\CoreExts\Automation\Language\ParseResult;
use YTassy\Tycms\CoreExts\Automation\Language\Parser;
use YTassy\Tycms\CoreExts\Automation\Language\ScenarioCompiler;

abstract class LanguageTestCase extends TestCase
{
    protected function parse(string $source): ParseResult
    {
        return (new Parser())->parse($source);
    }

    protected function compile(string $source): CompilationResult
    {
        return (new ScenarioCompiler())->compile($source);
    }

    protected function assertCompiles(string $source): Program
    {
        $result = $this->compile($source);

        self::assertSame(
            [],
            array_map(static fn (Diagnostic $diagnostic): string => (string) $diagnostic, $result->diagnostics),
        );
        self::assertNotNull($result->program);

        return $result->program;
    }

    protected function assertDiagnostic(string $source, string $code): Diagnostic
    {
        $result = $this->compile($source);

        foreach ($result->diagnostics as $diagnostic) {
            if ($diagnostic->code === $code) {
                self::assertNotSame('', $diagnostic->code);

                return $diagnostic;
            }
        }

        self::fail(
            'Diagnostic ' . $code . ' absent. Présents : '
            . implode(', ', array_map(
                static fn (Diagnostic $diagnostic): string => $diagnostic->code,
                $result->diagnostics,
            )),
        );
    }

    protected function main(Program $program): FunctionDecl
    {
        foreach ($program->functions as $function) {
            if ($function->name === 'main') {
                return $function;
            }
        }

        self::fail('Fonction main() absente du programme.');
    }

    protected function firstAssignment(Program $program): AssignmentStatement
    {
        $statement = $this->main($program)->body->statements[0] ?? null;
        self::assertInstanceOf(AssignmentStatement::class, $statement);

        return $statement;
    }
}
