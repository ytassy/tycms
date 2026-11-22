<?php

namespace YTassy\Tycms\CoreExts\Core\Helper;

use YTassy\Tycms\CoreExts\Core\Helper\Forms\FormTypesHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Menu\FormsHelper as MenuFormsHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Space\FormsHelper as SpaceFormsHelper;
use YTassy\Tycms\CoreExts\Core\Helper\SsoProvider\FormsHelper as SsoProviderFormsHelper;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Traits\TraitCoreSharedHelpers;
use YTassy\Tycms\CoreExts\Core\Helper\UserAdminHelper;

trait TraitAllHelpers
{
    use TraitCoreSharedHelpers;
    use TraitCoreAdditionalHelpers;

    protected UserAdminHelper $coreUserAdminHelper {
        get { return $this->getSharedExtensionsClass(UserAdminHelper::class); }
    }

    protected MenuFormsHelper $coreMenuFormsHelper {
        get { return $this->getSharedExtensionsClass(MenuFormsHelper::class); }
    }

    protected SpaceFormsHelper $coreSpaceFormsHelper {
        get { return $this->getSharedExtensionsClass(SpaceFormsHelper::class); }
    }

    protected SsoProviderFormsHelper $coreSsoProviderFormsHelper {
        get { return $this->getSharedExtensionsClass(SsoProviderFormsHelper::class); }
    }

    protected FormTypesHelper $coreFormTypesHelper {
        get { return $this->getSharedExtensionsClass(FormTypesHelper::class); }
    }
}
