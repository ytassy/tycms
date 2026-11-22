<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Language;

/**
 * Analyseur lexical du langage de scénarios.
 *
 * Il produit une liste de jetons avec leur intervalle source. Les commentaires
 * et les espaces sont ignorés. Toute erreur lexicale lève une SyntaxException
 * porteuse d'un diagnostic positionné.
 */
final class Lexer
{
    private const KEYWORDS = [
        'if' => TokenType::IF,
        'elseif' => TokenType::ELSEIF,
        'else' => TokenType::ELSE,
        'foreach' => TokenType::FOREACH,
        'for' => TokenType::FOR,
        'while' => TokenType::WHILE,
        'as' => TokenType::AS,
        'return' => TokenType::RETURN,
        'break' => TokenType::BREAK,
        'continue' => TokenType::CONTINUE,
        'function' => TokenType::FUNCTION,
        'and' => TokenType::AND,
        'or' => TokenType::OR,
        'not' => TokenType::NOT,
        'in' => TokenType::IN,
        'stop' => TokenType::STOP,
        'fail' => TokenType::FAIL,
        'true' => TokenType::TRUE,
        'false' => TokenType::FALSE,
        'null' => TokenType::NULL,
    ];

    /** Les opérateurs multi-caractères sont testés avant les caractères seuls. */
    private const OPERATORS = [
        '?->' => TokenType::QUESTION_ARROW,
        '??' => TokenType::COALESCE,
        '::' => TokenType::COLON_COLON,
        '=>' => TokenType::FAT_ARROW,
        '->' => TokenType::ARROW,
        '**' => TokenType::POWER,
        '==' => TokenType::EQ,
        '!=' => TokenType::NEQ,
        '<=' => TokenType::LTE,
        '>=' => TokenType::GTE,
        '(' => TokenType::LPAREN,
        ')' => TokenType::RPAREN,
        '{' => TokenType::LBRACE,
        '}' => TokenType::RBRACE,
        '[' => TokenType::LBRACKET,
        ']' => TokenType::RBRACKET,
        ';' => TokenType::SEMICOLON,
        ',' => TokenType::COMMA,
        ':' => TokenType::COLON,
        '?' => TokenType::QUESTION,
        '.' => TokenType::DOT,
        '+' => TokenType::PLUS,
        '-' => TokenType::MINUS,
        '*' => TokenType::STAR,
        '/' => TokenType::SLASH,
        '%' => TokenType::PERCENT,
        '=' => TokenType::ASSIGN,
        '<' => TokenType::LT,
        '>' => TokenType::GT,
    ];

    private string $source = '';
    private int $length = 0;
    private int $offset = 0;
    private int $line = 1;
    private int $column = 1;
    private Limits $limits;
    private int $tokenCount = 0;

    /**
     * @return list<Token>
     *
     * @throws SyntaxException
     */
    public function tokenize(string $source, ?Limits $limits = null): array
    {
        $this->source = $source;
        $this->length = strlen($source);
        $this->offset = 0;
        $this->line = 1;
        $this->column = 1;
        $this->limits = $limits ?? new Limits();
        $this->tokenCount = 0;

        if ($this->length > $this->limits->maxSourceBytes) {
            throw $this->error(
                DiagnosticCode::LIMIT_EXCEEDED,
                'Taille de source dépassée (maximum ' . $this->limits->maxSourceBytes . ' octets).',
                $this->position(),
            );
        }

        $tokens = [];

        while (!$this->isAtEnd()) {
            $this->skipWhitespaceAndComments();

            if ($this->isAtEnd()) {
                break;
            }

            $tokens[] = $this->scanToken();
        }

        $tokens[] = new Token(TokenType::EOF, '', null, SourceSpan::point($this->position()));

        return $tokens;
    }

    private function scanToken(): Token
    {
        $start = $this->position();
        $char = $this->peek();

        if ($char === '$') {
            return $this->scanVariable($start);
        }

        if ($char !== null && $this->isIdentifierStart($char)) {
            return $this->scanIdentifier($start);
        }

        if ($char !== null && $this->isDigit($char)) {
            return $this->scanNumber($start);
        }

        if ($char === '"' || $char === "'") {
            return $this->scanString($start);
        }

        foreach (self::OPERATORS as $lexeme => $type) {
            if ($this->match($lexeme)) {
                $this->consume($lexeme);

                return $this->token($type, $lexeme, null, $start);
            }
        }

        throw $this->error(
            DiagnosticCode::INVALID_CHARACTER,
            'Caractère inattendu « ' . ($char ?? '') . ' ».',
            $start,
        );
    }

    private function scanVariable(SourcePosition $start): Token
    {
        $this->advance();

        if ($this->isAtEnd() || !$this->isIdentifierStart((string) $this->peek())) {
            throw $this->error(
                DiagnosticCode::SYNTAX_ERROR,
                'Nom de variable attendu après « $ ».',
                $start,
                $this->position(),
            );
        }

        $name = $this->scanIdentifierText();
        $lexeme = '$' . $name;

        return $this->token(TokenType::VARIABLE, $lexeme, $name, $start);
    }

    private function scanIdentifier(SourcePosition $start): Token
    {
        $lexeme = $this->scanIdentifierText();
        $type = self::KEYWORDS[$lexeme] ?? TokenType::IDENTIFIER;

        $literal = match ($type) {
            TokenType::TRUE => true,
            TokenType::FALSE => false,
            TokenType::NULL => null,
            TokenType::IDENTIFIER => $lexeme,
            default => null,
        };

        return $this->token($type, $lexeme, $literal, $start);
    }

    private function scanIdentifierText(): string
    {
        $startOffset = $this->offset;
        $this->advance();

        while (!$this->isAtEnd() && $this->isIdentifierPart((string) $this->peek())) {
            $this->advance();
        }

        return substr($this->source, $startOffset, $this->offset - $startOffset);
    }

    private function scanNumber(SourcePosition $start): Token
    {
        while (!$this->isAtEnd() && $this->isDigit((string) $this->peek())) {
            $this->advance();
        }

        $isFloat = false;

        // Un point ne fait partie du nombre que s'il est suivi d'un chiffre ;
        // sinon il reste l'opérateur de concaténation.
        if ($this->peek() === '.' && $this->isDigit((string) $this->peek(1))) {
            $isFloat = true;
            $this->advance();

            while (!$this->isAtEnd() && $this->isDigit((string) $this->peek())) {
                $this->advance();
            }
        }

        if ($this->peek() === 'e' || $this->peek() === 'E') {
            $isFloat = true;
            $this->advance();

            if ($this->peek() === '+' || $this->peek() === '-') {
                $this->advance();
            }

            if (!$this->isDigit((string) $this->peek())) {
                throw $this->error(
                    DiagnosticCode::INVALID_NUMBER,
                    'Exposant numérique incomplet.',
                    $start,
                    $this->position(),
                );
            }

            while (!$this->isAtEnd() && $this->isDigit((string) $this->peek())) {
                $this->advance();
            }
        }

        $lexeme = substr($this->source, $start->offset, $this->offset - $start->offset);

        if ($isFloat) {
            $value = filter_var($lexeme, FILTER_VALIDATE_FLOAT);
            if (!is_float($value) || !is_finite($value)) {
                throw $this->error(DiagnosticCode::INVALID_NUMBER, 'Nombre flottant invalide ou hors limites.', $start);
            }

            return $this->token(TokenType::FLOAT, $lexeme, $value, $start);
        }

        $value = filter_var($lexeme, FILTER_VALIDATE_INT);
        if (!is_int($value)) {
            throw $this->error(DiagnosticCode::INVALID_NUMBER, 'Entier hors limites.', $start);
        }

        return $this->token(TokenType::INT, $lexeme, $value, $start);
    }

    private function scanString(SourcePosition $start): Token
    {
        $quote = (string) $this->peek();
        $this->advance();

        $value = '';

        while (!$this->isAtEnd()) {
            $char = (string) $this->peek();

            if ($char === $quote) {
                $this->advance();
                $lexeme = substr($this->source, $start->offset, $this->offset - $start->offset);

                return $this->token(TokenType::STRING, $lexeme, $value, $start);
            }

            if ($char !== '\\') {
                $value .= $this->advance();
                continue;
            }

            $this->advance();
            $value .= $this->scanEscape($start);
        }

        throw $this->error(
            DiagnosticCode::UNTERMINATED_STRING,
            'Chaîne non terminée.',
            $start,
            $this->position(),
        );
    }

    private function scanEscape(SourcePosition $start): string
    {
        if ($this->isAtEnd()) {
            throw $this->error(
                DiagnosticCode::UNTERMINATED_STRING,
                'Chaîne non terminée après une séquence d’échappement.',
                $start,
                $this->position(),
            );
        }

        $escape = (string) $this->advance();

        return match ($escape) {
            'n' => "\n",
            'r' => "\r",
            't' => "\t",
            '\\' => '\\',
            '"' => '"',
            "'" => "'",
            '$' => '$',
            'u' => $this->scanUnicodeEscape($start),
            default => throw $this->error(
                DiagnosticCode::INVALID_ESCAPE,
                'Séquence d’échappement inconnue « \\' . $escape . ' ».',
                $start,
                $this->position(),
            ),
        };
    }

    private function scanUnicodeEscape(SourcePosition $start): string
    {
        if ($this->peek() !== '{') {
            throw $this->error(
                DiagnosticCode::INVALID_ESCAPE,
                'Séquence Unicode attendue sous la forme \\u{XXXX}.',
                $start,
                $this->position(),
            );
        }

        $this->advance();
        $hex = '';

        while (!$this->isAtEnd() && $this->peek() !== '}') {
            $char = (string) $this->peek();
            if (!ctype_xdigit($char)) {
                throw $this->error(
                    DiagnosticCode::INVALID_ESCAPE,
                    'Chiffre hexadécimal attendu dans la séquence Unicode.',
                    $start,
                    $this->position(),
                );
            }

            $hex .= $char;
            $this->advance();
        }

        if ($this->isAtEnd()) {
            throw $this->error(
                DiagnosticCode::INVALID_ESCAPE,
                'Séquence Unicode non terminée.',
                $start,
                $this->position(),
            );
        }

        $this->advance(); // }

        if ($hex === '' || strlen($hex) > 6) {
            throw $this->error(
                DiagnosticCode::INVALID_ESCAPE,
                'Séquence Unicode invalide.',
                $start,
                $this->position(),
            );
        }

        $codepoint = hexdec($hex);
        if ($codepoint > 0x10FFFF || ($codepoint >= 0xD800 && $codepoint <= 0xDFFF)) {
            throw $this->error(
                DiagnosticCode::INVALID_ESCAPE,
                'Point de code Unicode hors plage autorisée.',
                $start,
                $this->position(),
            );
        }

        $character = mb_chr($codepoint, 'UTF-8');
        if ($character === false) {
            throw $this->error(
                DiagnosticCode::INVALID_ESCAPE,
                'Point de code Unicode non représentable.',
                $start,
                $this->position(),
            );
        }

        return $character;
    }

    private function skipWhitespaceAndComments(): void
    {
        while (!$this->isAtEnd()) {
            $char = $this->peek();

            if ($char === ' ' || $char === "\t" || $char === "\r" || $char === "\n") {
                $this->advance();
                continue;
            }

            if ($char === '/' && $this->peek(1) === '/') {
                $this->advance();
                $this->advance();

                while (!$this->isAtEnd() && $this->peek() !== "\n") {
                    $this->advance();
                }

                continue;
            }

            if ($char === '/' && $this->peek(1) === '*') {
                $start = $this->position();
                $this->advance();
                $this->advance();

                while (!$this->isAtEnd() && !($this->peek() === '*' && $this->peek(1) === '/')) {
                    $this->advance();
                }

                if ($this->isAtEnd()) {
                    throw $this->error(
                        DiagnosticCode::UNTERMINATED_COMMENT,
                        'Commentaire non terminé.',
                        $start,
                        $this->position(),
                    );
                }

                $this->advance();
                $this->advance();

                continue;
            }

            break;
        }
    }

    private function token(TokenType $type, string $lexeme, mixed $literal, SourcePosition $start): Token
    {
        $this->tokenCount++;

        if ($this->tokenCount > $this->limits->maxTokens) {
            throw $this->error(
                DiagnosticCode::LIMIT_EXCEEDED,
                'Nombre maximal de jetons dépassé (maximum ' . $this->limits->maxTokens . ').',
                $start,
                $this->position(),
            );
        }

        return new Token($type, $lexeme, $literal, new SourceSpan($start, $this->position()));
    }

    private function position(): SourcePosition
    {
        return new SourcePosition($this->offset, $this->line, $this->column);
    }

    private function peek(int $lookahead = 0): ?string
    {
        return $this->source[$this->offset + $lookahead] ?? null;
    }

    private function match(string $expected): bool
    {
        return substr($this->source, $this->offset, strlen($expected)) === $expected;
    }

    private function consume(string $expected): void
    {
        for ($index = 0, $length = strlen($expected); $index < $length; $index++) {
            $this->advance();
        }
    }

    private function advance(): string
    {
        $char = $this->source[$this->offset];
        $this->offset++;

        if ($char === "\n") {
            $this->line++;
            $this->column = 1;
        } elseif ((ord($char) & 0xC0) !== 0x80) {
            // Ne pas compter les octets de continuation UTF-8 comme des colonnes.
            $this->column++;
        }

        return $char;
    }

    private function isAtEnd(): bool
    {
        return $this->offset >= $this->length;
    }

    private function isIdentifierStart(string $char): bool
    {
        return $char === '_' || ctype_alpha($char);
    }

    private function isIdentifierPart(string $char): bool
    {
        return $char === '_' || ctype_alnum($char);
    }

    private function isDigit(string $char): bool
    {
        return $char !== '' && ctype_digit($char);
    }

    private function error(
        string $code,
        string $message,
        SourcePosition $start,
        ?SourcePosition $end = null,
    ): SyntaxException {
        return new SyntaxException(
            new Diagnostic($code, $message, new SourceSpan($start, $end ?? $this->position())),
        );
    }
}
