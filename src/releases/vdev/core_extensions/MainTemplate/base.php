<?php

use YTassy\Tycms\CoreExts\Core\Models\Menu;
use YTassy\Tycms\CoreExts\Core\Models\MenuItem;
use YTassy\Tycms\CoreExts\Core\Models\Space;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Template\ContentTemplate;
use YTassy\Tycms\CoreExts\MainTemplate\Template;

/** @var string|ContentTemplate $content */
/** @var object $params */
/** @var array $messages */
/** @var Template $this */
/** @var ?Menu $currentMenu */
/** @var ?MenuItem $currentMenuItem */
/** @var Space $space */
/** @var Configuration $global_conf */
/** @var array $vueGlobalConf */
/** @var (string $code) $_ */



use YTassy\Tycms\Response\RespLevel;
use YTassy\Tycms\Tools\HtmlTools;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Template\VueMode;
if (isset($content) == false) return;

$logo_url = $params->logo;
$favicon_url = $params->favicon;

// Un choix vide désactive le menu ou le pied de page pour ce Space.
$mainMenuId = $params->main_menu_id ?? null;
$mainMenuHtml = $mainMenuId === null ? '' : $this->coreMenuHelper->getHtml($mainMenuId, 'main', true);

$footerPage = ($params->footer_page_id ?? null) === null
    ? null : $this->corePageHelper->renderPage($params->footer_page_id);

$spaceParams = $space->params;

$browserTitle = $currentMenuItem?->browser_title ?: ($spaceParams->page_browser_title ?? $global_conf->site_name);
$metaDescription = $currentMenuItem?->meta_description ?: ($spaceParams->page_browser_description ?? '');
$metaRobots = $currentMenuItem?->meta_robots ?: ($spaceParams->page_meta_robots ?? '');

$vueMode = VueMode::FULL_BODY;
$vueComponent = null;
$vueProps = null;

if ($content instanceof ContentTemplate){
    $vueMode = $content->vueMode;
}

if ($vueComponent == null) $vueComponent = '';

$bodyVueAttributes = '';
$headerVueAttributes = '';
$bodyScriptVue = '';

//class="vue-app"
//data-component="<?= HtmlTools::escape($vueComponent)"

if ($vueMode == VueMode::FULL_BODY) {
    $vueBootstrapId = bin2hex(random_bytes(8));
    $bodyVueAttributes = 'data-vue-app';
}

if ($vueMode == VueMode::HEADER_ONLY) {
    $vueBootstrapId = bin2hex(random_bytes(8));
    $headerVueAttributes = 'data-vue-app';
}

?><!DOCTYPE html>
<html lang="<?= HtmlTools::escape($publicLocale) ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($browserTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <!-- CSS / Métadonnées -->
    <link rel="stylesheet" href="<?= ASSETS_CORE_PATH ?>/plg_core/mermaid/style.css">
    <link rel="icon" type="image/png" href="<?= HtmlTools::escape($favicon_url) ?>">
    <link rel="stylesheet" href="<?= ASSETS_CORE_PATH ?>/tpl_main/fa/css/fontawesome.min.css">
    <link rel="stylesheet" href="<?= ASSETS_CORE_PATH ?>/tpl_main/fa/css/solid.min.css">
    <link rel="stylesheet" href="<?= ASSETS_CORE_PATH ?>/tpl_main/fa/css/regular.min.css">
    <link rel="stylesheet" href="<?= ASSETS_CORE_PATH ?>/tpl_main/fa/css/brands.min.css">

    <?php if ($metaDescription !== '') { ?>
        <meta name="description" content="<?= HtmlTools::escape($metaDescription) ?>">
    <?php } ?>
    <?php if ($metaRobots !== '') { ?>
        <meta name="robots" content="<?= HtmlTools::escape($metaRobots) ?>">
    <?php } ?>

    <script src="<?= ASSETS_CORE_PATH ?>/tpl_main/js/bootstrap.bundle.min.js"></script>
    <link rel="stylesheet" href="<?= ASSETS_CORE_PATH ?>/plg_core/videojs/video-js-8.23.4.css">
    <script src="<?= ASSETS_CORE_PATH ?>/plg_core/videojs/video-8.23.4.min.js"></script>

    <?= $this->coreFrontendAssets->renderTags() ?>
    <?php if (!$global_conf->dev) { ?>
        <link rel="stylesheet" href="/templates/main/css">
    <?php } ?>

</head>

<body <?= $bodyVueAttributes ?>>


    <script id="app-bootstrap" type="application/json">
        <?= json_encode($vueGlobalConf,
            (($global_conf->dev === true || $global_conf->debug === true) ? JSON_PRETTY_PRINT : 0)
            | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) ?></script>

    <!-- Header / Navigation -->
    <header class="main-template-header" <?= $headerVueAttributes ?>>
        <nav class="navbar navbar-expand-lg border-bottom">
            <div class="container">

                <!-- Logo à gauche -->
                <a class="navbar-brand d-flex align-items-center" href="<?= HtmlTools::escape($homeUrl) ?>">
                    <img src="<?= HtmlTools::escape($logo_url) ?>" alt="Logo">
                </a>

                <?php if ($mainMenuId === null) { ?>
                    <span>Sélectionnez un menu dans la configuration de la template</span>
                <?php } else { ?>
                    <!-- Bouton Burger et menu aligné à droite -->
                    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarContent" aria-controls="navbarContent" aria-expanded="false" aria-label="Toggle navigation">
                        <span class="navbar-toggler-icon"></span>
                    </button>
                    <div class="collapse navbar-collapse" id="navbarContent">
                        <?php echo $mainMenuHtml; ?>
                    </div>
                <?php } ?>

            </div>
        </nav>
    </header>

    <?php if ($currentMenuItem !== null && $currentMenuItem->page_title !== '') { ?>
        <!-- Sous-header : titre de la page courante -->
        <section class="main-template-subheader border-bottom py-2">
            <div class="container">
                <h1 class="h2 mb-0"><?= htmlspecialchars($currentMenuItem->page_title, ENT_QUOTES, 'UTF-8') ?></h1>
            </div>
        </section>
    <?php } ?>

    <?php
        $containerClass = 'my-5 ';

        if ($content instanceof ContentTemplate)
        {
            if ($content->container == false)
            {
                $containerClass = '';
            } else {
                $containerClass .= $content->containerFluid ? ' container-fluid' : ' container';
            }
        } else {
            $containerClass .= ' container';
        }
    ?>
    <!-- Contenu principal -->
    <main>
        <?php if ($containerClass != '') echo '<div class="' . $containerClass . '">';?>

        <?php foreach ($messages as $item) { ?>
            <?php
                // Extraire le type (gestion si c'est un Enum RespLevel ou une string)
                $rawType = $item['type'] instanceof RespLevel ? $item['type']->value : (string) $item['type'];

                // Mapper les niveaux vers les classes Bootstrap
                $class = match (strtoupper($rawType)) {
                    'SUCCESS'        => 'alert-success',
                    'WARNING'        => 'alert-warning',
                    'DANGER', 'ERROR'=> 'alert-danger',
                    default          => 'alert-info',
                };
            ?>
            <div class="alert <?= $class ?> mb-4" role="alert">
                <?= htmlspecialchars((string) $item['message'], ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php } ?>

        <?php
            if ($content instanceof ContentTemplate) echo $content->getHtml(false);
            else echo $content;
        ?>
        <?php if ($containerClass != '') echo '</div>';?>
    </main>

    <?php if ($footerPage !== null) { ?>
        <!-- Footer -->
        <footer class="main-template-footer border-top pt-5 pb-3 mt-auto">
            <?php echo $footerPage->getHtml(true); ?>
        </footer>
    <?php } ?>

    <?php if ($global_conf->dev) { ?>
        <link rel="stylesheet" href="/templates/main/css">
    <?php }?>
</body>

</html>
