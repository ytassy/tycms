<?php

namespace YTassy\Tycms\CoreExts\Core\Helper;

use YTassy\Tycms\CoreExts\Core\Helper\Admin\AdminHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Config\FormsHelper as AppConfigFormsHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Config\ConfigurationHelper as AppConfigurationHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Acl\RoleAdminHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Acl\UserGroupAdminHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Admin\ExtensionsHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Extension\CatalogSourceClient;
use YTassy\Tycms\CoreExts\Core\Helper\Extension\CatalogSourceHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Extension\ExtensionInstaller;
use YTassy\Tycms\CoreExts\Core\Helper\Extension\ExtensionUninstaller;
use YTassy\Tycms\CoreExts\Core\Helper\Menu\MenuAdminHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Menu\MenuRouteHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Menu\MenuLanguageHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Media\MediaHelper;
use YTassy\Tycms\CoreExts\Core\JobQueue\JobAdminHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageButtonHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageButtonToken;
use YTassy\Tycms\CoreExts\Core\Helper\Mail\MailAdminHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Mail\MailLogHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Mail\UserWelcomeMailHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Language\LanguageOverrideAdminHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Space\SpaceAdminHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Space\SpaceSwitchHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Space\SpaceLanguageAdminHelper;
use YTassy\Tycms\CoreExts\Core\Helper\SystemUpdate\SystemUpdateHelper;
use YTassy\Tycms\CoreExts\Core\Helper\SystemUpdate\SystemUpdatePackageHelper;
use YTassy\Tycms\CoreExts\Core\Helper\SystemUpdate\SystemUpdatePreparationHelper;

trait TraitCoreAdditionalHelpers
{
    protected LogReaderHelper $coreLogReaderHelper {
        get { return $this->getSharedExtensionsClass(LogReaderHelper::class); }
    }

    protected MyAccountHelper $coreMyAccountHelper {
        get { return $this->getSharedExtensionsClass(MyAccountHelper::class); }
    }

    protected AuthenticationHelper $coreAuthenticationHelper {
        get { return $this->getSharedExtensionsClass(AuthenticationHelper::class); }
    }

    protected SpaceSwitchHelper $coreSpaceSwitchHelper {
        get { return $this->getSharedExtensionsClass(SpaceSwitchHelper::class); }
    }

    protected SystemUpdatePreparationHelper $coreSystemUpdatePreparationHelper {
        get { return $this->getSharedExtensionsClass(SystemUpdatePreparationHelper::class); }
    }

    protected SystemUpdatePackageHelper $coreSystemUpdatePackageHelper {
        get { return $this->getSharedExtensionsClass(SystemUpdatePackageHelper::class); }
    }

    protected SystemUpdateHelper $coreSystemUpdateHelper {
        get { return $this->getSharedExtensionsClass(SystemUpdateHelper::class); }
    }

    protected AppConfigurationHelper $coreAppConfigurationHelper {
        get { return $this->getSharedExtensionsClass(AppConfigurationHelper::class); }
    }

    protected AppConfigFormsHelper $coreAppConfigFormsHelper {
        get { return $this->getSharedExtensionsClass(AppConfigFormsHelper::class); }
    }

    protected PasswordResetHelper $corePasswordResetHelper {
        get { return $this->getSharedExtensionsClass(PasswordResetHelper::class); }
    }

    protected UserWelcomeMailHelper $coreUserWelcomeMailHelper {
        get { return $this->getSharedExtensionsClass(UserWelcomeMailHelper::class); }
    }

    protected UserGroupAdminHelper $coreUserGroupAdminHelper {
        get { return $this->getSharedExtensionsClass(UserGroupAdminHelper::class); }
    }

    protected RoleAdminHelper $coreRoleAdminHelper {
        get { return $this->getSharedExtensionsClass(RoleAdminHelper::class); }
    }

    protected MenuLanguageHelper $coreMenuLanguageHelper {
        get { return $this->getSharedExtensionsClass(MenuLanguageHelper::class); }
    }

    protected LanguageOverrideAdminHelper $coreLanguageOverrideAdminHelper {
        get { return $this->getSharedExtensionsClass(LanguageOverrideAdminHelper::class); }
    }
    protected MailAdminHelper $coreMailAdminHelper {
        get { return $this->getSharedExtensionsClass(MailAdminHelper::class); }
    }

    protected MailLogHelper $coreMailLogHelper {
        get { return $this->getSharedExtensionsClass(MailLogHelper::class); }
    }

    protected MenuRouteHelper $coreMenuRouteHelper {
        get { return $this->getSharedExtensionsClass(MenuRouteHelper::class); }
    }

    protected MenuAdminHelper $coreMenuAdminHelper {
        get { return $this->getSharedExtensionsClass(MenuAdminHelper::class); }
    }

    protected ExtensionsHelper $coreExtensionsHelper {
        get { return $this->getSharedExtensionsClass(ExtensionsHelper::class); }
    }

    protected CatalogSourceHelper $coreCatalogSourceHelper {
        get { return $this->getSharedExtensionsClass(CatalogSourceHelper::class); }
    }

    protected CatalogSourceClient $coreCatalogSourceClient {
        get { return $this->getSharedExtensionsClass(CatalogSourceClient::class); }
    }

    protected ExtensionInstaller $coreExtensionInstaller {
        get { return $this->getSharedExtensionsClass(ExtensionInstaller::class); }
    }

    protected ExtensionUninstaller $coreExtensionUninstaller {
        get { return $this->getSharedExtensionsClass(ExtensionUninstaller::class); }
    }

    protected AdminHelper $coreAdminHelper {
        get { return $this->getSharedExtensionsClass(AdminHelper::class); }
    }

    protected SpaceAdminHelper $coreSpaceAdminHelper {
        get { return $this->getSharedExtensionsClass(SpaceAdminHelper::class); }
    }

    protected SpaceLanguageAdminHelper $coreSpaceLanguageAdminHelper {
        get { return $this->getSharedExtensionsClass(SpaceLanguageAdminHelper::class); }
    }

    protected MediaHelper $coreMediaHelper {
        get { return $this->getSharedExtensionsClass(MediaHelper::class); }
    }

    protected PageButtonToken $corePageButtonToken {
        get { return $this->getSharedExtensionsClass(PageButtonToken::class); }
    }

    protected PageButtonHelper $corePageButtonHelper {
        get { return $this->getSharedExtensionsClass(PageButtonHelper::class); }
    }

    protected JobAdminHelper $coreJobAdminHelper {
        get { return $this->getSharedExtensionsClass(JobAdminHelper::class); }
    }
}
