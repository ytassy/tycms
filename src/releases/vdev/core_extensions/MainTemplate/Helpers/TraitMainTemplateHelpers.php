<?php

namespace YTassy\Tycms\CoreExts\MainTemplate\Helpers;


trait TraitMainTemplateHelpers
{

    protected CssHelper $mainTemplateCssHelper {
        get { return $this->getSharedExtensionsClass(CssHelper::class); } 
    }

    
}
