<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Language\Ast;

/** Opérateurs du langage, pour éviter les chaînes dispersées. */
final class Operator
{
    public const ADD = '+';
    public const SUBTRACT = '-';
    public const MULTIPLY = '*';
    public const DIVIDE = '/';
    public const MODULO = '%';
    public const POWER = '**';
    public const CONCAT = '.';
    public const EQUAL = '==';
    public const NOT_EQUAL = '!=';
    public const LESS = '<';
    public const LESS_OR_EQUAL = '<=';
    public const GREATER = '>';
    public const GREATER_OR_EQUAL = '>=';
    public const IN = 'in';
    public const AND = 'and';
    public const OR = 'or';
    public const COALESCE = '??';
    public const NOT = 'not';
    public const UNARY_MINUS = '-';
    public const UNARY_PLUS = '+';

    private function __construct()
    {
    }
}
