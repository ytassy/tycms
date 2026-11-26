<?php

namespace YTassy\Tycms\CoreExts\Core\ExtensionApi\Traits;

use YTassy\Tycms\TraitFrameworkSharedExtensions;
use YTassy\Tycms\CoreExts\Core\Helper\Frontend\FrontendAssets;
use YTassy\Tycms\CoreExts\Core\JobQueue\JobTrafficTrigger;
use YTassy\Tycms\CoreExts\Core\Helper\Video\SecureVideoHelper;
use YTassy\Tycms\CoreExts\Core\Helper\ShortCode\ShortCodeHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Fonts\FontHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Acl\AclHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Ai\AiGatewayHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Ai\AiProviderHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Crypt\CryptHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Mail\MailHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Mail\EmailTemplateRegistry;
use YTassy\Tycms\CoreExts\Core\Helper\Mail\EmailTemplateHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Language\LanguageCatalogRegistry;
use YTassy\Tycms\CoreExts\Core\Helper\Language\LanguageTranslator;
use YTassy\Tycms\CoreExts\Core\Helper\Language\LanguageProjectionHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Language\PublicLocaleHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Menu\MenuHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageFileList;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageHistoryHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageComponentRegistry;
use YTassy\Tycms\CoreExts\Core\Helper\Session\BrowserSessionPropertyHelper;
use YTassy\Tycms\CoreExts\Core\Helper\SsoProviderHelper;
use YTassy\Tycms\CoreExts\Core\Helper\OAuthGrantHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Space\SpaceHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Space\SpaceLanguageHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Template\TemplateHelper;
use YTassy\Tycms\CoreExts\Core\Helper\UserHelper;
use YTassy\Tycms\CoreExts\Core\Helper\UserRegistrationHelper;

trait TraitCoreSharedHelpers
{
    use TraitFrameworkSharedExtensions;
    use TraitCoreLang;

    protected FrontendAssets $coreFrontendAssets {
        get { return $this->getSharedExtensionsClass(FrontendAssets::class); }
    }

    protected JobTrafficTrigger $coreJobTrafficTrigger {
        get { return $this->getSharedExtensionsClass(JobTrafficTrigger::class); }
    }

    protected SecureVideoHelper $coreSecureVideoHelper {
        get { return $this->getSharedExtensionsClass(SecureVideoHelper::class); }
    }

    protected ShortCodeHelper $coreShortCodeHelper {
        get { return $this->getSharedExtensionsClass(ShortCodeHelper::class); }
    }

    protected FontHelper $coreFontHelper {
        get { return $this->getSharedExtensionsClass(FontHelper::class); }
    }

    protected LanguageCatalogRegistry $coreLanguageCatalogRegistry {
        get { return $this->getSharedExtensionsClass(LanguageCatalogRegistry::class); }
    }

    protected LanguageTranslator $coreLanguageTranslator {
        get { return $this->getSharedExtensionsClass(LanguageTranslator::class); }
    }

    protected LanguageProjectionHelper $coreLanguageProjectionHelper {
        get { return $this->getSharedExtensionsClass(LanguageProjectionHelper::class); }
    }

    protected PublicLocaleHelper $corePublicLocaleHelper {
        get { return $this->getSharedExtensionsClass(PublicLocaleHelper::class); }
    }

    protected AiProviderHelper $coreAiProviderHelper {
        get { return $this->getSharedExtensionsClass(AiProviderHelper::class); }
    }

    protected AiGatewayHelper $coreAiGatewayHelper {
        get { return $this->getSharedExtensionsClass(AiGatewayHelper::class); }
    }

    protected SpaceHelper $coreSpaceHelper {
        get { return $this->getSharedExtensionsClass(SpaceHelper::class); } 
    }

    protected SpaceLanguageHelper $coreSpaceLanguageHelper {
        get { return $this->getSharedExtensionsClass(SpaceLanguageHelper::class); }
    }

    protected MailHelper $coreMailHelper {
        get { return $this->getSharedExtensionsClass(MailHelper::class); }
    }

    protected EmailTemplateRegistry $coreEmailTemplateRegistry {
        get { return $this->getSharedExtensionsClass(EmailTemplateRegistry::class); }
    }

    protected EmailTemplateHelper $coreEmailTemplateHelper {
        get { return $this->getSharedExtensionsClass(EmailTemplateHelper::class); }
    }

    protected CryptHelper $coreCryptHelper {
        get { return $this->getSharedExtensionsClass(CryptHelper::class); }
    }

    protected AclHelper $coreAclHelper {
        get { return $this->getSharedExtensionsClass(AclHelper::class); } 
    }

    protected UserHelper $coreUserHelper {
        get { return $this->getSharedExtensionsClass(UserHelper::class); } 
    }

    protected UserRegistrationHelper $coreUserRegistrationHelper {
        get { return $this->getSharedExtensionsClass(UserRegistrationHelper::class); }
    }

    protected MenuHelper $coreMenuHelper {
        get { return $this->getSharedExtensionsClass(MenuHelper::class); } 
    }

    protected PageFileList $corePageFileList {
        get { return $this->getSharedExtensionsClass(PageFileList::class); }
    }

    protected PageHelper $corePageHelper {
        get { return $this->getSharedExtensionsClass(PageHelper::class); } 
    }

    protected PageHistoryHelper $corePageHistoryHelper {
        get { return $this->getSharedExtensionsClass(PageHistoryHelper::class); }
    }

    protected PageComponentRegistry $corePageComponentRegistry {
        get { return $this->getSharedExtensionsClass(PageComponentRegistry::class); }
    }

    protected TemplateHelper $coreTemplateHelper {
        get { return $this->getSharedExtensionsClass(TemplateHelper::class); } 
    }

    protected SsoProviderHelper $coreSsoProviderHelper {
        get { return $this->getSharedExtensionsClass(SsoProviderHelper::class); }
    }

    protected OAuthGrantHelper $coreOAuthGrantHelper {
        get { return $this->getSharedExtensionsClass(OAuthGrantHelper::class); }
    }

    protected BrowserSessionPropertyHelper $coreBrowserSessionPropertyHelper {
        get { return $this->getSharedExtensionsClass(BrowserSessionPropertyHelper::class); }
    }

    
}
