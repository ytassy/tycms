<?php

return new class extends \Adminer\Plugin {
    public function css()
    {
        // Conserver les feuilles natives, puis ajouter les surcharges personnelles.
        $styles = (new \Adminer\Adminer())->css();
        $version = filemtime(__DIR__ . '/../style.css');
        $styles['style.css?v=' . $version] = '';

        return $styles;
    }

    public function head($dark = null)
    {
        // Réutiliser le nonce CSP et renouveler l’URL après une modification du fichier.
        $version = filemtime(__DIR__ . '/../script.js');
        echo \Adminer\script_src('script.js?v=' . $version, true);

        return null;
    }
};
