<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\TyCatalog\Traits;

use YTassy\Tycms\CoreExts\TyCatalog\WF\WFTyCatalogAdmin;
use YTassy\Tycms\CoreExts\TyCatalog\WF\WFTyCatalogPublication;

trait TraitTyCatalogWF
{
    protected WFTyCatalogAdmin $wfTyCatalogAdmin {
        get { return $this->getSharedExtensionsClass(WFTyCatalogAdmin::class); }
    }

    protected WFTyCatalogPublication $wfTyCatalogPublication {
        get { return $this->getSharedExtensionsClass(WFTyCatalogPublication::class); }
    }
}
