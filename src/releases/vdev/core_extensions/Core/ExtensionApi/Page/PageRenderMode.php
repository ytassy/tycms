<?php

namespace YTassy\Tycms\CoreExts\Core\ExtensionApi\Page;

enum PageRenderMode: string
{
    case PUBLIC = 'public';
    case PREVIEW = 'preview';
    case CANVAS = 'canvas';
}
