<?php

use YTassy\Tycms\Tools\HtmlTools;

/** @var string $vueComponent */
/** @var array $vueProps */
/** @var string $vueBootstrapId */
/** @var string $adminUi */

$vuePropsJson = json_encode(
    $vueProps,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
);

?>
<div
    class="vue-app"
    data-vue-app
    data-component="<?= HtmlTools::escape($vueComponent) ?>"
    data-bootstrap-id="<?= HtmlTools::escape($vueBootstrapId) ?>"
    <?php if ($adminUi !== '') { ?>data-admin-ui="<?= HtmlTools::escape($adminUi) ?>"<?php } ?>
></div>

<script id="app-bootstrap-<?= HtmlTools::escape($vueBootstrapId) ?>" type="application/json"><?= $vuePropsJson ?></script>
