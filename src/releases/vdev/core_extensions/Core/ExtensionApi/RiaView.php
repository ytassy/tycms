<?php

namespace YTassy\Tycms\CoreExts\Core\ExtensionApi;

use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Template\VueMode;

class RiaView extends BaseView
{
    protected string $vueComponent;
    protected array $vueProps;
    protected string $vueBootstrapId;

    /** Variante visuelle de l'administration ; vide pour les autres racines RIA. */
    protected string $adminUi = '';

    public function __construct(BaseApplicationItem $app,
        string $vueComponent,
        array $vueProps = [],
        bool $container = true,
        bool $containerFluid = true)
    {
        parent::__construct($app, $container, $containerFluid, VueMode::HEADER_ONLY);

        $this->vueComponent = $vueComponent;
        $this->vueProps = $vueProps;
        $this->vueBootstrapId = bin2hex(random_bytes(8));
    }
    
    protected function getTemplatePath(){
        return dirname(__DIR__) . '/Views/tmpl/ria.php';
    }

}
