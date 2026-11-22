<?php

namespace YTassy\Tycms\CoreExts\Core\Controllers\Admin;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\AnnotationsController\Acl;
use YTassy\Tycms\Routing\Annotation\Doc\Controller;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerMethod;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseController;
use YTassy\Tycms\CoreExts\Core\Views\Admin\AdminView;
use YTassy\Tycms\CoreExts\Core\WF\TraitWF;

#[Controller(name: "Administrator", isApi: false, path: "administrator")]
class AdminController extends BaseController
{
    use TraitWF;

    
    #[ControllerMethod(name: "Home", path: "")]
    public function home()
    {
        return $this->getViewResponse(new AdminView(
            $this,
            $this->wfAdminExtensions->canEditAnyConfiguration(),
        ));
    }

}
