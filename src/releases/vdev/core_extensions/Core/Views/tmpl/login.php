<?php
/** @var array $authProps */
use YTassy\Tycms\Tools\HtmlTools;
$authJson = json_encode($authProps, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<div class="card card-login shadow-sm border-0" style="max-width:600px">
    <div class="card-body p-4 p-sm-5">
        <h1 class="h4 fw-bold mb-3">Connexion de secours</h1>
        <div data-page-vue="core/authentication" data-page-props="<?= HtmlTools::escape($authJson) ?>">
            <p role="status">Chargement du formulaire…</p>
            <noscript>Activez JavaScript pour vous connecter.</noscript>
        </div>
    </div>
</div>
