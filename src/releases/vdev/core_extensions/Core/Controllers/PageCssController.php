<?php

namespace YTassy\Tycms\CoreExts\Core\Controllers;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseController;
use YTassy\Tycms\Response\CssResponse;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Routing\Annotation\Doc\Controller;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerMethod;

#[Controller(name: 'page_css_', isApi: false, path: 'pages')]
class PageCssController extends BaseController
{
    #[ControllerMethod(name: 'get', path: '{id}/css', where: ['id' => '\d+'])]
    public function get(int $id): CssResponse
    {
        $page = $this->corePageHelper->getPage($id);
        if ($page === null) ExceptionResponse::throwException('Page introuvable.');
        // Les styles publics suivent le statut de la page ; l'aperçu fournit ses propres styles.
        return new CssResponse($page->status === 1 ? (string) $page->raw_css : '');
    }
}
