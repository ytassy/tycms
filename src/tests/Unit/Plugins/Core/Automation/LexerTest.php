<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\Automation;

use PHPUnit\Framework\Attributes\Test;
use YTassy\Tycms\CoreExts\Automation\Language\DiagnosticCode;
use YTassy\Tycms\CoreExts\Automation\Language\Lexer;
use YTassy\Tycms\CoreExts\Automation\Language\SyntaxException;
use YTassy\Tycms\CoreExts\Automation\Language\Token;
use YTassy\Tycms\CoreExts\Automation\Language\TokenType;

final class LexerTest extends LanguageTestCase
{
    #[Test]
    public function tokenizesVariablesOperatorsAndKeywords(): void
    {
        $source = <<<'DSL'
            $a = 1 + 2.5 ** 3; // commentaire
            if ($a >= 2 and not $b) { }
            DSL;

        $tokens = (new Lexer())->tokenize($source);
        $types = array_map(static fn (Token $token): TokenType => $token->type, $tokens);

        self::assertSame([
            TokenType::VARIABLE,
            TokenType::ASSIGN,
            TokenType::INT,
            TokenType::PLUS,
            TokenType::FLOAT,
            TokenType::POWER,
            TokenType::INT,
            TokenType::SEMICOLON,
            TokenType::IF,
            TokenType::LPAREN,
            TokenType::VARIABLE,
            TokenType::GTE,
            TokenType::INT,
            TokenType::AND,
            TokenType::NOT,
            TokenType::VARIABLE,
            TokenType::RPAREN,
            TokenType::LBRACE,
            TokenType::RBRACE,
            TokenType::EOF,
        ], $types);
    }

    #[Test]
    public function tracksLineAndColumnPositions(): void
    {
        $tokens = (new Lexer())->tokenize("\$a = 1;\n  \$b = 2;");

        $variables = array_values(array_filter(
            $tokens,
            static fn (Token $token): bool => $token->type === TokenType::VARIABLE,
        ));

        self::assertCount(2, $variables);
        self::assertSame('a', $variables[0]->literal);
        self::assertSame(1, $variables[0]->span->start->line);
        self::assertSame(1, $variables[0]->span->start->column);
        self::assertSame('b', $variables[1]->literal);
        self::assertSame(2, $variables[1]->span->start->line);
        self::assertSame(3, $variables[1]->span->start->column);
    }

    #[Test]
    public function decodesStringEscapesAndUnicode(): void
    {
        $tokens = (new Lexer())->tokenize('$x = "a\n\u{1F600}";');
        $string = array_values(array_filter(
            $tokens,
            static fn (Token $token): bool => $token->type === TokenType::STRING,
        ))[0] ?? null;

        self::assertInstanceOf(Token::class, $string);
        self::assertSame("a\n😀", $string->literal);
    }

    #[Test]
    public function skipsLineAndBlockComments(): void
    {
        $tokens = (new Lexer())->tokenize("/* avant */ \$x; // après\n");
        $types = array_map(static fn (Token $token): TokenType => $token->type, $tokens);

        self::assertSame([TokenType::VARIABLE, TokenType::SEMICOLON, TokenType::EOF], $types);
    }

    #[Test]
    public function rejectsUnterminatedStrings(): void
    {
        try {
            (new Lexer())->tokenize('$x = "abc;');
            self::fail('Une chaîne non terminée doit être refusée.');
        } catch (SyntaxException $exception) {
            self::assertSame(DiagnosticCode::UNTERMINATED_STRING, $exception->diagnostic->code);
            self::assertSame(1, $exception->diagnostic->span->start->line);
        }
    }

    #[Test]
    public function rejectsInvalidCharacters(): void
    {
        try {
            (new Lexer())->tokenize('$x = @;');
            self::fail('Un caractère inconnu doit être refusé.');
        } catch (SyntaxException $exception) {
            self::assertSame(DiagnosticCode::INVALID_CHARACTER, $exception->diagnostic->code);
        }
    }

    #[Test]
    public function rejectsInvalidEscapes(): void
    {
        try {
            (new Lexer())->tokenize('$x = "\q";');
            self::fail('Une séquence d’échappement inconnue doit être refusée.');
        } catch (SyntaxException $exception) {
            self::assertSame(DiagnosticCode::INVALID_ESCAPE, $exception->diagnostic->code);
        }
    }

    #[Test]
    public function rejectsNonFiniteFloats(): void
    {
        try {
            (new Lexer())->tokenize('$x = 1e999;');
            self::fail('Un flottant non fini doit être refusé.');
        } catch (SyntaxException $exception) {
            self::assertSame(DiagnosticCode::INVALID_NUMBER, $exception->diagnostic->code);
        }
    }
}
