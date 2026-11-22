<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Language;

enum TokenType
{
    case EOF;
    case IDENTIFIER;
    case VARIABLE;
    case INT;
    case FLOAT;
    case STRING;

    case TRUE;
    case FALSE;
    case NULL;

    case IF;
    case ELSEIF;
    case ELSE;
    case FOREACH;
    case FOR;
    case WHILE;
    case AS;
    case RETURN;
    case BREAK;
    case CONTINUE;
    case FUNCTION;
    case AND;
    case OR;
    case NOT;
    case IN;
    case STOP;
    case FAIL;

    case LPAREN;
    case RPAREN;
    case LBRACE;
    case RBRACE;
    case LBRACKET;
    case RBRACKET;
    case SEMICOLON;
    case COMMA;
    case COLON;
    case QUESTION;
    case QUESTION_ARROW;
    case ARROW;
    case COALESCE;
    case COLON_COLON;
    case FAT_ARROW;
    case DOT;
    case PLUS;
    case MINUS;
    case STAR;
    case SLASH;
    case PERCENT;
    case POWER;
    case ASSIGN;
    case EQ;
    case NEQ;
    case LT;
    case LTE;
    case GT;
    case GTE;
}
