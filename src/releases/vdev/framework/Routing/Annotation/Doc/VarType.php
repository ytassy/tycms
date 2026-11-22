<?php

namespace YTassy\Tycms\Routing\Annotation\Doc;

enum VarType: string
{
    case INT = 'INT';
    case STRING = 'STRING';
    case BOOL = 'BOOL';
    case FLOAT = 'FLOAT';
    case JSON = 'JSON';
    case JSON_ARRAY = 'JSON_ARRAY';
}
