<?php

namespace YTassy\Tycms\CoreExts\Core\Controller;

use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\Exceptions\AccessDeniedException;
use YTassy\Tycms\CoreExts\Core\Helper\TraitAllHelpers;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\AnnotationsController\Acl;
use YTassy\Tycms\Routing\Controller\BaseController;
use YTassy\Tycms\Routing\Route;

class ControllerManager extends BaseApplicationItem
{
    use TraitAllHelpers;


    public function onControllerCall(Route $route, BaseController $controller, string $controllerMethod, array $params)
    {

        foreach ($route->attributes as $name => $attrs) {

            foreach ($attrs as $attr) {
                if ($attr instanceof Acl) {

                    if ($attr->globalRole != null && $this->coreAclHelper->hasGlobalRole($attr->globalRole, $attr->spaceId) == false){
                        throw new AccessDeniedException();
                    }

                    if ($attr->globalPermission != null && $this->coreAclHelper->hasGlobalPermission($attr->globalPermission, $attr->spaceId) == false){
                        throw new AccessDeniedException();
                    }
                }
            }
        }
    }
}
