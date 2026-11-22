<?php

namespace YTassy\Tycms\CoreExts\Core\Controllers;

use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\Application;
use Override;
use YTassy\Tycms\CoreExts\Core\Models\Menu;
use YTassy\Tycms\CoreExts\Core\Models\MenuItem;
use YTassy\Tycms\CoreExts\Core\Views\Login;
use YTassy\Tycms\Response\HtmlResponse;
use YTassy\Tycms\Exceptions\NotFoundException;
use YTassy\Tycms\Response\RedirectResponse;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseController;
use YTassy\Tycms\CoreExts\Core\Views\Admin\Home;
use YTassy\Tycms\Routing\Annotation\Doc\Controller;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerMethod;
use YTassy\Tycms\Routing\Route;

class PageController extends BaseController
{
    
    public function page(Route $_route, array $_route_data)
    {
        $menuItem = $_route_data['menu_item'];
        $menu = $_route_data['menu'];

        $pageId = $menuItem->params->page_id;

        // Une entrée de menu ne rend que la page publiée de son propre Space.
        $page = is_int($pageId) ? $this->corePageHelper->getPage($pageId) : null;
        if ($page === null || $page->status !== 1
            || ($menu !== null && $page->space_id !== $menu->space_id)) {
            throw new NotFoundException();
        }

        $content = $this->corePageHelper->renderPage($pageId);

        return $this->renderAndGetTemplateResponse($content);
        
    }

}
