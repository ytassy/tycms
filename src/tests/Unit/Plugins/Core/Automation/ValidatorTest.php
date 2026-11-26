<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\Automation;

use PHPUnit\Framework\Attributes\Test;
use YTassy\Tycms\CoreExts\Automation\Language\CompilationResult;
use YTassy\Tycms\CoreExts\Automation\Language\DiagnosticCode;
use YTassy\Tycms\CoreExts\Automation\Language\Limits;
use YTassy\Tycms\CoreExts\Automation\Language\ScenarioCompiler;

final class ValidatorTest extends LanguageTestCase
{
    #[Test]
    public function mainIsMandatory(): void
    {
        $diagnostic = $this->assertDiagnostic('function other() { }', DiagnosticCode::MISSING_MAIN);

        self::assertSame(DiagnosticCode::MISSING_MAIN, $diagnostic->code);
    }

    #[Test]
    public function mainCannotBeDeclaredTwice(): void
    {
        $this->assertDiagnostic(
            'function main() { } function main() { }',
            DiagnosticCode::DUPLICATE_MAIN,
        );
    }

    #[Test]
    public function mainCannotTakeParameters(): void
    {
        $this->assertDiagnostic('function main($x) { }', DiagnosticCode::MAIN_WITH_PARAMS);
    }

    #[Test]
    public function mainCannotBeCalledByScenarioCode(): void
    {
        $this->assertDiagnostic(
            'function helper() { main(); } function main() { }',
            DiagnosticCode::MAIN_CALLED_BY_CODE,
        );
    }

    #[Test]
    public function unknownUserFunctionIsRejected(): void
    {
        $this->assertDiagnostic(
            'function main() { missing(); }',
            DiagnosticCode::UNKNOWN_FUNCTION,
        );
    }

    #[Test]
    public function unknownQualifiedFunctionIsRejected(): void
    {
        $this->assertDiagnostic(
            'function main() { missing::call(); }',
            DiagnosticCode::UNKNOWN_FUNCTION,
        );
    }

    #[Test]
    public function userFunctionArityIsChecked(): void
    {
        $this->assertDiagnostic(
            'function helper($a, $b) { } function main() { helper(1); }',
            DiagnosticCode::BAD_ARITY,
        );
    }

    #[Test]
    public function qualifiedFunctionArityIsChecked(): void
    {
        $this->assertDiagnostic(
            'function main() { core::sendMailTemplate("template"); }',
            DiagnosticCode::BAD_ARITY,
        );
    }

    #[Test]
    public function breakOutsideLoopIsRejected(): void
    {
        $this->assertDiagnostic('function main() { break; }', DiagnosticCode::BREAK_OUTSIDE_LOOP);
    }

    #[Test]
    public function continueOutsideLoopIsRejected(): void
    {
        $this->assertDiagnostic('function main() { continue; }', DiagnosticCode::CONTINUE_OUTSIDE_LOOP);
    }

    #[Test]
    public function breakAndContinueInsideLoopAreAccepted(): void
    {
        $this->assertCompiles(
            'function main() { foreach ([1] as $value) { break; continue; } }',
        );
    }

    #[Test]
    public function readonlyContextVariableCannotBeAssigned(): void
    {
        $this->assertDiagnostic(
            'function main() { $input = 1; }',
            DiagnosticCode::READONLY_VARIABLE,
        );
    }

    #[Test]
    public function readonlyContextVariableCannotBeAParameter(): void
    {
        $this->assertDiagnostic(
            'function main($input) { }',
            DiagnosticCode::READONLY_VARIABLE,
        );
    }

    #[Test]
    public function readonlyContextVariableCannotBeALoopVariable(): void
    {
        $this->assertDiagnostic(
            'function main() { foreach ([1] as $input) { } }',
            DiagnosticCode::READONLY_VARIABLE,
        );
    }

    #[Test]
    public function duplicateFunctionIsRejected(): void
    {
        $this->assertDiagnostic(
            'function helper() { } function helper() { } function main() { }',
            DiagnosticCode::DUPLICATE_FUNCTION,
        );
    }

    #[Test]
    public function duplicateParameterIsRejected(): void
    {
        $this->assertDiagnostic(
            'function helper($a, $a) { } function main() { }',
            DiagnosticCode::DUPLICATE_PARAM,
        );
    }

    #[Test]
    public function validProgramWithUserAndBuiltinFunctionsCompiles(): void
    {
        $program = $this->assertCompiles(<<<'DSL'
            function displayName($user) {
                return $user->firstName . " " . $user->lastName;
            }

            function main() {
                $name = displayName($input->user);
                core::sendMailTemplate("meeting", $input->email, ["name" => $name]);
                $now = date::now();
                return ["name" => $name, "at" => $now];
            }
            DSL);

        self::assertCount(2, $program->functions);
    }

    #[Test]
    public function functionLimitIsEnforced(): void
    {
        $result = $this->compileWithLimits(
            'function helper() { } function main() { }',
            new Limits(maxFunctions: 1),
        );

        self::assertSame(DiagnosticCode::LIMIT_EXCEEDED, $result->diagnostics[0]->code);
    }

    #[Test]
    public function parameterLimitIsEnforced(): void
    {
        $result = $this->compileWithLimits(
            'function main($a, $b) { }',
            new Limits(maxParameters: 1),
        );

        self::assertSame(DiagnosticCode::LIMIT_EXCEEDED, $result->diagnostics[0]->code);
    }

    #[Test]
    public function sourceLimitIsEnforced(): void
    {
        $result = $this->compileWithLimits(
            'function main() { }',
            new Limits(maxSourceBytes: 10),
        );

        self::assertSame(DiagnosticCode::LIMIT_EXCEEDED, $result->diagnostics[0]->code);
    }

    #[Test]
    public function tokenLimitIsEnforced(): void
    {
        $result = $this->compileWithLimits(
            'function main() { }',
            new Limits(maxTokens: 3),
        );

        self::assertSame(DiagnosticCode::LIMIT_EXCEEDED, $result->diagnostics[0]->code);
    }

    private function compileWithLimits(string $source, Limits $limits): CompilationResult
    {
        return (new ScenarioCompiler(null, $limits))->compile($source);
    }
}
