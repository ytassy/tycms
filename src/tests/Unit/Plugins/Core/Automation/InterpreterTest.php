<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\Automation;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Test;
use YTassy\Tycms\CoreExts\Automation\Language\Diagnostic;
use YTassy\Tycms\CoreExts\Automation\Language\DiagnosticCode;
use YTassy\Tycms\CoreExts\Automation\Language\Limits;
use YTassy\Tycms\CoreExts\Automation\Language\Registry\BuiltinFunctionCatalog;
use YTassy\Tycms\CoreExts\Automation\Language\Registry\FunctionCatalog;
use YTassy\Tycms\CoreExts\Automation\Language\Registry\FunctionEffect;
use YTassy\Tycms\CoreExts\Automation\Language\Registry\FunctionSignature;
use YTassy\Tycms\CoreExts\Automation\Language\ScenarioCompiler;
use YTassy\Tycms\CoreExts\Automation\Runtime\Action\ActionDispatcher;
use YTassy\Tycms\CoreExts\Automation\Runtime\Builtin\BuiltinFunctionRegistry;
use YTassy\Tycms\CoreExts\Automation\Runtime\Clock\FixedClock;
use YTassy\Tycms\CoreExts\Automation\Runtime\Clock\RuntimeClock;
use YTassy\Tycms\CoreExts\Automation\Runtime\DateValue;
use YTassy\Tycms\CoreExts\Automation\Runtime\ExecutionContext;
use YTassy\Tycms\CoreExts\Automation\Runtime\ExecutionResult;
use YTassy\Tycms\CoreExts\Automation\Runtime\ExecutionStatus;
use YTassy\Tycms\CoreExts\Automation\Runtime\Identity\FixedIdGenerator;
use YTassy\Tycms\CoreExts\Automation\Runtime\Identity\IdGenerator;
use YTassy\Tycms\CoreExts\Automation\Runtime\Interpreter;
use YTassy\Tycms\CoreExts\Automation\Runtime\Random\FixedRandom;
use YTassy\Tycms\CoreExts\Automation\Runtime\Random\RandomSource;

final class InterpreterTest extends LanguageTestCase
{
    #[Test]
    public function executesArithmeticPrecedenceAndConcat(): void
    {
        $this->assertOutput(7, $this->runScenario('function main() { return 1 + 2 * 3; }'));
        $this->assertOutput('a1true', $this->runScenario('function main() { return "a" . 1 . true; }'));
    }

    #[Test]
    public function responseFunctionsReturnTheApiResponseShape(): void
    {
        $this->assertOutput([
            'error' => false,
            'is_message' => true,
            'message_level' => 'SUCCESS',
            'message' => 'Merci.',
        ], $this->runScenario('function main() { return res::confirm("Merci."); }'));

        $this->assertOutput([
            'error' => true,
            'is_message' => true,
            'message_level' => 'ERROR',
            'message' => 'Échec.',
        ], $this->runScenario('function main() { return res::error("Échec."); }'));
    }

    #[Test]
    public function conditionsAndArithmeticAreStrictlyTyped(): void
    {
        $this->assertFailure(
            DiagnosticCode::TYPE_ERROR,
            $this->runScenario('function main() { if ("x") { return 1; } return 0; }'),
        );
        $this->assertFailure(
            DiagnosticCode::TYPE_ERROR,
            $this->runScenario('function main() { return "1" + 1; }'),
        );
        $this->assertOutput(false, $this->runScenario('function main() { return 1 == "1"; }'));
    }

    #[Test]
    public function ifElseAndTernaryWork(): void
    {
        $this->assertOutput(
            'yes',
            $this->runScenario('function main() { if (true) { return "yes"; } else { return "no"; } }'),
        );
        $this->assertOutput(1, $this->runScenario('function main() { return true ? 1 : 2; }'));
    }

    #[Test]
    public function foreachHandlesContinueAndBreak(): void
    {
        $result = $this->runScenario(<<<'DSL'
            function main() {
                $total = 0;
                foreach ([1, 2, 3, 4] as $value) {
                    if ($value == 3) { continue; }
                    if ($value == 4) { break; }
                    $total = $total + $value;
                }
                return $total;
            }
            DSL);

        $this->assertOutput(3, $result);
    }

    #[Test]
    public function foreachMapExposesKeysAndValues(): void
    {
        $result = $this->runScenario(<<<'DSL'
            function main() {
                $keys = [];
                foreach (["a" => 1, "b" => 2] as $key => $value) {
                    $keys = arr::push($keys, $key . ":" . $value);
                }
                return $keys;
            }
            DSL);

        $this->assertOutput(['a:1', 'b:2'], $result);
    }

    #[Test]
    public function forAndWhileLoopsWork(): void
    {
        $this->assertOutput(10, $this->runScenario(<<<'DSL'
            function main() {
                $total = 0;
                for ($i = 0; $i < 5; $i = $i + 1) {
                    $total = $total + $i;
                }
                return $total;
            }
            DSL));

        $this->assertOutput(3, $this->runScenario(<<<'DSL'
            function main() {
                $i = 0;
                $total = 0;
                while ($i < 3) {
                    $total = $total + $i;
                    $i = $i + 1;
                }
                return $total;
            }
            DSL));
    }

    #[Test]
    public function functionsAreHoistedAndScoped(): void
    {
        $this->assertOutput(5, $this->runScenario(<<<'DSL'
            function add($left, $right) {
                return $left + $right;
            }
            function main() {
                return add(2, 3);
            }
            DSL));

        $this->assertOutput(120, $this->runScenario(<<<'DSL'
            function factorial($n) {
                if ($n <= 1) { return 1; }
                return $n * factorial($n - 1);
            }
            function main() {
                return factorial(5);
            }
            DSL));

        $this->assertFailure(DiagnosticCode::UNDEFINED_VARIABLE, $this->runScenario(<<<'DSL'
            function helper() {
                return $local;
            }
            function main() {
                $local = 1;
                return helper();
            }
            DSL));
    }

    #[Test]
    public function callDepthIsBounded(): void
    {
        $this->assertFailure(DiagnosticCode::CALL_DEPTH_EXCEEDED, $this->runScenario(<<<'DSL'
            function recurse($value) {
                return recurse($value);
            }
            function main() {
                return recurse(1);
            }
            DSL));
    }

    #[Test]
    public function builtinsAndArrayAccessWork(): void
    {
        $this->assertOutput('ABC-3-6', $this->runScenario(
            'function main() { return str::upper("abc") . "-" . arr::length([1, 2, 3]) . "-" . math::sum([1, 2, 3]); }',
        ));

        $this->assertOutput(2, $this->runScenario(
            'function main() { $data = ["x" => ["y" => 2]]; return $data->x->y; }',
        ));

        $this->assertOutput('fallback', $this->runScenario(
            'function main() { $data = null; return $data?->x ?? "fallback"; }',
        ));

        $this->assertFailure(DiagnosticCode::INDEX_OUT_OF_RANGE, $this->runScenario(
            'function main() { $data = [1, 2]; return $data[5]; }',
        ));

        $this->assertFailure(DiagnosticCode::MISSING_FIELD, $this->runScenario(
            'function main() { $data = ["x" => 1]; return $data->missing; }',
        ));
    }

    #[Test]
    public function dateFunctionsUseTheInjectedClock(): void
    {
        $clock = new FixedClock(new DateValue(new DateTimeImmutable(
            '2026-11-15 10:30:00',
            new DateTimeZone('Europe/Paris'),
        )));

        $this->assertOutput('2026-11-15', $this->runScenario(
            'function main() { return date::format(date::now(), "Y-m-d"); }',
            clock: $clock,
        ));

        $this->assertOutput('2026-11-16', $this->runScenario(
            'function main() { return date::format(date::addDays(date::parse("2026-11-15"), 1), "Y-m-d"); }',
        ));
    }

    #[Test]
    public function randomAndUuidUseInjectedSources(): void
    {
        $this->assertOutput(7, $this->runScenario(
            'function main() { return math::randomInt(1, 10); }',
            random: new FixedRandom(7),
        ));

        $this->assertOutput(0.5, $this->runScenario(
            'function main() { return math::randomFloat(); }',
            random: new FixedRandom(0, 0.5),
        ));

        $this->assertOutput('uuid-test', $this->runScenario(
            'function main() { return util::uuid(); }',
            ids: new FixedIdGenerator('uuid-test'),
        ));
    }

    #[Test]
    public function jsonEncodeAndDecodeWork(): void
    {
        $this->assertOutput(1, $this->runScenario(
            'function main() { $data = json::decode("{\"a\":1}"); return $data->a; }',
        ));

        $this->assertOutput('{"a":1}', $this->runScenario(
            'function main() { return json::encode(["a" => 1]); }',
        ));
    }

    #[Test]
    public function sortByAndColumnWork(): void
    {
        $result = $this->runScenario(<<<'DSL'
            function main() {
                $rows = [
                    ["name" => "b", "rank" => 2],
                    ["name" => "a", "rank" => 1]
                ];
                return arr::column(arr::sortBy($rows, "rank"), "name");
            }
            DSL);

        $this->assertOutput(['a', 'b'], $result);
    }

    #[Test]
    public function actionsAreDispatchedAndJournaled(): void
    {
        $dispatcher = new RecordingActionDispatcher(['test::action' => 'ok']);
        $result = $this->runScenario(
            'function main() { return test::action("x"); }',
            catalog: $this->actionCatalog(),
            actions: $dispatcher,
        );

        $this->assertOutput('ok', $result);
        self::assertCount(1, $dispatcher->calls);
        self::assertSame('test::action', $dispatcher->calls[0]['identifier']);
        self::assertSame(['x'], $dispatcher->calls[0]['arguments']);

        $kinds = array_map(static fn ($step): string => $step->kind, $result->journal->all());
        self::assertContains('action', $kinds);
    }

    #[Test]
    public function unavailableActionFailsCleanly(): void
    {
        $this->assertFailure(DiagnosticCode::ACTION_UNAVAILABLE, $this->runScenario(
            'function main() { core::sendMailTemplate("template", "to@example.test", []); }',
        ));
    }

    #[Test]
    public function actionReturningAnObjectIsRejectedByTheValueBoundary(): void
    {
        $dispatcher = new RecordingActionDispatcher(['test::action' => new \stdClass()]);
        $this->assertFailure(DiagnosticCode::INVALID_VALUE, $this->runScenario(
            'function main() { return test::action(); }',
            catalog: $this->actionCatalog(),
            actions: $dispatcher,
        ));
    }

    #[Test]
    public function unsafeContextInputIsRejected(): void
    {
        $this->assertFailure(DiagnosticCode::INVALID_VALUE, $this->runScenario(
            'function main() { return 1; }',
            input: ['bad' => new \stdClass()],
        ));
    }

    #[Test]
    public function stopAndFailHaveDistinctStatuses(): void
    {
        $stopped = $this->runScenario('function main() { stop("ok"); }');
        self::assertSame(ExecutionStatus::STOPPED, $stopped->status);
        self::assertSame('ok', $stopped->message);
        self::assertTrue($stopped->isSuccessful());

        $failed = $this->runScenario('function main() { fail("bad"); }');
        self::assertSame(ExecutionStatus::FAILED, $failed->status);
        self::assertSame(DiagnosticCode::FAILED, $failed->diagnostics[0]->code);
        self::assertSame('bad', $failed->message);
    }

    #[Test]
    public function loopStepAndDurationBudgetsAreEnforced(): void
    {
        $this->assertFailure(DiagnosticCode::LOOP_LIMIT_EXCEEDED, $this->runScenario(
            'function main() { while (true) { } }',
            limits: new Limits(maxLoopIterationsPerLoop: 3),
        ));

        $this->assertFailure(DiagnosticCode::STEP_LIMIT_EXCEEDED, $this->runScenario(
            'function main() { $a = 1; $b = 2; $c = 3; }',
            limits: new Limits(maxSteps: 2),
        ));

        $this->assertFailure(DiagnosticCode::DURATION_EXCEEDED, $this->runScenario(
            'function main() { return 1; }',
            limits: new Limits(maxRunSeconds: -1.0),
        ));
    }

    #[Test]
    public function actionCallBudgetIsEnforced(): void
    {
        $this->assertFailure(DiagnosticCode::LIMIT_EXCEEDED, $this->runScenario(
            'function main() { test::action(); }',
            catalog: $this->actionCatalog(),
            actions: new RecordingActionDispatcher(),
            limits: new Limits(maxActionCalls: 0),
        ));
    }

    #[Test]
    public function divisionByZeroAndUndefinedVariableAreReported(): void
    {
        $this->assertFailure(
            DiagnosticCode::DIVISION_BY_ZERO,
            $this->runScenario('function main() { return 1 / 0; }'),
        );

        $this->assertFailure(
            DiagnosticCode::UNDEFINED_VARIABLE,
            $this->runScenario('function main() { return $missing; }'),
        );
    }

    #[Test]
    public function journalRecordsFunctionsBuiltinsAndLogs(): void
    {
        $result = $this->runScenario(<<<'DSL'
            function helper() {
                return 1;
            }
            function main() {
                util::log("journal");
                return helper() + str::length("a");
            }
            DSL);

        $this->assertOutput(2, $result);

        $entries = $result->journal->all();
        $kinds = array_map(static fn ($step): string => $step->kind, $entries);
        self::assertContains('function', $kinds);
        self::assertContains('builtin', $kinds);
        self::assertContains('log', $kinds);
    }

    private function assertOutput(mixed $expected, ExecutionResult $result): void
    {
        self::assertTrue($result->isSuccessful(), $this->diagnosticText($result));
        self::assertSame($expected, $result->output);
    }

    private function assertFailure(string $code, ExecutionResult $result): void
    {
        self::assertSame(ExecutionStatus::FAILED, $result->status, $this->diagnosticText($result));
        self::assertNotEmpty($result->diagnostics, 'Aucun diagnostic retourné.');
        self::assertSame($code, $result->diagnostics[0]->code, $this->diagnosticText($result));
    }

    private function diagnosticText(ExecutionResult $result): string
    {
        return implode(' | ', array_map(
            static fn (Diagnostic $diagnostic): string => (string) $diagnostic,
            $result->diagnostics,
        ));
    }

    /**
     * @param array<mixed> $input
     * @param array<mixed> $principal
     * @param array<mixed> $space
     * @param array<mixed> $run
     */
    private function runScenario(
        string $source,
        array $input = [],
        array $principal = [],
        array $space = [],
        array $run = [],
        ?FunctionCatalog $catalog = null,
        ?ActionDispatcher $actions = null,
        ?RuntimeClock $clock = null,
        ?RandomSource $random = null,
        ?IdGenerator $ids = null,
        ?Limits $limits = null,
    ): ExecutionResult {
        $limits ??= new Limits();
        $catalog ??= BuiltinFunctionCatalog::create();

        $compiler = new ScenarioCompiler($catalog, $limits);
        $compiled = $compiler->compile($source);

        self::assertSame(
            [],
            array_map(static fn (Diagnostic $diagnostic): string => (string) $diagnostic, $compiled->diagnostics),
            'Compilation échouée.',
        );
        self::assertNotNull($compiled->program);

        $interpreter = new Interpreter($catalog, new BuiltinFunctionRegistry(), $limits);
        $context = ExecutionContext::create($input, $principal, $space, $run, $actions, $clock, $random, $ids);

        return $interpreter->execute($compiled->program, $context);
    }

    private function actionCatalog(): FunctionCatalog
    {
        return new class implements FunctionCatalog {
            /** @var array<string, FunctionSignature> */
            private array $functions;

            public function __construct()
            {
                $this->functions = BuiltinFunctionCatalog::create()->all();
                $this->functions['test::action'] = new FunctionSignature(
                    'test::action',
                    0,
                    1,
                    FunctionEffect::EXTERNAL,
                );
            }

            public function find(string $identifier): ?FunctionSignature
            {
                return $this->functions[$identifier] ?? null;
            }
        };
    }
}
