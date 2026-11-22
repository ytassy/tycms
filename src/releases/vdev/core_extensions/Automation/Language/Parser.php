<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Language;

use YTassy\Tycms\CoreExts\Automation\Language\Ast\Expr\Access;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Expr\ArrayItem;
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
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Parameter;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Program;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Stmt\AssignmentStatement;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Stmt\Block;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Stmt\BreakStatement;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Stmt\ContinueStatement;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Stmt\ExpressionStatement;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Stmt\FailStatement;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Stmt\ForStatement;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Stmt\ForeachStatement;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Stmt\IfBranch;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Stmt\IfStatement;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Stmt\ReturnStatement;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Stmt\Statement;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Stmt\StopStatement;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Stmt\WhileStatement;

/**
 * Parseur de descente récursive avec précédence pour les expressions.
 *
 * Le programme est une suite de déclarations de fonctions ; `main` est
 * contrôlée par le validateur. Les erreurs sont collectées avec reprise au
 * niveau des instructions ou de la déclaration suivante.
 */
final class Parser
{
    /** @var list<Token> */
    private array $tokens = [];

    private int $current = 0;
    private int $expressionDepth = 0;
    private int $blockDepth = 0;
    private Limits $limits;

    /** @var list<Diagnostic> */
    private array $diagnostics = [];

    public function __construct(
        private readonly Lexer $lexer = new Lexer(),
        ?Limits $limits = null,
    ) {
        $this->limits = $limits ?? new Limits();
    }

    public function parse(string $source): ParseResult
    {
        $this->current = 0;
        $this->expressionDepth = 0;
        $this->blockDepth = 0;
        $this->diagnostics = [];

        try {
            $this->tokens = $this->lexer->tokenize($source, $this->limits);
        } catch (SyntaxException $exception) {
            return new ParseResult(null, [$exception->diagnostic]);
        }

        $program = $this->parseProgram();

        return new ParseResult($program, $this->diagnostics);
    }

    private function parseProgram(): Program
    {
        $functions = [];
        $start = $this->peek()->span->start;

        while (!$this->isAtEnd()) {
            try {
                if (!$this->check(TokenType::FUNCTION)) {
                    throw $this->errorAtToken(
                        DiagnosticCode::TOP_LEVEL_STATEMENT,
                        'Seule une déclaration de fonction est autorisée au niveau racine.',
                        $this->peek(),
                    );
                }

                $functions[] = $this->parseFunctionDeclaration();

                if (count($functions) > $this->limits->maxFunctions) {
                    throw $this->errorAtToken(
                        DiagnosticCode::LIMIT_EXCEEDED,
                        'Nombre maximal de fonctions dépassé (maximum ' . $this->limits->maxFunctions . ').',
                        $this->peek(),
                    );
                }
            } catch (SyntaxException $exception) {
                $this->diagnostics[] = $exception->diagnostic;

                if ($exception->diagnostic->code === DiagnosticCode::LIMIT_EXCEEDED) {
                    break;
                }

                $this->synchronizeTopLevel();
            }
        }

        return new Program($functions, new SourceSpan($start, $this->peek()->span->end));
    }

    private function parseFunctionDeclaration(): FunctionDecl
    {
        $start = $this->consume(TokenType::FUNCTION, '« function » attendu.')->span->start;
        $nameToken = $this->consume(TokenType::IDENTIFIER, 'Nom de fonction attendu.');
        $this->consume(TokenType::LPAREN, '« ( » attendu après le nom de fonction.');

        $parameters = [];

        if (!$this->check(TokenType::RPAREN)) {
            do {
                if (!$this->check(TokenType::VARIABLE)) {
                    throw $this->errorAtToken(
                        DiagnosticCode::INVALID_PARAMETER,
                        'Paramètre attendu sous la forme $nom.',
                        $this->peek(),
                    );
                }

                $parameter = $this->advance();
                $parameters[] = new Parameter((string) $parameter->literal, $parameter->span);

                if (count($parameters) > $this->limits->maxParameters) {
                    throw $this->errorAtToken(
                        DiagnosticCode::LIMIT_EXCEEDED,
                        'Nombre maximal de paramètres dépassé (maximum ' . $this->limits->maxParameters . ').',
                        $parameter,
                    );
                }
            } while ($this->consumeIf(TokenType::COMMA));
        }

        $this->consume(TokenType::RPAREN, '« ) » attendu après les paramètres.');

        $body = $this->parseBlock();

        return new FunctionDecl(
            (string) $nameToken->literal,
            $parameters,
            $body,
            new SourceSpan($start, $body->span->end),
        );
    }

    private function parseBlock(): Block
    {
        $open = $this->consume(TokenType::LBRACE, '« { » attendu.');
        $this->blockDepth++;

        if ($this->blockDepth > $this->limits->maxBlockDepth) {
            $this->blockDepth--;
            throw $this->errorAtToken(
                DiagnosticCode::LIMIT_EXCEEDED,
                'Profondeur de blocs dépassée (maximum ' . $this->limits->maxBlockDepth . ').',
                $open,
            );
        }

        $statements = [];

        try {
            while (!$this->check(TokenType::RBRACE) && !$this->isAtEnd()) {
                try {
                    $statements[] = $this->parseStatement();
                } catch (SyntaxException $exception) {
                    if ($exception->diagnostic->code === DiagnosticCode::LIMIT_EXCEEDED) {
                        throw $exception;
                    }

                    $this->diagnostics[] = $exception->diagnostic;
                    $this->synchronizeBlock();
                }
            }

            $close = $this->consume(TokenType::RBRACE, '« } » attendu pour fermer le bloc.');

            return new Block($statements, new SourceSpan($open->span->start, $close->span->end));
        } finally {
            $this->blockDepth--;
        }
    }

    private function parseStatement(): Statement
    {
        if ($this->check(TokenType::LBRACE)) {
            return $this->parseBlock();
        }

        if ($this->check(TokenType::IF)) {
            return $this->parseIf();
        }

        if ($this->check(TokenType::FOREACH)) {
            return $this->parseForeach();
        }

        if ($this->check(TokenType::FOR)) {
            return $this->parseFor();
        }

        if ($this->check(TokenType::WHILE)) {
            return $this->parseWhile();
        }

        if ($this->check(TokenType::RETURN)) {
            return $this->parseReturn();
        }

        if ($this->check(TokenType::BREAK)) {
            return $this->parseBreak();
        }

        if ($this->check(TokenType::CONTINUE)) {
            return $this->parseContinue();
        }

        if ($this->check(TokenType::STOP)) {
            return $this->parseStop();
        }

        if ($this->check(TokenType::FAIL)) {
            return $this->parseFail();
        }

        if ($this->check(TokenType::VARIABLE) && $this->checkNext(TokenType::ASSIGN)) {
            $assignment = $this->parseSimpleAssignment();
            $this->consume(TokenType::SEMICOLON, '« ; » attendu après l’affectation.');

            return new AssignmentStatement(
                $assignment->variable,
                $assignment->value,
                new SourceSpan($assignment->span->start, $this->previous()->span->end),
            );
        }

        return $this->parseExpressionStatement();
    }

    private function parseIf(): IfStatement
    {
        $start = $this->consume(TokenType::IF, '« if » attendu.')->span->start;
        $branches = [];

        $condition = $this->parseCondition();
        $body = $this->parseStatement();
        $branches[] = new IfBranch($condition, $body, $condition->span->merge($body->span));

        while ($this->consumeIf(TokenType::ELSEIF)) {
            $condition = $this->parseCondition();
            $body = $this->parseStatement();
            $branches[] = new IfBranch($condition, $body, $condition->span->merge($body->span));
        }

        $else = null;

        if ($this->consumeIf(TokenType::ELSE)) {
            $else = $this->parseStatement();
        }

        $end = $else?->span->end ?? $branches[array_key_last($branches)]->span->end;

        return new IfStatement($branches, $else, new SourceSpan($start, $end));
    }

    private function parseForeach(): ForeachStatement
    {
        $start = $this->consume(TokenType::FOREACH, '« foreach » attendu.')->span->start;
        $this->consume(TokenType::LPAREN, '« ( » attendu après foreach.');

        $iterable = $this->parseExpression();

        $this->consume(TokenType::AS, '« as » attendu dans foreach.');

        $first = $this->consume(TokenType::VARIABLE, 'Variable de boucle attendue.');
        $key = null;
        $value = (string) $first->literal;

        if ($this->consumeIf(TokenType::FAT_ARROW)) {
            $key = $value;
            $second = $this->consume(TokenType::VARIABLE, 'Variable de valeur attendue après « => ».');
            $value = (string) $second->literal;
        }

        $this->consume(TokenType::RPAREN, '« ) » attendu après foreach.');
        $body = $this->parseStatement();

        return new ForeachStatement(
            $iterable,
            $key,
            $value,
            $body,
            new SourceSpan($start, $body->span->end),
        );
    }

    private function parseFor(): ForStatement
    {
        $start = $this->consume(TokenType::FOR, '« for » attendu.')->span->start;
        $this->consume(TokenType::LPAREN, '« ( » attendu après for.');

        $init = null;
        if (!$this->check(TokenType::SEMICOLON)) {
            $init = $this->parseSimpleAssignment();
        }
        $this->consume(TokenType::SEMICOLON, '« ; » attendu après l’initialisation de for.');

        $condition = null;
        if (!$this->check(TokenType::SEMICOLON)) {
            $condition = $this->parseExpression();
        }
        $this->consume(TokenType::SEMICOLON, '« ; » attendu après la condition de for.');

        $update = null;
        if (!$this->check(TokenType::RPAREN)) {
            $update = $this->parseSimpleAssignment();
        }
        $this->consume(TokenType::RPAREN, '« ) » attendu après for.');

        $body = $this->parseStatement();

        return new ForStatement($init, $condition, $update, $body, new SourceSpan($start, $body->span->end));
    }

    private function parseWhile(): WhileStatement
    {
        $start = $this->consume(TokenType::WHILE, '« while » attendu.')->span->start;
        $condition = $this->parseCondition();
        $body = $this->parseStatement();

        return new WhileStatement($condition, $body, new SourceSpan($start, $body->span->end));
    }

    private function parseReturn(): ReturnStatement
    {
        $start = $this->consume(TokenType::RETURN, '« return » attendu.')->span->start;
        $value = $this->check(TokenType::SEMICOLON) ? null : $this->parseExpression();
        $this->consume(TokenType::SEMICOLON, '« ; » attendu après return.');

        return new ReturnStatement($value, new SourceSpan($start, $this->previous()->span->end));
    }

    private function parseBreak(): BreakStatement
    {
        $start = $this->consume(TokenType::BREAK, '« break » attendu.')->span->start;
        $this->consume(TokenType::SEMICOLON, '« ; » attendu après break.');

        return new BreakStatement(new SourceSpan($start, $this->previous()->span->end));
    }

    private function parseContinue(): ContinueStatement
    {
        $start = $this->consume(TokenType::CONTINUE, '« continue » attendu.')->span->start;
        $this->consume(TokenType::SEMICOLON, '« ; » attendu après continue.');

        return new ContinueStatement(new SourceSpan($start, $this->previous()->span->end));
    }

    private function parseStop(): StopStatement
    {
        $start = $this->consume(TokenType::STOP, '« stop » attendu.')->span->start;
        $message = null;

        if ($this->consumeIf(TokenType::LPAREN)) {
            $message = $this->parseExpression();
            $this->consume(TokenType::RPAREN, '« ) » attendu après stop.');
        }

        $this->consume(TokenType::SEMICOLON, '« ; » attendu après stop.');

        return new StopStatement($message, new SourceSpan($start, $this->previous()->span->end));
    }

    private function parseFail(): FailStatement
    {
        $start = $this->consume(TokenType::FAIL, '« fail » attendu.')->span->start;
        $this->consume(TokenType::LPAREN, '« ( » attendu après fail.');
        $message = $this->parseExpression();
        $this->consume(TokenType::RPAREN, '« ) » attendu après le message de fail.');
        $this->consume(TokenType::SEMICOLON, '« ; » attendu après fail.');

        return new FailStatement($message, new SourceSpan($start, $this->previous()->span->end));
    }

    private function parseSimpleAssignment(): AssignmentStatement
    {
        $variable = $this->consume(TokenType::VARIABLE, 'Variable attendue à gauche de l’affectation.');
        $this->consume(TokenType::ASSIGN, '« = » attendu après la variable.');
        $value = $this->parseExpression();

        return new AssignmentStatement(
            (string) $variable->literal,
            $value,
            new SourceSpan($variable->span->start, $value->span->end),
        );
    }

    private function parseExpressionStatement(): ExpressionStatement
    {
        $expression = $this->parseExpression();
        $this->consume(TokenType::SEMICOLON, '« ; » attendu après l’expression.');

        return new ExpressionStatement(
            $expression,
            new SourceSpan($expression->span->start, $this->previous()->span->end),
        );
    }

    private function parseCondition(): Expression
    {
        $this->consume(TokenType::LPAREN, '« ( » attendu avant la condition.');
        $condition = $this->parseExpression();
        $this->consume(TokenType::RPAREN, '« ) » attendu après la condition.');

        return $condition;
    }

    private function parseExpression(): Expression
    {
        $this->expressionDepth++;

        if ($this->expressionDepth > $this->limits->maxAstDepth) {
            $this->expressionDepth--;
            throw $this->errorAtToken(
                DiagnosticCode::LIMIT_EXCEEDED,
                'Profondeur d’expression dépassée (maximum ' . $this->limits->maxAstDepth . ').',
                $this->peek(),
            );
        }

        try {
            return $this->parseTernary();
        } finally {
            $this->expressionDepth--;
        }
    }

    private function parseTernary(): Expression
    {
        $condition = $this->parseCoalesce();

        if (!$this->consumeIf(TokenType::QUESTION)) {
            return $condition;
        }

        $ifTrue = $this->parseExpression();
        $this->consume(TokenType::COLON, '« : » attendu dans l’expression ternaire.');
        $ifFalse = $this->parseExpression();

        return new Ternary($condition, $ifTrue, $ifFalse, $condition->span->merge($ifFalse->span));
    }

    private function parseCoalesce(): Expression
    {
        $left = $this->parseOr();

        while ($this->check(TokenType::COALESCE)) {
            $operator = $this->advance();
            $left = $this->binary($left, $operator, $this->parseOr());
        }

        return $left;
    }

    private function parseOr(): Expression
    {
        $left = $this->parseAnd();

        while ($this->check(TokenType::OR)) {
            $operator = $this->advance();
            $left = $this->binary($left, $operator, $this->parseAnd());
        }

        return $left;
    }

    private function parseAnd(): Expression
    {
        $left = $this->parseNot();

        while ($this->check(TokenType::AND)) {
            $operator = $this->advance();
            $left = $this->binary($left, $operator, $this->parseNot());
        }

        return $left;
    }

    private function parseNot(): Expression
    {
        if ($this->check(TokenType::NOT)) {
            $operator = $this->advance();
            $operand = $this->parseNot();

            return new Unary(
                Operator::NOT,
                $operand,
                $operator->span->merge($operand->span),
            );
        }

        return $this->parseEquality();
    }

    private function parseEquality(): Expression
    {
        $left = $this->parseComparison();

        if ($this->check(TokenType::EQ) || $this->check(TokenType::NEQ)) {
            $operator = $this->advance();
            $left = $this->binary($left, $operator, $this->parseComparison());
        }

        return $left;
    }

    private function parseComparison(): Expression
    {
        $left = $this->parseConcat();

        if ($this->check(TokenType::LT)
            || $this->check(TokenType::LTE)
            || $this->check(TokenType::GT)
            || $this->check(TokenType::GTE)
            || $this->check(TokenType::IN)) {
            $operator = $this->advance();
            $left = $this->binary($left, $operator, $this->parseConcat());
        }

        return $left;
    }

    private function parseConcat(): Expression
    {
        $left = $this->parseAdditive();

        while ($this->check(TokenType::DOT)) {
            $operator = $this->advance();
            $left = $this->binary($left, $operator, $this->parseAdditive());
        }

        return $left;
    }

    private function parseAdditive(): Expression
    {
        $left = $this->parseMultiplicative();

        while ($this->check(TokenType::PLUS) || $this->check(TokenType::MINUS)) {
            $operator = $this->advance();
            $left = $this->binary($left, $operator, $this->parseMultiplicative());
        }

        return $left;
    }

    private function parseMultiplicative(): Expression
    {
        $left = $this->parseUnary();

        while ($this->check(TokenType::STAR)
            || $this->check(TokenType::SLASH)
            || $this->check(TokenType::PERCENT)) {
            $operator = $this->advance();
            $left = $this->binary($left, $operator, $this->parseUnary());
        }

        return $left;
    }

    private function parseUnary(): Expression
    {
        if ($this->check(TokenType::MINUS) || $this->check(TokenType::PLUS)) {
            $operator = $this->advance();
            $operand = $this->parseUnary();
            $constant = $operator->type === TokenType::MINUS
                ? Operator::UNARY_MINUS
                : Operator::UNARY_PLUS;

            return new Unary($constant, $operand, $operator->span->merge($operand->span));
        }

        return $this->parsePower();
    }

    private function parsePower(): Expression
    {
        $left = $this->parsePostfix();

        if ($this->check(TokenType::POWER)) {
            $operator = $this->advance();
            $left = $this->binary($left, $operator, $this->parseUnary());
        }

        return $left;
    }

    private function parsePostfix(): Expression
    {
        $expression = $this->parsePrimary();

        while (true) {
            if ($this->consumeIf(TokenType::LBRACKET)) {
                $index = $this->parseExpression();
                $close = $this->consume(TokenType::RBRACKET, '« ] » attendu après l’index.');
                $expression = new Index(
                    $expression,
                    $index,
                    new SourceSpan($expression->span->start, $close->span->end),
                );
                continue;
            }

            if ($this->check(TokenType::ARROW) || $this->check(TokenType::QUESTION_ARROW)) {
                $operator = $this->advance();
                $field = $this->consume(TokenType::IDENTIFIER, 'Nom de champ attendu après « -> ».');
                $expression = new Access(
                    $expression,
                    (string) $field->literal,
                    $operator->type === TokenType::QUESTION_ARROW,
                    new SourceSpan($expression->span->start, $field->span->end),
                );
                continue;
            }

            break;
        }

        return $expression;
    }

    private function parsePrimary(): Expression
    {
        if ($this->check(TokenType::INT)
            || $this->check(TokenType::FLOAT)
            || $this->check(TokenType::STRING)
            || $this->check(TokenType::TRUE)
            || $this->check(TokenType::FALSE)
            || $this->check(TokenType::NULL)) {
            $token = $this->advance();

            return new Literal($token->literal, $token->span);
        }

        if ($this->check(TokenType::VARIABLE)) {
            $token = $this->advance();

            return new Variable((string) $token->literal, $token->span);
        }

        if ($this->check(TokenType::IDENTIFIER)) {
            if (!$this->checkNext(TokenType::LPAREN) && !$this->checkNext(TokenType::COLON_COLON)) {
                throw $this->errorAtToken(
                    DiagnosticCode::SYNTAX_ERROR,
                    'Un identifiant seul n’est pas une valeur ; appelez une fonction.',
                    $this->peek(),
                );
            }

            return $this->parseCall();
        }

        if ($this->consumeIf(TokenType::LPAREN)) {
            $expression = $this->parseExpression();
            $this->consume(TokenType::RPAREN, '« ) » attendu après l’expression.');

            return $expression;
        }

        if ($this->check(TokenType::LBRACKET)) {
            return $this->parseArrayLiteral();
        }

        throw $this->errorAtToken(
            DiagnosticCode::SYNTAX_ERROR,
            'Expression attendue.',
            $this->peek(),
        );
    }

    private function parseCall(): Call
    {
        $start = $this->peek()->span->start;
        $nameToken = $this->consume(TokenType::IDENTIFIER, 'Nom de fonction attendu.');
        $namespace = null;
        $name = (string) $nameToken->literal;

        if ($this->consumeIf(TokenType::COLON_COLON)) {
            $namespace = $name;
            $nameToken = $this->consume(TokenType::IDENTIFIER, 'Nom de fonction attendu après « :: ».');
            $name = (string) $nameToken->literal;
        }

        $this->consume(TokenType::LPAREN, '« ( » attendu après le nom de fonction.');
        $arguments = [];

        if (!$this->check(TokenType::RPAREN)) {
            do {
                $arguments[] = $this->parseExpression();
            } while ($this->consumeIf(TokenType::COMMA) && !$this->check(TokenType::RPAREN));
        }

        $close = $this->consume(TokenType::RPAREN, '« ) » attendu après les arguments.');

        return new Call($namespace, $name, $arguments, new SourceSpan($start, $close->span->end));
    }

    private function parseArrayLiteral(): ArrayLiteral
    {
        $open = $this->consume(TokenType::LBRACKET, '« [ » attendu.');
        $items = [];
        $hasKeyed = false;
        $hasPositional = false;

        if (!$this->check(TokenType::RBRACKET)) {
            do {
                $itemStart = $this->peek()->span->start;
                $first = $this->parseExpression();

                if ($this->consumeIf(TokenType::FAT_ARROW)) {
                    $hasKeyed = true;
                    $value = $this->parseExpression();
                    $items[] = new ArrayItem(
                        $first,
                        $value,
                        new SourceSpan($itemStart, $value->span->end),
                    );
                } else {
                    $hasPositional = true;
                    $items[] = new ArrayItem(null, $first, $first->span);
                }
            } while ($this->consumeIf(TokenType::COMMA) && !$this->check(TokenType::RBRACKET));
        }

        $close = $this->consume(TokenType::RBRACKET, '« ] » attendu après le tableau.');
        $span = new SourceSpan($open->span->start, $close->span->end);

        if ($hasKeyed && $hasPositional) {
            throw new SyntaxException(new Diagnostic(
                DiagnosticCode::MIXED_ARRAY_LITERAL,
                'Un tableau ne peut pas mélanger des éléments positionnels et des clés.',
                $span,
            ));
        }

        return new ArrayLiteral($items, $hasKeyed, $span);
    }

    private function binary(Expression $left, Token $operator, Expression $right): Binary
    {
        return new Binary(
            $left,
            $operator->lexeme,
            $right,
            $left->span->merge($right->span),
        );
    }

    private function consume(TokenType $type, string $message): Token
    {
        if ($this->check($type)) {
            return $this->advance();
        }

        throw $this->errorAtToken(
            DiagnosticCode::UNEXPECTED_TOKEN,
            $message . ' Trouvé ' . $this->describe($this->peek()) . '.',
            $this->peek(),
        );
    }

    private function consumeIf(TokenType $type): bool
    {
        if (!$this->check($type)) {
            return false;
        }

        $this->advance();

        return true;
    }

    private function check(TokenType $type): bool
    {
        return $this->peek()->type === $type;
    }

    private function checkNext(TokenType $type): bool
    {
        return ($this->tokens[$this->current + 1] ?? null)?->type === $type;
    }

    private function advance(): Token
    {
        $token = $this->peek();

        if (!$this->isAtEnd()) {
            $this->current++;
        }

        return $token;
    }

    private function peek(): Token
    {
        return $this->tokens[$this->current] ?? $this->tokens[array_key_last($this->tokens)];
    }

    private function previous(): Token
    {
        return $this->tokens[$this->current - 1] ?? $this->tokens[0];
    }

    private function isAtEnd(): bool
    {
        return $this->peek()->type === TokenType::EOF;
    }

    private function synchronizeTopLevel(): void
    {
        while (!$this->isAtEnd() && !$this->check(TokenType::FUNCTION)) {
            $this->advance();
        }
    }

    private function synchronizeBlock(): void
    {
        while (!$this->isAtEnd()) {
            if ($this->check(TokenType::RBRACE)) {
                return;
            }

            if ($this->consumeIf(TokenType::SEMICOLON)) {
                return;
            }

            $this->advance();
        }
    }

    private function describe(Token $token): string
    {
        return $token->type === TokenType::EOF ? 'la fin de la source' : '« ' . $token->lexeme . ' »';
    }

    private function errorAtToken(string $code, string $message, Token $token): SyntaxException
    {
        return new SyntaxException(new Diagnostic($code, $message, $token->span));
    }
}
