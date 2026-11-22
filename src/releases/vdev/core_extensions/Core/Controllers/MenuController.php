<?php

namespace YTassy\Tycms\CoreExts\Core\Controllers;

use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\Application;
use Override;
use YTassy\Tycms\CoreExts\Core\Models\Menu;
use YTassy\Tycms\CoreExts\Core\Models\MenuItem;
use YTassy\Tycms\CoreExts\Core\Views\Login;
use YTassy\Tycms\Response\HtmlResponse;
use YTassy\Tycms\Response\RedirectResponse;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseController;

class MenuController extends BaseController
{
    private Menu $menu;
    private MenuItem $menuItem;
    

    public function __construct(BaseApplicationItem|Application $app)
    {
        parent::__construct($app);

    }
    
    public function menu()
    {
        /*$menuItem = $this->menuItem;
        
        if ($menuItem->extension == 'core')
        {
            if ($menuItem->type == 'page') {
                $pageId = $menuItem->params['page_id'];
                $html = $this->corePageHelper->renderPage($pageId);

                return new HtmlResponse($html);
            } else if ($menuItem->type == 'login') {
                

            } else if ($menuItem->type == 'logout') {
                
            
            }
        }*/
    }

}
