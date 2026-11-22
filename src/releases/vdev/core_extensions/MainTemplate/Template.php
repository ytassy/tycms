<?php

namespace YTassy\Tycms\CoreExts\MainTemplate;

use Configuration;
use Override;
use YTassy\Tycms\Extensions\EmailRenderContext;
use YTassy\Tycms\Lib\Forms\Form;
use YTassy\Tycms\CoreExts\Core\Helper\Page\Renderer\PageRenderResult;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\BasePermissions;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseTemplate;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Template\ContentTemplate;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\IExtensionEvents;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Traits\TraitCoreSharedHelpers;
use YTassy\Tycms\CoreExts\MainTemplate\Controllers\CssController;
use YTassy\Tycms\CoreExts\MainTemplate\Controllers\AdminEmailTestController;
use YTassy\Tycms\Response\ExceptionResponse;

class Template extends BaseTemplate implements IExtensionEvents
{

    public function getName(): string
    {
        return "Template principale";
    }

    /** Variables globales de couleur ; les valeurs restent définies par le CSS du thème. */
    public function getEditorColorVariables(): array
    {
        return [
            ['variable' => '--bs-primary', 'label' => 'Principale'],
            ['variable' => '--bs-secondary', 'label' => 'Secondaire'],
            ['variable' => '--bs-success', 'label' => 'Succès'],
            ['variable' => '--bs-info', 'label' => 'Information'],
            ['variable' => '--bs-warning', 'label' => 'Avertissement'],
            ['variable' => '--bs-danger', 'label' => 'Danger'],
            ['variable' => '--bs-body-color', 'label' => 'Texte'],
            ['variable' => '--bs-heading-color', 'label' => 'Titres'],
            ['variable' => '--theme-page-bg', 'label' => 'Fond de page (body)'],
            ['variable' => '--bs-body-bg', 'label' => 'Fond des composants'],
            ['variable' => '--bs-tertiary-bg', 'label' => 'Fond des surfaces claires'],
            ['variable' => '--bs-secondary-bg', 'label' => 'Fond des surfaces secondaires'],
            ['variable' => '--theme-header-bg', 'label' => 'Fond du header'],
            ['variable' => '--theme-header-color', 'label' => 'Texte du header'],
            ['variable' => '--theme-subheader-bg', 'label' => 'Fond du bandeau du titre'],
            ['variable' => '--theme-subheader-color', 'label' => 'Texte du bandeau du titre'],
            ['variable' => '--theme-footer-bg', 'label' => 'Fond du footer'],
            ['variable' => '--theme-footer-color', 'label' => 'Texte du footer'],
            ['variable' => '--bs-border-color', 'label' => 'Bordures'],
        ];
    }

    #[Override]
    public function prepareConfigForEditor(int $spaceId, array $params): array
    {
        // Les anciens choix sont remplacés avant la validation des nouvelles options.
        foreach (['theme_font_family', 'theme_heading_font_family'] as $name) {
            if (isset($params[$name]) && is_string($params[$name])) {
                $params[$name] = $this->coreFontHelper->normalizeId($params[$name]);
            }
        }

        // Initialiser le nouveau champ avant que le formulaire ajoute son défaut blanc.
        // La première sauvegarde conserve ainsi le rendu des configurations existantes.
        $params['theme_components_bg'] ??= $params['theme_body_bg'] ?? '#FFFFFF';

        // Ces zones utilisaient jusqu'ici le fond des surfaces claires.
        $params['theme_header_bg'] ??= $params['theme_surface_bg'] ?? '#F8F9FA';
        $params['theme_subheader_bg'] ??= $params['theme_surface_bg'] ?? '#F8F9FA';
        $params['theme_subheader_color'] ??= $params['theme_heading_color'] ?? '#1A1A1A';
        $params['theme_footer_bg'] ??= $params['theme_surface_bg'] ?? '#F8F9FA';
        $params['theme_header_color'] ??= $params['theme_body_color'] ?? '#1A1A1A';

        // Initialiser les couleurs de menu avant les défauts statiques du formulaire.
        $params['theme_menu_active_bg'] ??= $params['theme_header_bg'];
        $params['theme_menu_hover_color'] ??= $params['theme_header_color'];
        $params['theme_menu_active_color'] ??= $params['theme_header_color'];

        return $params;
    }

    public function getVersion(): string
    {
        return APP_VERSION;
    }

    public function getIconClass(): string
    {
        return "fa-solid fa-palette";
    }
    
    #[Override]
	protected function _getMigrations(): array
	{
		return [
            //'1.0.2' => [ Mig_1_0_2::class ] 
        ];
	}

    public function getControllers(): array
    {
        return [
            new CssController($this),
            new AdminEmailTestController($this),
        ];
    }

    public function coreOnRegisterMenuTypes(): array { return []; }

    public function coreOnRegisterAclPermissions(): ?BasePermissions { return null; }


    public function coreLanguageCatalogFiles(): array
    {
        return [
            'fr' => __DIR__ . '/Languages/fr.php',
            'en' => __DIR__ . '/Languages/en.php',
        ];
    }

    public function coreRegisterConfigForm(): ?Form {
        $conf = new Config($this);
        return $conf->getConfigForm();
    }

    /** Le menu principal et le pied de page restent dans le Space configuré ou ses parents. */
    public function assertConfigSubmissionAllowed(int $spaceId, object $params): void
    {
        $allowedSpaceIds = $this->coreSpaceHelper->getSpaceIdsWithParents($spaceId);

        $mainMenuId = $params->main_menu_id ?? null;
        if ($mainMenuId !== null) {
            $menu = null;
            foreach ($allowedSpaceIds as $allowedSpaceId) {
                foreach ($this->coreMenuHelper->getMenusAnItemsOfSpace($allowedSpaceId) as $candidate) {
                    if ($candidate->id === (int) $mainMenuId) {
                        $menu = $candidate;
                        break 2;
                    }
                }
            }
            if ($menu === null) {
                ExceptionResponse::throwWarning('Le menu principal doit appartenir à cet espace ou à un parent.');
            }
        }

        $footerPageId = $params->footer_page_id ?? null;
        if ($footerPageId !== null) {
            $page = $this->corePageHelper->getPage((int) $footerPageId);
            if ($page === null || $page->status !== 1
                || !in_array($page->space_id, $allowedSpaceIds, true)) {
                ExceptionResponse::throwWarning('La page de pied de page doit être publiée dans cet espace ou un parent.');
            }
        }
    }


    public function render(mixed $content, object $templateParams): string
    {
        $messages = $this->pendingMessageQueue->flush();

        [$currentMenu, $currentMenuItem] = $this->coreMenuHelper->getCurrentMenu();

        $space = $this->coreSpaceHelper->getCurrentSpace();
        $publicLocale = $this->corePublicLocaleHelper->locale() ?? $this->coreSpaceLanguageHelper->getDefaultLocale($space->id);
        $homeUrl = $this->corePublicLocaleHelper->publicPath('/') ?? '/';

        $conf = new Configuration();

        // Exécution de la template
        return (function(string $file, array $variables): string {
            extract($variables, EXTR_SKIP);
            
            ob_start();
            include $file;
            return ob_get_clean();
        })(__DIR__ . '/base.php', [
            'content' => $content,
            'messages' => $messages,
            'params' => $templateParams,
            'currentMenu' => $currentMenu,
            'currentMenuItem' => $currentMenuItem,
            'space' => $space,
            'publicLocale' => $publicLocale,
            'homeUrl' => $homeUrl,
            'global_conf' => $conf,
            'vueGlobalConf' => $this->getRiaGlobalVariable(),
            '_' => function($code){
                return $this->_($code);
            }

        ]);

    }

    public function renderEmail(
        string $content,
        object $templateParams,
        string $mode,
        EmailRenderContext $context,
    ): string {
        return EmailRenderer::render($content, $templateParams, $mode, $context);
    }
    

}
