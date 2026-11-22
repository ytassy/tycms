<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Runtime;

use YTassy\Tycms\CoreExts\Automation\Language\Ast\Expr\Access;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Expr\ArrayLiteral;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Expr\Binary;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Expr\Call;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Expr\Expression;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Expr\Index;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Expr\Literal;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Expr\Ternary;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Expr\Unary;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Expr\Variable;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\FunctionDecl;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Operator;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Program;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Stmt\AssignmentStatement;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Stmt\Block;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Stmt\BreakStatement;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Stmt\ContinueStatement;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Stmt\ExpressionStatement;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Stmt\FailStatement;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Stmt\ForStatement;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Stmt\ForeachStatement;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Stmt\IfStatement;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Stmt\ReturnStatement;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Stmt\Statement;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Stmt\StopStatement;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Stmt\WhileStatement;
use YTassy\Tycms\CoreExts\Automation\Language\Diagnostic;
use YTassy\Tycms\CoreExts\Automation\Language\DiagnosticCode;
use YTassy\Tycms\CoreExts\Automation\Language\Limits;
use YTassy\Tycms\CoreExts\Automation\Language\Registry\FunctionCatalog;
use YTassy\Tycms\CoreExts\Automation\Language\SourceSpan;
use YTassy\Tycms\CoreExts\Automation\Runtime\Builtin\BuiltinFunctionRegistry;
use YTassy\Tycms\CoreExts\Automation\Runtime\Environment\Environment;
use YTassy\Tycms\CoreExts\Automation\Runtime\Environment\ReadOnlyVariableException;
use YTassy\Tycms\CoreExts\Automation\Runtime\Environment\UndefinedVariableException;
use YTassy\Tycms\CoreExts\Automation\Runtime\Signal\BreakSignal;
use YTassy\Tycms\CoreExts\Automation\Runtime\Signal\ContinueSignal;
use YTassy\Tycms\CoreExts\Automation\Runtime\Signal\FailSignal;
use YTassy\Tycms\CoreExts\Automation\Runtime\Signal\ReturnSignal;
use YTassy\Tycms\CoreExts\Automation\Runtime\Signal\StopSignal;

/** Interpréteur du langage de scénarios. */
final class Interpreter
{
    private const READONLY_VARIABLES = ['input', 'principal', 'space', 'run'];

    /** @var array<string, FunctionDecl> */
    private array $functions = [];

    private Environment $contextEnv;
    private RuntimeServices $services;
    private int $callDepth = 0;

    public function __construct(
        private readonly FunctionCatalog $catalog,
        private readonly BuiltinFunctionRegistry $builtins,
        private readonly Limits $limits,
    ) {
    }

    public function execute(Program $program, ExecutionContext $context): ExecutionResult
    {
        $budget = new ExecutionBudget($this->limits);
        $journal = new ExecutionJournal($this->limits->maxJournalEntries);
        $this->services = new RuntimeServices($context, $budget, $journal, $this->limits);
        $this->functions = [];
        $this->callDepth = 0;

        foreach ($program->functions as $function) {
            $this->functions[$function->name] ??= $function;
        }

        $this->contextEnv = new Environment(null);

        try {
            foreach ([
                'input' => $context->input,
                'principal' => $context->principal,
                'space' => $context->space,
                'run' => $context->run,
            ] as $name => $value) {
                ValueTools::assertSafe($value, $this->limits, $program->span);
                $this->contextEnv->defineReadOnly($name, $value);
            }

            $main = $this->functions['main'] ?? null;

            if ($main === null) {
                return $this->failed(
                    new Diagnostic(DiagnosticCode::MISSING_MAIN, 'La fonction main() est absente.', $program->span),
                    $budget,
                    $journal,
                );
            }

            $output = $this->callUserFunction($main, [], $program->span);

            return $this->result(ExecutionStatus::SUCCESS, $output, null, [], $budget, $journal);
        } catch (StopSignal $signal) {
            $journal->add('stop', 'stop', $program->span, [], null, null, 'stopped');

            return $this->result(ExecutionStatus::STOPPED, null, $signal->reason, [], $budget, $journal);
        } catch (FailSignal $signal) {
            $diagnostic = new Diagnostic(DiagnosticCode::FAILED, $signal->reason, $signal->span);
            $journal->add('fail', 'fail', $signal->span, [], null, $diagnostic, 'failed');

            return $this->failed($diagnostic, $budget, $journal);
        } catch (ExecutionException $exception) {
            $journal->add('error', 'runtime', $exception->diagnostic->span, [], null, $exception->diagnostic, 'failed');

            return $this->failed($exception->diagnostic, $budget, $journal);
        } catch (ReturnSignal) {
            return $this->failed(
                new Diagnostic(DiagnosticCode::CONTROL_FLOW_ESCAPED, 'Retour de fonction inattendu.', $program->span),
                $budget,
                $journal,
            );
        } catch (BreakSignal|ContinueSignal) {
            return $this->failed(
                new Diagnostic(DiagnosticCode::CONTROL_FLOW_ESCAPED, 'Commande de boucle hors boucle.', $program->span),
                $budget,
                $journal,
            );
        }
    }

    private function callUserFunction(FunctionDecl $function, array $arguments, SourceSpan $callSpan): mixed
    {
        $this->services->budget->step($callSpan);
        $this->callDepth++;

        if ($this->callDepth > $this->limits->maxCallDepth) {
            $this->callDepth--;

            throw ValueTools::error(
                DiagnosticCode::CALL_DEPTH_EXCEEDED,
                'Profondeur d’appel dépassée (maximum ' . $this->limits->maxCallDepth . ').',
                $callSpan,
            );
        }

        try {
            if (count($arguments) !== count($function->parameters)) {
                throw ValueTools::error(
                    DiagnosticCode::INVALID_ARGUMENT,
                    'La fonction « ' . $function->name . ' » attend '
                        . count($function->parameters) . ' argument(s), ' . count($arguments) . ' fourni(s).',
                    $callSpan,
                );
            }

            $env = new Environment($this->contextEnv);

            foreach ($function->parameters as $index => $parameter) {
                $value = $arguments[$index] ?? null;
                $env->define($parameter->name, ValueTools::assertSafe($value, $this->limits, $callSpan));
            }

            try {
                $this->executeStatement($function->body, $env);
                $result = null;
            } catch (ReturnSignal $signal) {
                $result = $signal->value;
            }

            $result = ValueTools::assertSafe($result, $this->limits, $callSpan);
            $this->services->journal->add('function', $function->name, $callSpan, $arguments, $result);

            return $result;
        } catch (ExecutionException $exception) {
            $this->services->journal->add(
                'function',
                $function->name,
                $callSpan,
                $arguments,
                null,
                $exception->diagnostic,
                'failed',
            );

            throw $exception;
        } catch (BreakSignal|ContinueSignal) {
            $exception = ValueTools::error(
                DiagnosticCode::CONTROL_FLOW_ESCAPED,
                '« break » ou « continue » ne peut pas sortir d’une fonction.',
                $callSpan,
            );

            $this->services->journal->add('function', $function->name, $callSpan, $arguments, null, $exception->diagnostic, 'failed');

            throw $exception;
        } finally {
            $this->callDepth--;
        }
    }

    private function executeStatement(Statement $statement, Environment $env): void
    {
        $this->services->budget->step($statement->span);

        if ($statement instanceof Block) {
            $blockEnv = new Environment($env);

            foreach ($statement->statements as $child) {
                $this->executeStatement($child, $blockEnv);
            }

            return;
        }

        if ($statement instanceof IfStatement) {
            foreach ($statement->branches as $branch) {
                if ($this->evaluateCondition($branch->condition, $env)) {
                    $this->executeStatement($branch->body, $env);

                    return;
                }
            }

            if ($statement->else !== null) {
                $this->executeStatement($statement->else, $env);
            }

            return;
        }

        if ($statement instanceof ForeachStatement) {
            $this->executeForeach($statement, $env);

            return;
        }

        if ($statement instanceof ForStatement) {
            $this->executeFor($statement, $env);

            return;
        }

        if ($statement instanceof WhileStatement) {
            $this->executeWhile($statement, $env);

            return;
        }

        if ($statement instanceof ReturnStatement) {
            throw new ReturnSignal(
                $statement->value === null ? null : $this->evaluate($statement->value, $env),
            );
        }

        if ($statement instanceof BreakStatement) {
            throw new BreakSignal();
        }

        if ($statement instanceof ContinueStatement) {
            throw new ContinueSignal();
        }

        if ($statement instanceof StopStatement) {
            $reason = $statement->message === null
                ? null
                : ValueTools::assertString($this->evaluate($statement->message, $env), $statement->span);

            throw new StopSignal($reason);
        }

        if ($statement instanceof FailStatement) {
            $reason = ValueTools::assertString($this->evaluate($statement->message, $env), $statement->span);

            throw new FailSignal($reason, $statement->span);
        }

        if ($statement instanceof AssignmentStatement) {
            if (in_array($statement->variable, self::READONLY_VARIABLES, true)) {
                throw ValueTools::error(
                    DiagnosticCode::READONLY_VARIABLE,
                    'La variable $' . $statement->variable . ' est en lecture seule.',
                    $statement->span,
                );
            }

            $value = $this->evaluate($statement->value, $env);

            try {
                $env->assign($statement->variable, ValueTools::assertSafe($value, $this->limits, $statement->span));
            } catch (ReadOnlyVariableException) {
                throw ValueTools::error(
                    DiagnosticCode::READONLY_VARIABLE,
                    'La variable $' . $statement->variable . ' est en lecture seule.',
                    $statement->span,
                );
            }

            return;
        }

        if ($statement instanceof ExpressionStatement) {
            $this->evaluate($statement->expression, $env);

            return;
        }

        throw new \LogicException('Instruction inconnue : ' . $statement::class);
    }

    private function executeForeach(ForeachStatement $statement, Environment $env): void
    {
        $iterable = ValueTools::assertArray($this->evaluate($statement->iterable, $env), $statement->iterable->span);
        $iteration = 0;

        foreach ($iterable as $key => $value) {
            $this->services->budget->loopIteration($statement->span, ++$iteration);
            $iterationEnv = new Environment($env);

            if ($statement->keyVariable !== null && $statement->keyVariable !== $statement->valueVariable) {
                $iterationEnv->define($statement->keyVariable, $key);
            }

            $iterationEnv->define($statement->valueVariable, $value);

            try {
                $this->executeStatement($statement->body, $iterationEnv);
            } catch (ContinueSignal) {
                continue;
            } catch (BreakSignal) {
                break;
            }
        }
    }

    private function executeFor(ForStatement $statement, Environment $env): void
    {
        $loopEnv = new Environment($env);

        if ($statement->init !== null) {
            $this->executeStatement($statement->init, $loopEnv);
        }

        $iteration = 0;

        while (true) {
            if ($statement->condition !== null && !$this->evaluateCondition($statement->condition, $loopEnv)) {
                break;
            }

            $this->services->budget->loopIteration($statement->span, ++$iteration);
            $iterationEnv = new Environment($loopEnv);

            try {
                $this->executeStatement($statement->body, $iterationEnv);
            } catch (ContinueSignal) {
                // L'update reste exécuté, comme dans une boucle for classique.
            } catch (BreakSignal) {
                break;
            }

            if ($statement->update !== null) {
                $this->executeStatement($statement->update, $loopEnv);
            }
        }
    }

    private function executeWhile(WhileStatement $statement, Environment $env): void
    {
        $loopEnv = new Environment($env);
        $iteration = 0;

        while (true) {
            if (!$this->evaluateCondition($statement->condition, $loopEnv)) {
                break;
            }

            $this->services->budget->loopIteration($statement->span, ++$iteration);
            $iterationEnv = new Environment($loopEnv);

            try {
                $this->executeStatement($statement->body, $iterationEnv);
            } catch (ContinueSignal) {
                continue;
            } catch (BreakSignal) {
                break;
            }
        }
    }

    private function evaluate(Expression $expression, Environment $env): mixed
    {
        if ($expression instanceof Literal) {
            return $expression->value;
        }

        if ($expression instanceof Variable) {
            try {
                return $env->get($expression->name);
            } catch (UndefinedVariableException) {
                throw ValueTools::error(
                    DiagnosticCode::UNDEFINED_VARIABLE,
                    'Variable non définie : $' . $expression->name . '.',
                    $expression->span,
                );
            }
        }

        if ($expression instanceof Call) {
            return $this->evaluateCall($expression, $env);
        }

        if ($expression instanceof Index) {
            return $this->evaluateIndex($expression, $env);
        }

        if ($expression instanceof Access) {
            return $this->evaluateAccess($expression, $env);
        }

        if ($expression instanceof Unary) {
            return $this->evaluateUnary($expression, $env);
        }

        if ($expression instanceof Binary) {
            return $this->evaluateBinary($expression, $env);
        }

        if ($expression instanceof Ternary) {
            return $this->evaluateCondition($expression->condition, $env)
                ? $this->evaluate($expression->ifTrue, $env)
                : $this->evaluate($expression->ifFalse, $env);
        }

        if ($expression instanceof ArrayLiteral) {
            return $this->evaluateArray($expression, $env);
        }

        throw new \LogicException('Expression inconnue : ' . $expression::class);
    }

    private function evaluateCall(Call $call, Environment $env): mixed
    {
        $arguments = [];

        foreach ($call->arguments as $argument) {
            $arguments[] = ValueTools::assertSafe(
                $this->evaluate($argument, $env),
                $this->limits,
                $argument->span,
            );
        }

        $identifier = $call->identifier();

        if ($call->isUserFunction()) {
            $function = $this->functions[$call->name] ?? null;

            if ($function === null) {
                throw ValueTools::error(
                    DiagnosticCode::UNKNOWN_FUNCTION,
                    'Fonction utilisateur inconnue « ' . $call->name . ' ».',
                    $call->span,
                );
            }

            return $this->callUserFunction($function, $arguments, $call->span);
        }

        $signature = $this->catalog->find($identifier);

        if ($signature === null) {
            throw ValueTools::error(
                DiagnosticCode::UNKNOWN_FUNCTION,
                'Fonction inconnue « ' . $identifier . ' ».',
                $call->span,
            );
        }

        if (!$signature->accepts(count($arguments))) {
            throw ValueTools::error(
                DiagnosticCode::INVALID_ARGUMENT,
                'La fonction « ' . $identifier . ' » attend ' . $signature->describeArity()
                    . ', ' . count($arguments) . ' fourni(s).',
                $call->span,
            );
        }

        if ($this->builtins->has($identifier)) {
            return $this->callBuiltin($identifier, $arguments, $call->span);
        }

        return $this->callAction($identifier, $arguments, $call->span);
    }

    private function callBuiltin(string $identifier, array $arguments, SourceSpan $span): mixed
    {
        $this->services->budget->step($span);

        try {
            $result = $this->builtins->call($identifier, $arguments, $this->services, $span);
            $result = ValueTools::assertSafe($result, $this->limits, $span);
            $this->services->journal->add('builtin', $identifier, $span, $arguments, $result);

            return $result;
        } catch (ExecutionException $exception) {
            $this->services->journal->add('builtin', $identifier, $span, $arguments, null, $exception->diagnostic, 'failed');

            throw $exception;
        } catch (\Throwable) {
            $exception = ValueTools::error(
                DiagnosticCode::RUNTIME_ERROR,
                'La fonction « ' . $identifier . ' » a échoué.',
                $span,
            );

            $this->services->journal->add('builtin', $identifier, $span, $arguments, null, $exception->diagnostic, 'failed');

            throw $exception;
        }
    }

    private function callAction(string $identifier, array $arguments, SourceSpan $span): mixed
    {
        $this->services->budget->actionCall($span);

        try {
            $result = $this->services->context->actions->dispatch($identifier, $arguments, $span, $this->services);
            $result = ValueTools::assertSafe($result, $this->limits, $span);
            $this->services->journal->add('action', $identifier, $span, $arguments, $result);

            return $result;
        } catch (ExecutionException $exception) {
            $this->services->journal->add('action', $identifier, $span, $arguments, null, $exception->diagnostic, 'failed');

            throw $exception;
        } catch (\Throwable) {
            $exception = ValueTools::error(
                DiagnosticCode::ACTION_FAILED,
                'L’action « ' . $identifier . ' » a échoué.',
                $span,
            );

            $this->services->journal->add('action', $identifier, $span, $arguments, null, $exception->diagnostic, 'failed');

            throw $exception;
        }
    }

    private function evaluateIndex(Index $expression, Environment $env): mixed
    {
        $target = $this->evaluate($expression->target, $env);
        $index = $this->evaluate($expression->index, $env);

        if (!is_int($index) && !is_string($index)) {
            throw ValueTools::error(
                DiagnosticCode::TYPE_ERROR,
                'Index de tableau attendu (int|string), ' . ValueTools::type($index) . ' fourni.',
                $expression->span,
            );
        }

        if (!is_array($target)) {
            throw ValueTools::error(
                DiagnosticCode::TYPE_ERROR,
                'Indexation impossible sur une valeur de type ' . ValueTools::type($target) . '.',
                $expression->span,
            );
        }

        if (!array_key_exists($index, $target)) {
            throw ValueTools::error(
                DiagnosticCode::INDEX_OUT_OF_RANGE,
                'Index absent : ' . var_export($index, true) . '.',
                $expression->span,
            );
        }

        return $target[$index];
    }

    private function evaluateAccess(Access $expression, Environment $env): mixed
    {
        $target = $this->evaluate($expression->target, $env);

        if ($target === null && $expression->nullSafe) {
            return null;
        }

        if (!is_array($target)) {
            throw ValueTools::error(
                DiagnosticCode::TYPE_ERROR,
                'Accès « -> » impossible sur une valeur de type ' . ValueTools::type($target) . '.',
                $expression->span,
            );
        }

        if (!array_key_exists($expression->field, $target)) {
            if ($expression->nullSafe) {
                return null;
            }

            throw ValueTools::error(
                DiagnosticCode::MISSING_FIELD,
                'Champ absent : « ' . $expression->field . ' ».',
                $expression->span,
            );
        }

        return $target[$expression->field];
    }

    private function evaluateUnary(Unary $expression, Environment $env): mixed
    {
        $value = $this->evaluate($expression->operand, $env);

        if ($expression->operator === Operator::NOT) {
            if (!is_bool($value)) {
                throw ValueTools::error(
                    DiagnosticCode::TYPE_ERROR,
                    'L’opérateur « not » attend un booléen, ' . ValueTools::type($value) . ' fourni.',
                    $expression->span,
                );
            }

            return !$value;
        }

        $number = ValueTools::assertNumber($value, $expression->span);

        return ValueTools::assertSafe(
            $expression->operator === Operator::UNARY_MINUS ? -$number : $number,
            $this->limits,
            $expression->span,
        );
    }

    private function evaluateBinary(Binary $expression, Environment $env): mixed
    {
        $operator = $expression->operator;

        if ($operator === Operator::AND) {
            return $this->evaluateCondition($expression->left, $env)
                && $this->evaluateCondition($expression->right, $env);
        }

        if ($operator === Operator::OR) {
            return $this->evaluateCondition($expression->left, $env)
                || $this->evaluateCondition($expression->right, $env);
        }

        if ($operator === Operator::COALESCE) {
            $left = $this->evaluate($expression->left, $env);

            return $left === null ? $this->evaluate($expression->right, $env) : $left;
        }

        $left = $this->evaluate($expression->left, $env);
        $right = $this->evaluate($expression->right, $env);

        return $this->applyBinary($operator, $left, $right, $expression->span);
    }

    private function applyBinary(string $operator, mixed $left, mixed $right, SourceSpan $span): mixed
    {
        if (in_array($operator, [Operator::ADD, Operator::SUBTRACT, Operator::MULTIPLY, Operator::POWER], true)) {
            $leftNumber = ValueTools::assertNumber($left, $span);
            $rightNumber = ValueTools::assertNumber($right, $span);

            $result = match ($operator) {
                Operator::ADD => $leftNumber + $rightNumber,
                Operator::SUBTRACT => $leftNumber - $rightNumber,
                Operator::MULTIPLY => $leftNumber * $rightNumber,
                Operator::POWER => $leftNumber ** $rightNumber,
            };

            return ValueTools::assertSafe($result, $this->limits, $span);
        }

        if ($operator === Operator::DIVIDE) {
            $leftNumber = ValueTools::assertNumber($left, $span);
            $rightNumber = ValueTools::assertNumber($right, $span);

            if ($rightNumber == 0) {
                throw ValueTools::error(DiagnosticCode::DIVISION_BY_ZERO, 'Division par zéro.', $span);
            }

            return ValueTools::assertSafe($leftNumber / $rightNumber, $this->limits, $span);
        }

        if ($operator === Operator::MODULO) {
            $leftNumber = ValueTools::assertInt($left, $span);
            $rightNumber = ValueTools::assertInt($right, $span);

            if ($rightNumber === 0) {
                throw ValueTools::error(DiagnosticCode::DIVISION_BY_ZERO, 'Modulo par zéro.', $span);
            }

            return $leftNumber % $rightNumber;
        }

        if ($operator === Operator::CONCAT) {
            $result = ValueTools::toConcatString($left, $span) . ValueTools::toConcatString($right, $span);

            if (strlen($result) > $this->limits->maxStringBytes) {
                throw ValueTools::error(
                    DiagnosticCode::LIMIT_EXCEEDED,
                    'Taille de chaîne dépassée (maximum ' . $this->limits->maxStringBytes . ' octets).',
                    $span,
                );
            }

            return $result;
        }

        if (in_array($operator, [
            Operator::LESS,
            Operator::LESS_OR_EQUAL,
            Operator::GREATER,
            Operator::GREATER_OR_EQUAL,
        ], true)) {
            $comparison = ValueTools::compare($left, $right, $span);

            return match ($operator) {
                Operator::LESS => $comparison < 0,
                Operator::LESS_OR_EQUAL => $comparison <= 0,
                Operator::GREATER => $comparison > 0,
                Operator::GREATER_OR_EQUAL => $comparison >= 0,
            };
        }

        if ($operator === Operator::EQUAL) {
            return ValueTools::equals($left, $right);
        }

        if ($operator === Operator::NOT_EQUAL) {
            return !ValueTools::equals($left, $right);
        }

        if ($operator === Operator::IN) {
            if (!is_array($right)) {
                throw ValueTools::error(
                    DiagnosticCode::TYPE_ERROR,
                    'L’opérateur « in » attend un tableau à droite.',
                    $span,
                );
            }

            foreach ($right as $value) {
                if (ValueTools::equals($left, $value)) {
                    return true;
                }
            }

            return false;
        }

        throw new \LogicException('Opérateur inconnu : ' . $operator);
    }

    private function evaluateArray(ArrayLiteral $expression, Environment $env): array
    {
        $result = [];
        $index = 0;

        foreach ($expression->items as $item) {
            if ($expression->isMap) {
                if ($item->key === null) {
                    throw new \LogicException('Une map doit avoir une clé.');
                }

                $key = $this->evaluate($item->key, $env);

                if (!is_int($key) && !is_string($key)) {
                    throw ValueTools::error(
                        DiagnosticCode::TYPE_ERROR,
                        'Clé de tableau attendue (int|string), ' . ValueTools::type($key) . ' fourni.',
                        $item->span,
                    );
                }

                $result[$key] = ValueTools::assertSafe(
                    $this->evaluate($item->value, $env),
                    $this->limits,
                    $item->span,
                );
            } else {
                $result[$index++] = ValueTools::assertSafe(
                    $this->evaluate($item->value, $env),
                    $this->limits,
                    $item->span,
                );
            }
        }

        return ValueTools::assertSafe($result, $this->limits, $expression->span);
    }

    private function evaluateCondition(Expression $expression, Environment $env): bool
    {
        $value = $this->evaluate($expression, $env);

        if (!is_bool($value)) {
            throw ValueTools::error(
                DiagnosticCode::TYPE_ERROR,
                'Condition booléenne attendue, ' . ValueTools::type($value) . ' fourni.',
                $expression->span,
            );
        }

        return $value;
    }

    private function result(
        ExecutionStatus $status,
        mixed $output,
        ?string $message,
        array $diagnostics,
        ExecutionBudget $budget,
        ExecutionJournal $journal,
    ): ExecutionResult {
        return new ExecutionResult(
            $status,
            $output,
            $message,
            $diagnostics,
            $journal,
            $budget->steps(),
            $budget->loopIterations(),
            $budget->actionCalls(),
            $budget->elapsedSeconds(),
        );
    }

    private function failed(Diagnostic $diagnostic, ExecutionBudget $budget, ExecutionJournal $journal): ExecutionResult
    {
        return $this->result(ExecutionStatus::FAILED, null, $diagnostic->message, [$diagnostic], $budget, $journal);
    }
}
