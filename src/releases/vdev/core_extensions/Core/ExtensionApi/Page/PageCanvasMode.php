<?php

namespace YTassy\Tycms\CoreExts\Core\ExtensionApi\Page;

enum PageCanvasMode: string
{
    case VUE = 'vue';
    case API = 'api';
    case PLACEHOLDER = 'placeholder';
}
