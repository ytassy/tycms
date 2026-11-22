<?php

namespace YTassy\Tycms\CoreExts\Core\ExtensionApi;

use YTassy\Tycms\CoreExts\Core\Helper\Page\Renderer\PageRenderResult;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseView;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Template\ContentTemplate;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Template\TemplateResponse;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Traits\TraitCoreSharedHelpers;
use YTassy\Tycms\Response\HtmlResponse;
use YTassy\Tycms\Routing\Controller\BaseController as ControllerBaseController;

class BaseController extends ControllerBaseController
{
    use TraitCoreSharedHelpers;

    protected function renderAndGetTemplateResponse(string|PageRenderResult|ContentTemplate $content, ?int $spaceId = null) : TemplateResponse{

        $templateName = $this->coreSpaceHelper->getCurrentSpace()->template;
        
        [$conf, $template] = $this->coreTemplateHelper->getTemplateAndExtension($templateName, $spaceId);

        // Convert PageRenderResult => ContentTemplate
        if ($content instanceof PageRenderResult) {
            $content = new ContentTemplate(
                $content->getHtml(false),
                $content->container,
                $content->containerFluid
            );
        }

        $response = new TemplateResponse($content, $template, $conf->params);
        return $response;
    }

    protected function getViewResponse(BaseView $view, bool $useTemplate = true): HtmlResponse
    {
        $html = $view->render();

        if ($useTemplate){

            if ($view instanceof BaseView) {
                return $this->renderAndGetTemplateResponse(new ContentTemplate(
                    $html, $view->container, $view->containerFluid, $view->vueMode
                ));
            } else {
                return $this->renderAndGetTemplateResponse($html);
            }
        }

        return new HtmlResponse($html);
    }
}

