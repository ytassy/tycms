<?php

namespace YTassy\Tycms\CoreExts\Core\ExtensionApi\Page;

enum PageDataMode: string
{
    case INPUT = 'input';
    case EDITOR = 'editor';
    case RENDER = 'render';
}
