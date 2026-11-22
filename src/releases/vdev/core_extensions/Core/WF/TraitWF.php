<?php

namespace YTassy\Tycms\CoreExts\Core\WF;

use YTassy\Tycms\CoreExts\Core\WF\Admin\WFExtensions;
use YTassy\Tycms\CoreExts\Core\WF\Admin\WFExtensionSource;
use YTassy\Tycms\CoreExts\Core\WF\WFSpace;
use YTassy\Tycms\CoreExts\Core\WF\WFMedia;

trait TraitWF
{
    protected WFLogs $wfLogs {
        get { return $this->getSharedExtensionsClass(WFLogs::class); }
    }

    protected WFMyAccount $wfMyAccount {
        get { return $this->getSharedExtensionsClass(WFMyAccount::class); }
    }

    protected WFAuthentication $wfAuthentication {
        get { return $this->getSharedExtensionsClass(WFAuthentication::class); }
    }

    protected WFSpaceSwitch $wfSpaceSwitch {
        get { return $this->getSharedExtensionsClass(WFSpaceSwitch::class); }
    }

    protected WFSystemUpdate $wfSystemUpdate {
        get { return $this->getSharedExtensionsClass(WFSystemUpdate::class); }
    }

    protected WFFont $wfFont {
        get { return $this->getSharedExtensionsClass(WFFont::class); }
    }

    protected WFPasswordReset $wfPasswordReset {
        get { return $this->getSharedExtensionsClass(WFPasswordReset::class); }
    }

    protected WFEmailTemplate $wfEmailTemplate {
        get { return $this->getSharedExtensionsClass(WFEmailTemplate::class); }
    }

    protected WFLanguage $wfLanguage {
        get { return $this->getSharedExtensionsClass(WFLanguage::class); }
    }

    protected WFAi $wfAi {
        get { return $this->getSharedExtensionsClass(WFAi::class); }
    }

    protected WFOAuthIntegration $wfOAuthIntegration {
        get { return $this->getSharedExtensionsClass(WFOAuthIntegration::class); }
    }

    protected WFSsoProvider $wfSsoProvider {
        get { return $this->getSharedExtensionsClass(WFSsoProvider::class); }
    }

    protected WFSso $wfSso {
        get { return $this->getSharedExtensionsClass(WFSso::class); }
    }

    protected WFUser $wfUser {
        get { return $this->getSharedExtensionsClass(WFUser::class); } 
    }

    protected WFAdminUser $wfAdminUser {
        get { return $this->getSharedExtensionsClass(WFAdminUser::class); }
    }

    protected WFSystemInfo $wfSystemInfo {
        get { return $this->getSharedExtensionsClass(WFSystemInfo::class); }
    }

    protected WFMenu $wfMenu {
        get { return $this->getSharedExtensionsClass(WFMenu::class); } 
    }

    protected WFPage $wfPage {
        get { return $this->getSharedExtensionsClass(WFPage::class); }
    }

    protected WFPageButton $wfPageButton {
        get { return $this->getSharedExtensionsClass(WFPageButton::class); }
    }

    protected WFSpace $wfSpace {
        get { return $this->getSharedExtensionsClass(WFSpace::class); }
    }

    protected WFMedia $wfMedia {
        get { return $this->getSharedExtensionsClass(WFMedia::class); }
    }

    protected WFRole $wfRole {
        get { return $this->getSharedExtensionsClass(WFRole::class); }
    }

    protected WFUserGroup $wfUserGroup {
        get { return $this->getSharedExtensionsClass(WFUserGroup::class); }
    }

    
    protected WFExtensions $wfAdminExtensions {
        get { return $this->getSharedExtensionsClass(WFExtensions::class); } 
    }

    protected WFExtensionSource $wfExtensionSource {
        get { return $this->getSharedExtensionsClass(WFExtensionSource::class); }
    }

    

}
