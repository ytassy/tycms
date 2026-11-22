<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\TyCatalog\Traits;

use YTassy\Tycms\CoreExts\TyCatalog\Helper\TyCatalogAdminHelper;
use YTassy\Tycms\CoreExts\TyCatalog\Helper\TyCatalogDownload;
use YTassy\Tycms\CoreExts\TyCatalog\Helper\TyCatalogPublicationHelper;
use YTassy\Tycms\CoreExts\TyCatalog\Helper\TyCatalogPublicHelper;
use YTassy\Tycms\CoreExts\TyCatalog\Helper\TyCatalogStorage;
use YTassy\Tycms\CoreExts\TyCatalog\Helper\TyCatalogTokenHelper;

trait TraitTyCatalogHelpers
{
    protected TyCatalogAdminHelper $tyCatalogAdminHelper {
        get { return $this->getSharedExtensionsClass(TyCatalogAdminHelper::class); }
    }

    protected TyCatalogPublicationHelper $tyCatalogPublicationHelper {
        get { return $this->getSharedExtensionsClass(TyCatalogPublicationHelper::class); }
    }

    protected TyCatalogStorage $tyCatalogStorage {
        get { return $this->getSharedExtensionsClass(TyCatalogStorage::class); }
    }

    protected TyCatalogDownload $tyCatalogDownload {
        get { return $this->getSharedExtensionsClass(TyCatalogDownload::class); }
    }

    protected TyCatalogTokenHelper $tyCatalogTokenHelper {
        get { return $this->getSharedExtensionsClass(TyCatalogTokenHelper::class); }
    }

    protected TyCatalogPublicHelper $tyCatalogPublicHelper {
        get { return $this->getSharedExtensionsClass(TyCatalogPublicHelper::class); }
    }
}
