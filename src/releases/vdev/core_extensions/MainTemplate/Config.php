<?php

namespace YTassy\Tycms\CoreExts\MainTemplate;

use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\Lib\Forms\CallbackFormComputedProvider;
use YTassy\Tycms\Lib\Forms\Form;
use YTassy\Tycms\Lib\Forms\FormComputedContext;
use YTassy\Tycms\Lib\Scss\ScssCompiler;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Traits\TraitCoreSharedHelpers;
use YTassy\Tycms\Response\ExceptionResponse;

class Config extends BaseApplicationItem
{
    use TraitCoreSharedHelpers;

    private const MAX_CUSTOM_SCSS_BYTES = 100 * 1024;

    private const THEME_COLOR_PALETTE = [
        '#1A6B6B',
        '#2C3E50',
        '#198754',
        '#0DCAF0',
        '#FFC107',
        '#DC3545',
        '#FFFFFF',
        '#1A1A1A',
    ];

    public function getConfigForm(): ?Form{
        //if ($this->coreAclHelper->hasGlobalPermission(Permissions::EXTENSION_EDIT) == false) return null;

        $includeScssCompilerDetails = $this->in_dev || $this->in_debug;
        
        $self = $this;
    /*
    */
        return new Form($this, [
            'id' => 'template.main.config',
            'version' => 13,
            'sections' => [
                [
                    'id' => 'general',
                    'label' => 'Général',
                    'fields' => [
                        [
                            'name' => 'main_menu_id',
                            'type' => 'catalog.core_menu',
                            'default' => null,
                            'placeholder' => 'Aucun menu',
                            'label' => 'Menu principal',
                        ],
                        [
                            'name' => 'logo',
                            'type' => 'core.media_file',
                            'default' => ASSETS_CORE_PATH . '/tpl_main/images/my_logo.webp',
                            'label' => 'Logo',
                            'mediaType' => 'image',
                            'manualInputMode' => 'any',
                        ],
                        [
                            'name' => 'theme_logo_height',
                            'type' => 'core.number',
                            'default' => 40,
                            'label' => 'Hauteur du logo',
                            'description' => 'Hauteur en pixels, entre 20 et 200.',
                            'validation' => ['minimum' => 20, 'maximum' => 200],
                            'ui' => ['step' => 1],
                        ],
                        [
                            'name' => 'favicon',
                            'type' => 'core.media_file',
                            'default' => ASSETS_CORE_PATH.'/tpl_main/images/my_favicon.png',
                            'label' => 'Favicon',
                            'mediaType' => 'image',
                            'manualInputMode' => 'any',
                        ],
                        [
                            'name' => 'footer_page_id',
                            'type' => 'catalog.core_page_item',
                            'default' => null,
                            'placeholder' => 'Aucune page',
                            'label' => 'Page de pied de page',
                        ],
                        
                    ],
                    /*
                    [
                        'type' => 'core.note',
                        'label' => 'Attention',
                        'description' => 'Une donnée de contexte.',
                    ],*/
                ],
                [
                    'id' => 'email_default',
                    'label' => 'E-mail : cadre',
                    'fields' => [
                        [
                            'name' => 'logo_email',
                            'type' => 'core.media_file',
                            'label' => 'Logo e-mail',
                            'mediaType' => 'image',
                        ],
                        [
                            'name' => 'email_logo_max_width', 'type' => 'core.number',
                            'default' => 320, 'label' => 'Largeur maximale du logo (px)',
                            'validation' => ['minimum' => 40, 'maximum' => 600],
                            'ui' => ['step' => 1],
                        ],
                        [
                            'name' => 'email_logo_max_height', 'type' => 'core.number',
                            'default' => 120, 'label' => 'Hauteur maximale du logo (px)',
                            'validation' => ['minimum' => 20, 'maximum' => 300],
                            'ui' => ['step' => 1],
                        ],
                        [
                            'name' => 'email_default_outer_background', 'type' => 'core.color',
                            'default' => '#F0F0F0', 'label' => 'Fond autour du cadre', 'allowAlpha' => false,
                        ],
                        [
                            'name' => 'email_default_content_background', 'type' => 'core.color',
                            'default' => '#FFFFFF', 'label' => 'Fond du contenu', 'allowAlpha' => false,
                        ],
                        [
                            'name' => 'email_default_card_width', 'type' => 'core.number',
                            'default' => 630, 'label' => 'Largeur maximale du cadre (px)',
                            'validation' => ['minimum' => 420, 'maximum' => 800],
                            'ui' => ['step' => 1],
                        ],
                        [
                            'name' => 'email_default_content_padding', 'type' => 'core.number',
                            'default' => 30, 'label' => 'Marge intérieure du contenu (px)',
                            'validation' => ['minimum' => 12, 'maximum' => 60],
                            'ui' => ['step' => 1],
                        ],
                        [
                            'name' => 'email_default_divider_color', 'type' => 'core.color',
                            'default' => '#E6E6E6', 'label' => 'Couleur du séparateur', 'allowAlpha' => false,
                        ],
                        [
                            'name' => 'email_default_footer_background', 'type' => 'core.color',
                            'default' => '#142B5B', 'label' => 'Fond du pied de page', 'allowAlpha' => false,
                        ],
                        [
                            'name' => 'email_default_footer_color', 'type' => 'core.color',
                            'default' => '#FFFFFF', 'label' => 'Texte du pied de page', 'allowAlpha' => false,
                        ],
                        [
                            'name' => 'email_footer_text', 'type' => 'core.text',
                            'default' => '© {space} {year}', 'label' => 'Texte du pied de page',
                            'description' => '{space} et {year} sont remplacés à l’envoi.',
                            'validation' => ['maxLength' => 255],
                        ],
                        [
                            'name' => 'email_footer_url', 'type' => 'core.text',
                            'default' => '/', 'label' => 'Lien du pied de page',
                            'description' => 'Chemin local ou URL HTTPS ; un chemin local utilise le domaine principal du Space.',
                            'validation' => [
                                'maxLength' => 2048,
                                'pattern' => '^(?:/(?!/)[^\s"\x27<>]*|https://[^\s"\x27<>]+)$',
                            ],
                        ],
                        [
                            'name' => 'email_footer_link_text', 'type' => 'core.text',
                            'default' => '', 'label' => 'Libellé du lien',
                            'description' => 'Vide : affiche l’URL complète.',
                            'validation' => ['maxLength' => 255],
                        ],
                    ],
                ],
                [
                    'id' => 'email_blank',
                    'label' => 'E-mail : simple',
                    'fields' => [[
                        'name' => 'email_blank_background', 'type' => 'core.color',
                        'default' => '#FFFFFF', 'label' => 'Couleur de fond', 'allowAlpha' => false,
                    ]],
                ],
                [
                    'id' => 'email_common',
                    'label' => 'E-mail : typographie',
                    'fields' => [
                        [
                            'name' => 'email_body_font', 'type' => 'core.select',
                            'default' => 'arial', 'label' => 'Police du texte',
                            'options' => [
                                'arial' => 'Arial', 'georgia' => 'Georgia', 'verdana' => 'Verdana',
                                'tahoma' => 'Tahoma', 'trebuchet' => 'Trebuchet MS',
                            ],
                        ],
                        [
                            'name' => 'email_heading_font', 'type' => 'core.select',
                            'default' => 'arial', 'label' => 'Police des titres',
                            'options' => [
                                'arial' => 'Arial', 'georgia' => 'Georgia', 'verdana' => 'Verdana',
                                'tahoma' => 'Tahoma', 'trebuchet' => 'Trebuchet MS',
                            ],
                        ],
                        [
                            'name' => 'email_body_size', 'type' => 'core.number',
                            'default' => 16, 'label' => 'Taille du texte (px)',
                            'validation' => ['minimum' => 12, 'maximum' => 24], 'ui' => ['step' => 1],
                        ],
                        [
                            'name' => 'email_heading_size', 'type' => 'core.number',
                            'default' => 24, 'label' => 'Taille des titres (px)',
                            'validation' => ['minimum' => 16, 'maximum' => 40], 'ui' => ['step' => 1],
                        ],
                        [
                            'name' => 'email_line_height', 'type' => 'core.number',
                            'default' => 1.5, 'label' => 'Interligne',
                            'validation' => ['minimum' => 1, 'maximum' => 2.5], 'ui' => ['step' => 0.05],
                        ],
                        [
                            'name' => 'email_text_color', 'type' => 'core.color',
                            'default' => '#222222', 'label' => 'Couleur du texte', 'allowAlpha' => false,
                        ],
                        [
                            'name' => 'email_heading_color', 'type' => 'core.color',
                            'default' => '#142B5B', 'label' => 'Couleur des titres', 'allowAlpha' => false,
                        ],
                        [
                            'name' => 'email_link_color', 'type' => 'core.color',
                            'default' => '#0066CC', 'label' => 'Couleur des liens', 'allowAlpha' => false,
                        ],
                    ],
                ],
                [
                    'id' => 'theme_colors',
                    'label' => 'Thème : couleurs',
                    'fields' => [
                        // Les repères visuels ne portent aucune valeur enregistrée.
                        [
                            'type' => 'core.note',
                            'label' => 'Palette du thème',
                        ],
                        [
                            'name' => 'theme_primary',
                            'type' => 'core.color',
                            'default' => '#1A6B6B',
                            'label' => 'Couleur primaire',
                            'allowAlpha' => false,
                            'palette' => self::THEME_COLOR_PALETTE,
                        ],
                        [
                            'name' => 'theme_secondary',
                            'type' => 'core.color',
                            'default' => '#2C3E50',
                            'label' => 'Couleur secondaire',
                            'allowAlpha' => false,
                            'palette' => self::THEME_COLOR_PALETTE,
                        ],
                        [
                            'name' => 'theme_success',
                            'type' => 'core.color',
                            'default' => '#198754',
                            'label' => 'Couleur de succès',
                            'allowAlpha' => false,
                            'palette' => self::THEME_COLOR_PALETTE,
                        ],
                        [
                            'name' => 'theme_info',
                            'type' => 'core.color',
                            'default' => '#0DCAF0',
                            'label' => 'Couleur d’information',
                            'allowAlpha' => false,
                            'palette' => self::THEME_COLOR_PALETTE,
                        ],
                        [
                            'name' => 'theme_warning',
                            'type' => 'core.color',
                            'default' => '#FFC107',
                            'label' => 'Couleur d’avertissement',
                            'allowAlpha' => false,
                            'palette' => self::THEME_COLOR_PALETTE,
                        ],
                        [
                            'name' => 'theme_danger',
                            'type' => 'core.color',
                            'default' => '#DC3545',
                            'label' => 'Couleur de danger',
                            'allowAlpha' => false,
                            'palette' => self::THEME_COLOR_PALETTE,
                        ],

                        [
                            'type' => 'core.note',
                            'label' => 'Page et composants',
                        ],
                        [
                            'name' => 'theme_body_bg',
                            'type' => 'core.color',
                            'default' => '#FFFFFF',
                            'label' => 'Fond de page (body)',
                            'description' => 'Couleur appliquée uniquement au fond du body.',
                            'allowAlpha' => false,
                            'palette' => self::THEME_COLOR_PALETTE,
                        ],
                        [
                            'name' => 'theme_body_color',
                            'type' => 'core.color',
                            'default' => '#1A1A1A',
                            'label' => 'Couleur du texte',
                            'allowAlpha' => false,
                            'palette' => self::THEME_COLOR_PALETTE,
                        ],
                        [
                            'name' => 'theme_heading_color',
                            'type' => 'core.color',
                            'default' => '#1A1A1A',
                            'label' => 'Couleur des titres',
                            'allowAlpha' => false,
                            'palette' => self::THEME_COLOR_PALETTE,
                        ],
                        [
                            'name' => 'theme_components_bg',
                            'type' => 'core.color',
                            'default' => '#FFFFFF',
                            'label' => 'Fond des composants',
                            'description' => 'Fond de base Bootstrap : champs, cartes, modales et composants utilisant cette couleur.',
                            'allowAlpha' => false,
                            'palette' => self::THEME_COLOR_PALETTE,
                        ],
                        [
                            'name' => 'theme_surface_bg',
                            'type' => 'core.color',
                            'default' => '#F8F9FA',
                            'label' => 'Fond des surfaces claires',
                            'allowAlpha' => false,
                            'palette' => self::THEME_COLOR_PALETTE,
                        ],
                        [
                            'name' => 'theme_surface_alt_bg',
                            'type' => 'core.color',
                            'default' => '#E9ECEF',
                            'label' => 'Fond des surfaces secondaires',
                            'allowAlpha' => false,
                            'palette' => self::THEME_COLOR_PALETTE,
                        ],
                        [
                            'name' => 'theme_border_color',
                            'type' => 'core.color',
                            'default' => '#DEE2E6',
                            'label' => 'Couleur des bordures',
                            'allowAlpha' => false,
                            'palette' => self::THEME_COLOR_PALETTE,
                        ],

                        [
                            'type' => 'core.note',
                            'label' => 'Header',
                        ],
                        [
                            'name' => 'theme_header_bg',
                            'type' => 'core.color',
                            'default' => '#F8F9FA',
                            'label' => 'Fond du header',
                            'allowAlpha' => false,
                            'palette' => self::THEME_COLOR_PALETTE,
                        ],
                        [
                            'name' => 'theme_header_color',
                            'type' => 'core.color',
                            'default' => '#1A1A1A',
                            'label' => 'Couleur du texte du header',
                            'allowAlpha' => false,
                            'palette' => self::THEME_COLOR_PALETTE,
                        ],

                        [
                            'type' => 'core.note',
                            'label' => 'Menu',
                        ],
                        [
                            'name' => 'theme_menu_hover_bg',
                            'type' => 'core.color',
                            'default' => '#FFFFFF',
                            'label' => 'Menu : couleur de fond au survol',
                            'allowAlpha' => false,
                            'palette' => self::THEME_COLOR_PALETTE,
                        ],
                        [
                            'name' => 'theme_menu_hover_color',
                            'type' => 'core.color',
                            'default' => '#1A1A1A',
                            'label' => 'Menu : couleur du texte au survol',
                            'allowAlpha' => false,
                            'palette' => self::THEME_COLOR_PALETTE,
                        ],
                        [
                            'name' => 'theme_menu_active_bg',
                            'type' => 'core.color',
                            'default' => '#F8F9FA',
                            'label' => 'Menu : couleur de fond à l’état actif',
                            'allowAlpha' => false,
                            'palette' => self::THEME_COLOR_PALETTE,
                        ],
                        [
                            'name' => 'theme_menu_active_color',
                            'type' => 'core.color',
                            'default' => '#1A1A1A',
                            'label' => 'Menu : couleur du texte à l’état actif',
                            'allowAlpha' => false,
                            'palette' => self::THEME_COLOR_PALETTE,
                        ],

                        [
                            'type' => 'core.note',
                            'label' => 'Bandeau du titre',
                        ],
                        [
                            'name' => 'theme_subheader_bg',
                            'type' => 'core.color',
                            'default' => '#F8F9FA',
                            'label' => 'Fond du bandeau du titre',
                            'allowAlpha' => false,
                            'palette' => self::THEME_COLOR_PALETTE,
                        ],
                        [
                            'name' => 'theme_subheader_color',
                            'type' => 'core.color',
                            'default' => '#1A1A1A',
                            'label' => 'Couleur du texte du bandeau du titre',
                            'allowAlpha' => false,
                            'palette' => self::THEME_COLOR_PALETTE,
                        ],

                        [
                            'type' => 'core.note',
                            'label' => 'Footer',
                        ],
                        [
                            'name' => 'theme_footer_bg',
                            'type' => 'core.color',
                            'default' => '#F8F9FA',
                            'label' => 'Fond du footer',
                            'allowAlpha' => false,
                            'palette' => self::THEME_COLOR_PALETTE,
                        ],
                        [
                            'name' => 'theme_footer_color',
                            'type' => 'core.color',
                            'default' => '#212529',
                            'label' => 'Couleur du texte du footer',
                            'allowAlpha' => false,
                            'palette' => self::THEME_COLOR_PALETTE,
                        ],
                    ],
                ],
                [
                    'id' => 'theme_typography',
                    'label' => 'Thème : typographie',
                    'fields' => [
                        [
                            'name' => 'theme_font_family',
                            'type' => 'core.font_family',
                            'default' => 'arial',
                            'label' => 'Police du texte',
                        ],
                        [
                            'name' => 'theme_heading_font_family',
                            'type' => 'core.font_family',
                            'default' => 'georgia',
                            'label' => 'Police des titres',
                        ],
                        [
                            'name' => 'theme_font_size',
                            'type' => 'core.number',
                            'default' => 16,
                            'label' => 'Taille de caractère de base',
                            'validation' => ['minimum' => 12, 'maximum' => 24],
                            'ui' => ['step' => 1],
                        ],
                        [
                            'name' => 'theme_line_height',
                            'type' => 'core.number',
                            'default' => 1.5,
                            'label' => 'Interligne',
                            'validation' => ['minimum' => 1, 'maximum' => 2.5],
                            'ui' => ['step' => 0.05],
                        ],
                    ],
                ],
                [
                    'id' => 'theme_components',
                    'label' => 'Thème : composants',
                    'fields' => [
                        [
                            'name' => 'theme_header_height',
                            'type' => 'core.number',
                            'default' => 80,
                            'label' => 'Hauteur du header',
                            'description' => 'Hauteur en pixels, entre 40 et 200.',
                            'validation' => ['minimum' => 40, 'maximum' => 200],
                            'ui' => ['step' => 1],
                        ],
                        [
                            'name' => 'theme_border_width',
                            'type' => 'core.number',
                            'default' => 1,
                            'label' => 'Épaisseur des bordures',
                            'validation' => ['minimum' => 0, 'maximum' => 4],
                            'ui' => ['step' => 1],
                        ],
                        [
                            'name' => 'theme_border_radius',
                            'type' => 'core.number',
                            'default' => 8,
                            'label' => 'Arrondi des bordures',
                            'validation' => ['minimum' => 0, 'maximum' => 32],
                            'ui' => ['step' => 1],
                        ],
                        [
                            'name' => 'theme_box_shadow',
                            'type' => 'core.select',
                            'default' => 'normal',
                            'label' => 'Ombres',
                            'options' => [
                                'none' => 'Aucune',
                                'subtle' => 'Légères',
                                'normal' => 'Normales',
                                'strong' => 'Prononcées',
                            ],
                        ],
                    ],
                ],
                [
                    'id' => 'theme_admin',
                    'label' => 'Thème : administration',
                    'fields' => [
                        [
                            'name' => 'theme_admin_ui',
                            'type' => 'core.select',
                            'default' => 'modern',
                            'label' => 'Interface d’administration',
                            'description' => 'Variante visuelle de l’administration du Space courant. « Moderne » utilise des surfaces, des arrondis et des ombres plus doux, sans modifier les couleurs du site.',
                            'options' => [
                                'modern' => 'Moderne',
                                'classic' => 'Classique',
                            ],
                        ],
                    ],
                ],
                [
                    'id' => 'theme_scss',
                    'label' => 'Thème : SCSS avancé',
                    'fields' => [
                        [
                            'name' => 'theme_custom_scss',
                            'type' => 'core.code',
                            'default' => '',
                            'syntax' => 'scss',
                            'validation' => [
                                'maxLength' => self::MAX_CUSTOM_SCSS_BYTES,
                                'maxBytes' => self::MAX_CUSTOM_SCSS_BYTES,
                            ],
                            'label' => 'SCSS personnalisé',
                            'description' => 'Vous pouvez utiliser @import "./file.scss";. Le dossier racine correspond au dossier de médias de l\'espace courant. Le SCSS sera compilé en CSS et injecté dans le site. Attention si vous modifiez les fichiers importés il faudra Enregistrer la template ici pour générer le CSS.',
                        ],
                        [
                            'name' => 'theme_custom_compiled_css',
                            'type' => 'core.computed',
                            'syntax' => 'css',
                            'schema' => ['type' => 'string'],
                            'computedProvider' => new CallbackFormComputedProvider(
                                static function (FormComputedContext $context) use ($includeScssCompilerDetails, $self): string {
                                    $customScss = (string) $context->getValue('theme_custom_scss', '');
                                    $importRoot = null;
                                    if (preg_match('/@(import|use|forward)\b/', $customScss) === 1) {
                                        $spaceId = $context->getAttribute('space_id');
                                        $importRoot = $self->coreSpaceHelper->getSpace($spaceId)->getMediasDir();
                                    }

                                    $compiledCss = ScssCompiler::compileScss(
                                        $customScss,
                                        $importRoot,
                                    );
                                    
                                    if ($compiledCss === null) {
                                        $errorMessage = 'Le SCSS personnalisé est invalide.';
                                        $compilerDetails = ScssCompiler::getLastError()?->getMessage();
                                        if ($includeScssCompilerDetails && $compilerDetails !== null && $compilerDetails !== '') {
                                            $errorMessage .= " $compilerDetails";
                                        }

                                        ExceptionResponse::throwWarning($errorMessage);

                                        return '';
                                    }

                                    return $compiledCss;
                                },
                            ),
                            'label' => 'CSS compilé personnalisé',
                        ],
                    ],
                ]
            ],
        ]);

    }

}
