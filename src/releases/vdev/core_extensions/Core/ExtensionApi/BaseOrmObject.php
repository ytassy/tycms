<?php

namespace YTassy\Tycms\CoreExts\Core\ExtensionApi;

use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\DB\ORM\BaseOrmObject as ORMBaseOrmObject;
use YTassy\Tycms\DB\ORM\FieldDef;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\AnnotationsOrm\Acl;

use YTassy\Tycms\DB\ORM\Annotation\Hidden;
use YTassy\Tycms\CoreExts\Core\Helper\Acl\AclHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Space\SpaceHelper;
use YTassy\Tycms\CoreExts\Core\Helper\UserHelper;

class BaseOrmObject extends ORMBaseOrmObject {

    #[Hidden]
    protected SpaceHelper $coreSpaceHelper {
        get { return $this->getSharedExtensionsClass(SpaceHelper::class); }
    }

    #[Hidden]
    protected AclHelper $coreAclHelper {
        get { return $this->getSharedExtensionsClass(AclHelper::class); }
    }

    #[Hidden]
    protected UserHelper $coreUserHelper {
        get { return $this->getSharedExtensionsClass(UserHelper::class); }
    }


    protected function serializeField(FieldDef $field): bool
    {
        foreach($field->attributes as $attr) {
            if ($attr instanceof Acl) {
                // Les deux conditions déclarées sont cumulatives ; une ACL vide n'autorise rien.
                if ($attr->globalRole === null && $attr->globalPermission === null) return false;
                if ($attr->globalRole !== null
                    && !$this->coreAclHelper->hasGlobalRole($attr->globalRole, $attr->spaceId)) return false;
                if ($attr->globalPermission !== null
                    && !$this->coreAclHelper->hasGlobalPermission($attr->globalPermission, $attr->spaceId)) return false;
            }
        }
        
        return true;
    }
    
}

