<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Language;

use YTassy\Tycms\CoreExts\Automation\Language\Ast\Expr\Access;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Expr\ArrayLiteral;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Expr\Binary;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Expr\Call;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Expr\Expression;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Expr\Index;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Expr\Ternary;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Expr\Unary;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\FunctionDecl;
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
use YTassy\Tycms\CoreExts\Automation\Language\Registry\FunctionCatalog;

/** Contrôles statiques indépendants de l'exécution. */
final class Validator
{
    private const READONLY_VARIABLES = ['input', 'principal', 'space', 'run'];

    private readonly Limits $limits;

    public function __construct(
        private readonly FunctionCatalog $catalog,
        ?Limits $limits = null,
    ) {
        $this->limits = $limits ?? new Limits();
    }

    /** @return list<Diagnostic> */
    public function validate(Program $program): array
    {
        $diagnostics = [];
        $functions = [];
        $main = null;

        foreach ($program->functions as $function) {
            if ($function->name === 'main') {
                if ($main !== null) {
                    $diagnostics[] = new Diagnostic(
                        DiagnosticCode::DUPLICATE_MAIN,
                        'La fonction main() est déclarée plusieurs fois.',
                        $function->span,
                    );
                } else {
                    $main = $function;
                    $functions['main'] = $function;
                }

                continue;
            }

            if (isset($functions[$function->name])) {
                $diagnostics[] = new Diagnostic(
                    DiagnosticCode::DUPLICATE_FUNCTION,
                    'La fonction « ' . $function->name . ' » est déclarée plusieurs fois.',
                    $function->span,
                );
                continue;
            }

            $functions[$function->name] = $function;
        }

        if ($main === null) {
            $diagnostics[] = new Diagnostic(
                DiagnosticCode::MISSING_MAIN,
                'La fonction main() est obligatoire.',
                $program->span,
            );
        }

        if (count($program->functions) > $this->limits->maxFunctions) {
            $diagnostics[] = new Diagnostic(
                DiagnosticCode::LIMIT_EXCEEDED,
                'Nombre maximal de fonctions dépassé (maximum ' . $this->limits->maxFunctions . ').',
                $program->span,
            );
        }

        foreach ($program->functions as $function) {
            if (count($function->parameters) > $this->limits->maxParameters) {
                $diagnostics[] = new Diagnostic(
                    DiagnosticCode::LIMIT_EXCEEDED,
                    'Nombre maximal de paramètres dépassé dans « ' . $function->name
                        . ' » (maximum ' . $this->limits->maxParameters . ').',
                    $function->span,
                );
            }

            if ($function->name === 'main' && count($function->parameters) > 0) {
                $diagnostics[] = new Diagnostic(
                    DiagnosticCode::MAIN_WITH_PARAMS,
                    'La fonction main() ne doit pas avoir de paramètres.',
                    $function->span,
                );
            }

            $this->validateParameters($function, $diagnostics);
            $this->walkStatement($function->body, 0, $functions, $diagnostics);
        }

        return $diagnostics;
    }

    /** @param list<Diagnostic> $diagnostics */
    private function validateParameters(FunctionDecl $function, array &$diagnostics): void
    {
        $seen = [];

        foreach ($function->parameters as $parameter) {
            if (in_array($parameter->name, self::READONLY_VARIABLES, true)) {
                $diagnostics[] = new Diagnostic(
                    DiagnosticCode::READONLY_VARIABLE,
                    'Le paramètre $' . $parameter->name . ' est réservé au contexte d’exécution.',
                    $parameter->span,
                );
            }

            if (isset($seen[$parameter->name])) {
                $diagnostics[] = new Diagnostic(
                    DiagnosticCode::DUPLICATE_PARAM,
                    'Le paramètre $' . $parameter->name . ' est dupliqué.',
                    $parameter->span,
                );
            }

            $seen[$parameter->name] = true;
        }
    }

    /**
     * @param array<string, FunctionDecl> $functions
     * @param list<Diagnostic> $diagnostics
     */
    private function walkStatement(
        Statement $statement,
        int $loopDepth,
        array $functions,
        array &$diagnostics,
    ): void {
        if ($statement instanceof Block) {
            foreach ($statement->statements as $child) {
                $this->walkStatement($child, $loopDepth, $functions, $diagnostics);
            }

            return;
        }

        if ($statement instanceof IfStatement) {
            foreach ($statement->branches as $branch) {
                $this->walkExpression($branch->condition, $functions, $diagnostics);
                $this->walkStatement($branch->body, $loopDepth, $functions, $diagnostics);
            }

            if ($statement->else !== null) {
                $this->walkStatement($statement->else, $loopDepth, $functions, $diagnostics);
            }

            return;
        }

        if ($statement instanceof ForeachStatement) {
            $this->walkExpression($statement->iterable, $functions, $diagnostics);
            $this->validateLoopVariable($statement->keyVariable, $statement, $diagnostics);
            $this->validateLoopVariable($statement->valueVariable, $statement, $diagnostics);
            $this->walkStatement($statement->body, $loopDepth + 1, $functions, $diagnostics);

            return;
        }

        if ($statement instanceof ForStatement) {
            if ($statement->init !== null) {
                $this->walkStatement($statement->init, $loopDepth, $functions, $diagnostics);
            }

            if ($statement->condition !== null) {
                $this->walkExpression($statement->condition, $functions, $diagnostics);
            }

            if ($statement->update !== null) {
                $this->walkStatement($statement->update, $loopDepth, $functions, $diagnostics);
            }

            $this->walkStatement($statement->body, $loopDepth + 1, $functions, $diagnostics);

            return;
        }

        if ($statement instanceof WhileStatement) {
            $this->walkExpression($statement->condition, $functions, $diagnostics);
            $this->walkStatement($statement->body, $loopDepth + 1, $functions, $diagnostics);

            return;
        }

        if ($statement instanceof ReturnStatement) {
            if ($statement->value !== null) {
                $this->walkExpression($statement->value, $functions, $diagnostics);
            }

            return;
        }

        if ($statement instanceof BreakStatement) {
            if ($loopDepth === 0) {
                $diagnostics[] = new Diagnostic(
                    DiagnosticCode::BREAK_OUTSIDE_LOOP,
                    '« break » doit être placé dans une boucle.',
                    $statement->span,
                );
            }

            return;
        }

        if ($statement instanceof ContinueStatement) {
            if ($loopDepth === 0) {
                $diagnostics[] = new Diagnostic(
                    DiagnosticCode::CONTINUE_OUTSIDE_LOOP,
                    '« continue » doit être placé dans une boucle.',
                    $statement->span,
                );
            }

            return;
        }

        if ($statement instanceof StopStatement) {
            if ($statement->message !== null) {
                $this->walkExpression($statement->message, $functions, $diagnostics);
            }

            return;
        }

        if ($statement instanceof FailStatement) {
            $this->walkExpression($statement->message, $functions, $diagnostics);

            return;
        }

        if ($statement instanceof AssignmentStatement) {
            if (in_array($statement->variable, self::READONLY_VARIABLES, true)) {
                $diagnostics[] = new Diagnostic(
                    DiagnosticCode::READONLY_VARIABLE,
                    'La variable $' . $statement->variable . ' est en lecture seule.',
                    $statement->span,
                );
            }

            $this->walkExpression($statement->value, $functions, $diagnostics);

            return;
        }

        if ($statement instanceof ExpressionStatement) {
            $this->walkExpression($statement->expression, $functions, $diagnostics);
        }
    }

    /**
     * @param array<string, FunctionDecl> $functions
     * @param list<Diagnostic> $diagnostics
     */
    private function walkExpression(Expression $expression, array $functions, array &$diagnostics): void
    {
        if ($expression instanceof Call) {
            $this->checkCall($expression, $functions, $diagnostics);

            foreach ($expression->arguments as $argument) {
                $this->walkExpression($argument, $functions, $diagnostics);
            }

            return;
        }

        if ($expression instanceof Index) {
            $this->walkExpression($expression->target, $functions, $diagnostics);
            $this->walkExpression($expression->index, $functions, $diagnostics);

            return;
        }

        if ($expression instanceof Access) {
            $this->walkExpression($expression->target, $functions, $diagnostics);

            return;
        }

        if ($expression instanceof Unary) {
            $this->walkExpression($expression->operand, $functions, $diagnostics);

            return;
        }

        if ($expression instanceof Binary) {
            $this->walkExpression($expression->left, $functions, $diagnostics);
            $this->walkExpression($expression->right, $functions, $diagnostics);

            return;
        }

        if ($expression instanceof Ternary) {
            $this->walkExpression($expression->condition, $functions, $diagnostics);
            $this->walkExpression($expression->ifTrue, $functions, $diagnostics);
            $this->walkExpression($expression->ifFalse, $functions, $diagnostics);

            return;
        }

        if ($expression instanceof ArrayLiteral) {
            foreach ($expression->items as $item) {
                if ($item->key !== null) {
                    $this->walkExpression($item->key, $functions, $diagnostics);
                }

                $this->walkExpression($item->value, $functions, $diagnostics);
            }
        }
    }

    /** @param array<string, FunctionDecl> $functions @param list<Diagnostic> $diagnostics */
    private function checkCall(Call $call, array $functions, array &$diagnostics): void
    {
        if ($call->isUserFunction()) {
            if ($call->name === 'main') {
                $diagnostics[] = new Diagnostic(
                    DiagnosticCode::MAIN_CALLED_BY_CODE,
                    'main() est réservée au moteur et ne peut pas être appelée par le scénario.',
                    $call->span,
                );

                return;
            }

            $target = $functions[$call->name] ?? null;

            if ($target === null) {
                $diagnostics[] = new Diagnostic(
                    DiagnosticCode::UNKNOWN_FUNCTION,
                    'Fonction utilisateur inconnue « ' . $call->name . ' ».',
                    $call->span,
                );

                return;
            }

            $expected = count($target->parameters);
            $given = count($call->arguments);

            if ($given !== $expected) {
                $diagnostics[] = new Diagnostic(
                    DiagnosticCode::BAD_ARITY,
                    'La fonction « ' . $call->name . ' » attend ' . $expected . ' argument(s), '
                        . $given . ' fourni(s).',
                    $call->span,
                );
            }

            return;
        }

        $signature = $this->catalog->find($call->identifier());

        if ($signature === null) {
            $diagnostics[] = new Diagnostic(
                DiagnosticCode::UNKNOWN_FUNCTION,
                'Fonction inconnue « ' . $call->identifier() . ' ».',
                $call->span,
            );

            return;
        }

        if (!$signature->accepts(count($call->arguments))) {
            $diagnostics[] = new Diagnostic(
                DiagnosticCode::BAD_ARITY,
                'La fonction « ' . $call->identifier() . ' » attend '
                    . $signature->describeArity() . ', ' . count($call->arguments) . ' fourni(s).',
                $call->span,
            );
        }
    }

    /** @param list<Diagnostic> $diagnostics */
    private function validateLoopVariable(?string $variable, Statement $statement, array &$diagnostics): void
    {
        if ($variable === null || !in_array($variable, self::READONLY_VARIABLES, true)) {
            return;
        }

        $diagnostics[] = new Diagnostic(
            DiagnosticCode::READONLY_VARIABLE,
            'La variable de boucle $' . $variable . ' est réservée au contexte d’exécution.',
            $statement->span,
        );
    }
}
