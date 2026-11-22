<?php

namespace YTassy\Tycms\CoreExts\Matrix;

use Override;
use YTassy\Tycms\Extensions\BasePlugin;
use YTassy\Tycms\Lib\Forms\Form;
use YTassy\Tycms\CoreExts\Core\Helper\TraitAllHelpers;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\BasePermissions;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\IExtensionEvents;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Menu\MenuItemDef;
use YTassy\Tycms\CoreExts\Matrix\Controllers\MatrixController;

class Plugin extends BasePlugin implements IExtensionEvents
{
    use TraitAllHelpers;

    public function getName(): string
    {
        return "Matrix";
    }

    public function getVersion(): string
    {
        return APP_VERSION;
    }

    public function getDescription(): string
    {
        
        return "Communication au protocole Matrix";
    }

    public function getIconClass(): string
    {
        return "fa-solid fa-mobile-screen";
    }

    #[Override]
	protected function _getMigrations(): array
	{
		return [
            //'1.0.0' => [ Mig_1_0_0_p1::class, Mig_1_0_0_p2::class],
            //'1.0.2' => [ Mig_1_0_2::class ] 
        ];
	}

    public function getControllers(): array
    {
        return [
            //new MatrixController($this),
        ];
    }


    public function coreOnRegisterAclPermissions(): ?BasePermissions {
        return null;
    }


    public function coreLanguageCatalogFiles(): array { return []; }

    /**
     * @return array<MenuItemDef>
     */
    public function coreOnRegisterMenuTypes(): array{
        return [];
    }

    public function coreRegisterConfigForm(): ?Form {
        return null;
    }

}
