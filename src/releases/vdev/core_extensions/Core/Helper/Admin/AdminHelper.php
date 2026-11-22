<?php

namespace YTassy\Tycms\CoreExts\Core\Helper\Admin;

use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\Application;
use YTassy\Tycms\DB\FormatIn;
use YTassy\Tycms\Lib\Forms\Form;
use YTassy\Tycms\CoreExts\Core\Models\Config;
use YTassy\Tycms\CoreExts\Core\Models\Menu;
use YTassy\Tycms\CoreExts\Core\Models\Page;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\Tools\ArrayTools;

class AdminHelper extends BaseHelper
{
	public function __construct(BaseApplicationItem|Application $app)
    {
        parent::__construct($app);
       
    }

    /**
     * retourne les Menu que je peux voir et lié à d'autre objets
     * @return array<Menu>
     */
    public function getMenusICanlinkForEditors(): array{

        $spaces = $this->coreAclHelper->getSpacesHavingGlobalPermission(Permissions::MENU_READ);
        $menus = [];

        foreach($spaces as $spaceId)
        {
            $menus = array_merge($menus, $this->coreMenuHelper->getMenusAnItemsOfSpace($spaceId));
        }

        return $menus;
    }

    /**
     * retourne les Pages que je peux voir et lier à d'autre objets
     * @return array<Page>
     */
    public function getPagesICanlinkForEditors(): array{

        $spaces = $this->coreAclHelper->getSpacesHavingGlobalPermission(Permissions::PAGE_READ);
        $pages = [];

        foreach($spaces as $spaceId)
        {
            $pages = array_merge($pages,
                $this->corePageHelper->getPageMetadataByIdForSpace($spaceId));
        }

        return $pages;
    }


}
