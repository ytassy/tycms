<?php

namespace YTassy\Tycms\CoreExts\Security\Traits;

use YTassy\Tycms\CoreExts\Security\Helper\SecurityPolicyHelper;
use YTassy\Tycms\CoreExts\Security\Helper\WafHelper;

/** Accès typé unique aux politiques partagées par le plugin et les composants de page. */
trait TraitSecurityHelpers
{
    protected WafHelper $securityWafHelper {
        get { return $this->getSharedExtensionsClass(WafHelper::class); }
    }

    protected SecurityPolicyHelper $securityPolicyHelper {
        get { return $this->getSharedExtensionsClass(SecurityPolicyHelper::class); }
    }
}
