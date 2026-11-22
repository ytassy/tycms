<?php

namespace YTassy\Tycms\CoreExts\Core\ExtensionApi;

use YTassy\Tycms\Lib\Forms\Form;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\BasePermissions;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\PermDef;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Menu\MenuItemDef;

interface IExtensionEvents
{

    /**
     * @return array<MenuItemDef>
     */
    public function coreOnRegisterMenuTypes(): array;

    /**
     * @return ?BasePermissions
     */
    public function coreOnRegisterAclPermissions(): ?BasePermissions;


    public function coreRegisterConfigForm(): ?Form;


    /** @return array<string,string> Chemins des catalogues PHP par locale ; fr est la source. */
    public function coreLanguageCatalogFiles(): array;

    
}
