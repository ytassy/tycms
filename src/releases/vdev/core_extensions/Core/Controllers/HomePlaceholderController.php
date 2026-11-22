<?php

namespace YTassy\Tycms\CoreExts\Core\Controllers;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseController;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Template\TemplateResponse;

final class HomePlaceholderController extends BaseController
{
    public function show(): TemplateResponse
    {
        // Cette page n'existe que tant que l'accueil du Space n'est pas choisi.
        return $this->renderAndGetTemplateResponse(
            '<main class="container py-5"><h1>Configurez votre page Home</h1></main>'
        );
    }
}
