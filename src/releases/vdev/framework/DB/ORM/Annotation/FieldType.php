<?php

namespace YTassy\Tycms\DB\ORM\Annotation;


enum FieldType: string
{
    case BOOL = 'BOOL';
    case INT = 'INT';
    case STRING = 'STRING';
    case TEXT = 'TEXT';
    case FLOAT = 'FLOAT';
    case JSON = 'JSON';
    case JSON_TEXT = 'JSON_TEXT';
    case JSON_ARRAY = 'JSON_ARRAY';
    case JSON_ARRAY_TEXT = 'JSON_ARRAY_TEXT';
	case DATETIME = 'DATETIME';
	case DATE = 'DATE';
	case BINARY = 'BINARY';

}
