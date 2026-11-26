<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\Automation;

use PHPUnit\Framework\Attributes\Test;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Expr\Access;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Expr\ArrayLiteral;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Expr\Binary;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Expr\Literal;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Expr\Unary;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Expr\Variable;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Stmt\AssignmentStatement;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Stmt\ForeachStatement;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Stmt\ForStatement;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Stmt\StopStatement;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Stmt\FailStatement;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Stmt\ReturnStatement;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Stmt\WhileStatement;
use YTassy\Tycms\CoreExts\Automation\Language\DiagnosticCode;

final class ParserTest extends LanguageTestCase
{
    #[Test]
    public function parsesFunctionsControlFlowAndActions(): void
    {
        $source = <<<'DSL'
            function displayName($user) {
                return $user->firstName . " " . $user->lastName;
            }

            function main() {
                if ($input?->teamId == null) {
                    fail("teamId manquant");
                }

                $users = workteam::getUsersOfTeam($input->teamId);

                foreach ($users as $user) {
                    core::sendMailTemplate("meeting-invite", $user->email, [
                        "firstName" => $user->firstName
                    ]);
                }

                return ["count" => arr::length($users)];
            }
            DSL;

        $parsed = $this->parse($source);

        self::assertSame([], $parsed->diagnostics);
        self::assertNotNull($parsed->program);
        self::assertCount(2, $parsed->program->functions);

        $main = $this->main($parsed->program);
        self::assertCount(4, $main->body->statements);

        $foreach = $main->body->statements[2];
        self::assertInstanceOf(ForeachStatement::class, $foreach);
        self::assertNull($foreach->keyVariable);
        self::assertSame('user', $foreach->valueVariable);

        $return = $main->body->statements[3];
        self::assertInstanceOf(ReturnStatement::class, $return);
        self::assertInstanceOf(ArrayLiteral::class, $return->value);
        self::assertTrue($return->value->isMap);
    }

    #[Test]
    public function appliesArithmeticPrecedence(): void
    {
        $program = $this->assertCompiles('function main() { $x = 1 + 2 * 3; }');
        $expression = $this->firstAssignment($program)->value;

        self::assertInstanceOf(Binary::class, $expression);
        self::assertSame('+', $expression->operator);
        self::assertInstanceOf(Literal::class, $expression->left);
        self::assertSame(1, $expression->left->value);
        self::assertInstanceOf(Binary::class, $expression->right);
        self::assertSame('*', $expression->right->operator);
    }

    #[Test]
    public function concatBindsLooserThanAddition(): void
    {
        $program = $this->assertCompiles('function main() { $x = "a" . 1 + 2; }');
        $expression = $this->firstAssignment($program)->value;

        self::assertInstanceOf(Binary::class, $expression);
        self::assertSame('.', $expression->operator);
        self::assertInstanceOf(Literal::class, $expression->left);
        self::assertSame('a', $expression->left->value);
        self::assertInstanceOf(Binary::class, $expression->right);
        self::assertSame('+', $expression->right->operator);
    }

    #[Test]
    public function notBindsLooserThanEquality(): void
    {
        $program = $this->assertCompiles('function main() { $x = not $a == $b; }');
        $expression = $this->firstAssignment($program)->value;

        self::assertInstanceOf(Unary::class, $expression);
        self::assertSame('not', $expression->operator);
        self::assertInstanceOf(Binary::class, $expression->operand);
        self::assertSame('==', $expression->operand->operator);
    }

    #[Test]
    public function powerIsRightAssociativeAndBindsTighterThanUnaryMinus(): void
    {
        $program = $this->assertCompiles('function main() { $x = -2 ** 2; $y = 2 ** 3 ** 2; }');

        $minus = $this->main($program)->body->statements[0];
        self::assertInstanceOf(AssignmentStatement::class, $minus);
        self::assertInstanceOf(Unary::class, $minus->value);
        self::assertSame('-', $minus->value->operator);
        self::assertInstanceOf(Binary::class, $minus->value->operand);
        self::assertSame('**', $minus->value->operand->operator);

        $power = $this->main($program)->body->statements[1];
        self::assertInstanceOf(AssignmentStatement::class, $power);
        self::assertInstanceOf(Binary::class, $power->value);
        self::assertSame('**', $power->value->operator);
        self::assertInstanceOf(Binary::class, $power->value->right);
        self::assertSame('**', $power->value->right->operator);
    }

    #[Test]
    public function nullSafeAccessAndCoalesceAreParsed(): void
    {
        $program = $this->assertCompiles('function main() { $x = $a?->b ?? "x"; }');
        $expression = $this->firstAssignment($program)->value;

        self::assertInstanceOf(Binary::class, $expression);
        self::assertSame('??', $expression->operator);
        self::assertInstanceOf(Access::class, $expression->left);
        self::assertTrue($expression->left->nullSafe);
        self::assertSame('b', $expression->left->field);
    }

    #[Test]
    public function arrayLiteralDistinguishesListAndMap(): void
    {
        $program = $this->assertCompiles(
            'function main() { $a = [1, 2]; $b = ["k" => 1]; $c = []; }',
        );

        $statements = $this->main($program)->body->statements;

        $list = $statements[0];
        self::assertInstanceOf(AssignmentStatement::class, $list);
        self::assertInstanceOf(ArrayLiteral::class, $list->value);
        self::assertFalse($list->value->isMap);
        self::assertCount(2, $list->value->items);
        self::assertNull($list->value->items[0]->key);
        self::assertSame(1, $list->value->items[0]->value->value);

        $map = $statements[1];
        self::assertInstanceOf(AssignmentStatement::class, $map);
        self::assertInstanceOf(ArrayLiteral::class, $map->value);
        self::assertTrue($map->value->isMap);
        self::assertInstanceOf(Literal::class, $map->value->items[0]->key);
        self::assertSame('k', $map->value->items[0]->key->value);

        $empty = $statements[2];
        self::assertInstanceOf(AssignmentStatement::class, $empty);
        self::assertInstanceOf(ArrayLiteral::class, $empty->value);
        self::assertFalse($empty->value->isMap);
        self::assertSame([], $empty->value->items);
    }

    #[Test]
    public function mixedArrayLiteralIsRejected(): void
    {
        $diagnostic = $this->assertDiagnostic(
            'function main() { $x = [1, "a" => 2]; }',
            DiagnosticCode::MIXED_ARRAY_LITERAL,
        );

        self::assertSame(1, $diagnostic->span->start->line);
    }

    #[Test]
    public function parsesForeachWithKeyAndValue(): void
    {
        $program = $this->assertCompiles('function main() { foreach ($map as $k => $v) { } }');
        $statement = $this->main($program)->body->statements[0];

        self::assertInstanceOf(ForeachStatement::class, $statement);
        self::assertSame('k', $statement->keyVariable);
        self::assertSame('v', $statement->valueVariable);
    }

    #[Test]
    public function parsesForLoopAndStopFail(): void
    {
        $program = $this->assertCompiles(
            'function main() { for ($i = 0; $i < 3; $i = $i + 1) { } stop("ok"); fail("bad"); }',
        );

        $statements = $this->main($program)->body->statements;

        $for = $statements[0];
        self::assertInstanceOf(ForStatement::class, $for);
        self::assertInstanceOf(AssignmentStatement::class, $for->init);
        self::assertInstanceOf(Binary::class, $for->condition);
        self::assertInstanceOf(AssignmentStatement::class, $for->update);

        $stop = $statements[1];
        self::assertInstanceOf(StopStatement::class, $stop);
        self::assertInstanceOf(Literal::class, $stop->message);
        self::assertSame('ok', $stop->message->value);

        $fail = $statements[2];
        self::assertInstanceOf(FailStatement::class, $fail);
        self::assertInstanceOf(Literal::class, $fail->message);
        self::assertSame('bad', $fail->message->value);
    }

    #[Test]
    public function parsesWhileLoop(): void
    {
        $program = $this->assertCompiles(
            'function main() { while ($x < 3) { $x = $x + 1; } }',
        );

        $statement = $this->main($program)->body->statements[0];
        self::assertInstanceOf(WhileStatement::class, $statement);
        self::assertInstanceOf(Binary::class, $statement->condition);
    }

    #[Test]
    public function invalidParameterSyntaxIsRejected(): void
    {
        $diagnostic = $this->assertDiagnostic(
            'function main($x, y) { }',
            DiagnosticCode::INVALID_PARAMETER,
        );

        self::assertSame(DiagnosticCode::INVALID_PARAMETER, $diagnostic->code);
    }

    #[Test]
    public function topLevelStatementIsRejected(): void
    {
        $diagnostic = $this->assertDiagnostic('$x = 1;', DiagnosticCode::TOP_LEVEL_STATEMENT);

        self::assertSame(DiagnosticCode::TOP_LEVEL_STATEMENT, $diagnostic->code);
    }

    #[Test]
    public function parserRecoversAtTheNextStatement(): void
    {
        $parsed = $this->parse(<<<'DSL'
            function main() {
                $x = ;
                $y = 1;
            }
            DSL);

        self::assertCount(1, $parsed->diagnostics);
        self::assertNotNull($parsed->program);
        self::assertCount(1, $parsed->program->functions);

        $statements = $this->main($parsed->program)->body->statements;
        self::assertCount(1, $statements);
        self::assertInstanceOf(AssignmentStatement::class, $statements[0]);
        self::assertSame('y', $statements[0]->variable);
    }

    #[Test]
    public function syntaxErrorCarriesTheTokenPosition(): void
    {
        $diagnostic = $this->assertDiagnostic(
            'function main() { $x = ; }',
            DiagnosticCode::SYNTAX_ERROR,
        );

        self::assertSame(1, $diagnostic->span->start->line);
        self::assertSame(24, $diagnostic->span->start->column);
    }

    #[Test]
    public function variableAccessOnACallIsParsed(): void
    {
        $parsed = $this->parse(
            'function main() { $x = workteam::getTeamInfos($input->teamId); $name = $x->name; }',
        );

        self::assertSame([], $parsed->diagnostics);
        self::assertNotNull($parsed->program);

        $statements = $this->main($parsed->program)->body->statements;
        self::assertInstanceOf(AssignmentStatement::class, $statements[1]);
        self::assertInstanceOf(Access::class, $statements[1]->value);
        self::assertSame('name', $statements[1]->value->field);
    }

    #[Test]
    public function unaryNotOnVariableIsParsed(): void
    {
        $program = $this->assertCompiles('function main() { $x = not $flag; }');
        $expression = $this->firstAssignment($program)->value;

        self::assertInstanceOf(Unary::class, $expression);
        self::assertSame('not', $expression->operator);
        self::assertInstanceOf(Variable::class, $expression->operand);
        self::assertSame('flag', $expression->operand->name);
    }
}
