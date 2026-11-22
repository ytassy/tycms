<?php

namespace YTassy\Tycms\CoreExts\Core\ExtensionApi\Template;

enum VueMode: string
{
    case NONE = 'NONE';
    case FULL_BODY = 'FULL_BODY';
    case HEADER_ONLY = 'HEADER_ONLY';
    
}
