<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\TyCatalog;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\BasePermissions;
use YTassy\Tycms\CoreExts\TyCatalog\Controller\TyCatalogAdminController;
use YTassy\Tycms\CoreExts\TyCatalog\Controller\TyCatalogPublicController;
use YTassy\Tycms\CoreExts\TyCatalog\Controller\TyCatalogPublishApiController;
use YTassy\Tycms\CoreExts\TyCatalog\Migration\Mig_1_0_0;
use YTassy\Tycms\CoreExts\TyCatalog\Migration\Mig_1_0_1;
use YTassy\Tycms\CoreExts\TyCatalog\Migration\Mig_1_0_2;
use YTassy\Tycms\CoreExts\TyCatalog\Migration\Mig_1_0_3;
use YTassy\Tycms\CoreExts\TyCatalog\Migration\Mig_1_0_4;
use YTassy\Tycms\CoreExts\TyCatalog\Migration\Mig_1_0_5;
use YTassy\Tycms\Extensions\BasePlugin;

/** Serveur de catalogues d'extensions ; le client d'installation reste dans Core. */
final class Plugin extends BasePlugin
{
    public function getName(): string
    {
        return 'TyCatalog';
    }

    public function getVersion(): string
    {
        // Les extensions Core partagent la version du système.
        return APP_VERSION;
    }

    public function getDescription(): string
    {
        return 'Catalogue d’extensions TyCMS.';
    }

    public function getIconClass(): string
    {
        return 'fa-solid fa-book-open';
    }

    protected function _getMigrations(): array
    {
        return [
            '1.0.0' => [Mig_1_0_0::class],
            '1.0.1' => [Mig_1_0_1::class],
            '1.0.2' => [Mig_1_0_2::class],
            '1.0.3' => [Mig_1_0_3::class],
            '1.0.4' => [Mig_1_0_4::class],
            '1.0.5' => [Mig_1_0_5::class],
        ];
    }

    public function coreOnRegisterAclPermissions(): ?BasePermissions
    {
        return new Permissions($this);
    }

    public function getControllers(): array
    {
        return [
            new TyCatalogAdminController($this),
            new TyCatalogPublishApiController($this),
            new TyCatalogPublicController($this),
        ];
    }
}
