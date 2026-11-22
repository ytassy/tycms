<?php

namespace YTassy\Tycms\CoreExts\Core\ExtensionApi;

use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Template\VueMode;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Traits\TraitCoreSharedHelpers;
use YTassy\Tycms\Exceptions\TechException;
use YTassy\Tycms\Tools\StringTools;

class BaseView extends BaseApplicationItem
{
    use TraitCoreSharedHelpers;

    public function __construct(
        BaseApplicationItem $app,
        public readonly bool $container = true,
        public readonly bool $containerFluid = false,
        public readonly VueMode $vueMode = VueMode::FULL_BODY)
    {
        parent::__construct($app);

    }
    
    /**
     * Donne le Path du fichier de template.
     * Cette méthode peut être surchargée.
     */
    protected function getTemplatePath(){
        return $this->_classDir . '/tmpl/' . StringTools::toSnakeCase($this->_classFileName);
    }

    public function render() : string
    {
        $tmplFile = $this->getTemplatePath();

        if (file_exists($tmplFile) == false) {
           throw new TechException("Template file not found for View " . get_class($this));
        }

        
        // Exécution de la template
        $res = (function(string $fichier, array $variables): string {
            extract($variables, EXTR_SKIP);
            
            ob_start();
            include $fichier;
            return ob_get_clean();
        })($tmplFile, array_merge(get_object_vars($this), [
            "_" => fn(?string $code = null): string => $this->_($code),
        ]));

        
        return $res;
    }



    
    
}
