<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Language;

/** Codes stables des diagnostics du langage. */
final class DiagnosticCode
{
    public const SYNTAX_ERROR = 'SYNTAX_ERROR';
    public const UNEXPECTED_TOKEN = 'UNEXPECTED_TOKEN';
    public const UNTERMINATED_STRING = 'UNTERMINATED_STRING';
    public const UNTERMINATED_COMMENT = 'UNTERMINATED_COMMENT';
    public const INVALID_ESCAPE = 'INVALID_ESCAPE';
    public const INVALID_NUMBER = 'INVALID_NUMBER';
    public const INVALID_CHARACTER = 'INVALID_CHARACTER';
    public const INVALID_PARAMETER = 'INVALID_PARAMETER';
    public const LIMIT_EXCEEDED = 'LIMIT_EXCEEDED';
    public const TOP_LEVEL_STATEMENT = 'TOP_LEVEL_STATEMENT';
    public const MIXED_ARRAY_LITERAL = 'MIXED_ARRAY_LITERAL';
    public const MISSING_MAIN = 'MISSING_MAIN';
    public const DUPLICATE_MAIN = 'DUPLICATE_MAIN';
    public const MAIN_WITH_PARAMS = 'MAIN_WITH_PARAMS';
    public const MAIN_CALLED_BY_CODE = 'MAIN_CALLED_BY_CODE';
    public const DUPLICATE_FUNCTION = 'DUPLICATE_FUNCTION';
    public const DUPLICATE_PARAM = 'DUPLICATE_PARAM';
    public const UNKNOWN_FUNCTION = 'UNKNOWN_FUNCTION';
    public const BAD_ARITY = 'BAD_ARITY';
    public const BREAK_OUTSIDE_LOOP = 'BREAK_OUTSIDE_LOOP';
    public const CONTINUE_OUTSIDE_LOOP = 'CONTINUE_OUTSIDE_LOOP';
    public const READONLY_VARIABLE = 'READONLY_VARIABLE';

    public const UNDEFINED_VARIABLE = 'UNDEFINED_VARIABLE';
    public const TYPE_ERROR = 'TYPE_ERROR';
    public const DIVISION_BY_ZERO = 'DIVISION_BY_ZERO';
    public const MISSING_FIELD = 'MISSING_FIELD';
    public const INDEX_OUT_OF_RANGE = 'INDEX_OUT_OF_RANGE';
    public const ACTION_UNAVAILABLE = 'ACTION_UNAVAILABLE';
    public const ACTION_FAILED = 'ACTION_FAILED';
    public const ACCESS_DENIED = 'ACCESS_DENIED';
    public const RUNTIME_ERROR = 'RUNTIME_ERROR';
    public const INVALID_VALUE = 'INVALID_VALUE';
    public const INVALID_ARGUMENT = 'INVALID_ARGUMENT';
    public const CALL_DEPTH_EXCEEDED = 'CALL_DEPTH_EXCEEDED';
    public const STEP_LIMIT_EXCEEDED = 'STEP_LIMIT_EXCEEDED';
    public const LOOP_LIMIT_EXCEEDED = 'LOOP_LIMIT_EXCEEDED';
    public const DURATION_EXCEEDED = 'DURATION_EXCEEDED';
    public const CONTROL_FLOW_ESCAPED = 'CONTROL_FLOW_ESCAPED';
    public const FAILED = 'FAILED';
    public const OUTPUT_TOO_LARGE = 'OUTPUT_TOO_LARGE';

    private function __construct()
    {
    }
}
