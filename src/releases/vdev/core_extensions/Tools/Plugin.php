<?php

namespace YTassy\Tycms\CoreExts\Tools;

use Override;
use YTassy\Tycms\Extensions\BasePlugin;
use YTassy\Tycms\Lib\Forms\Form;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\BasePermissions;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\IExtensionEvents;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Menu\MenuItemDef;
use YTassy\Tycms\Response\Response;
use YTassy\Tycms\CoreExts\Core\Controllers\Admin\AdminController;

class Plugin extends BasePlugin implements IExtensionEvents
{

    public function getName(): string
    {
        return "Outils";
    }

    public function getVersion(): string
    {
        return APP_VERSION;
    }

    public function getDescription(): string
    {
        return "Outils divers";
    }

    public function getIconClass(): string
    {
        return "fa-solid fa-puzzle-piece";
    }

    #[Override]
	protected function _getMigrations(): array
	{
		return [
            //'1.0.0' => [ Mig_1_0_0_p1::class, Mig_1_0_0_p2::class],
            //'1.0.2' => [ Mig_1_0_2::class ] 
        ];
	}

    public function coreOnRegisterAclPermissions(): ?BasePermissions {
        return null;
    }


    public function coreLanguageCatalogFiles(): array { return []; }

    public function onBeforeSendResponse(Response $response)
    {
        
    }

    /**
     * @return array<MenuItemDef>
     */
    public function coreOnRegisterMenuTypes(): array{
        return [];
    }

    public function coreRegisterConfigForm(): ?Form {
        return null;

        return new Form($this, [
            'id' => 'plugin.core_tools.config',
            'version' => 1,
            'sections' => [[
                'id' => 'general',
                'label' => 'Général',
                'fields' => [
                    //
                ],
            ]],
        ]);
    }
}
