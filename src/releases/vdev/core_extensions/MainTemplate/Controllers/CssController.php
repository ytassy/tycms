<?php

namespace YTassy\Tycms\CoreExts\MainTemplate\Controllers;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseController;
use YTassy\Tycms\Response\CssResponse;
use YTassy\Tycms\Routing\Annotation\Doc\Controller;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerMethod;
use YTassy\Tycms\CoreExts\MainTemplate\Helpers\TraitMainTemplateHelpers;

#[Controller(name: "TemplateMainCss", isApi: false, path: "/templates/main/css")]
class CssController extends BaseController
{
    use TraitMainTemplateHelpers;
    
 
    #[ControllerMethod(name: "get", path: "")]
    public function get()
    {
        return new CssResponse($this->mainTemplateCssHelper->getCss());
    }

}
