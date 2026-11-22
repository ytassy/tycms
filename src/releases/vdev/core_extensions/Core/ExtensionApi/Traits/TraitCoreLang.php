<?php

namespace YTassy\Tycms\CoreExts\Core\ExtensionApi\Traits;

trait TraitCoreLang
{
    
    protected function _(?string $code = null): string {
        if ($code == null || trim($code) == '') return '';
        
        // Les pages publiques admises sans choix de Space utilisent le même repli que la template.
        $spaceId = $this->coreSpaceHelper->getCurrentSpace()->id;
        $locale = $this->corePublicLocaleHelper->locale()
            ?? $this->coreSpaceLanguageHelper->getDefaultLocale($spaceId);

        return $this->coreLanguageTranslator->resolve($code, $locale, $spaceId);
    }

    
}
