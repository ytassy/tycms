<?php

namespace YTassy\Tycms\CoreExts\MainTemplate\Helpers;

use YTassy\Tycms\CoreExts\Core\Helper\TraitAllHelpers;
use YTassy\Tycms\WF\BaseHelper;

class CssHelper extends BaseHelper
{
    use TraitAllHelpers;

    private const BOX_SHADOWS = [
        'none' => [
            'sm' => 'none',
            'default' => 'none',
            'lg' => 'none',
            'inset' => 'none',
        ],
        'subtle' => [
            'sm' => '0 .0625rem .125rem rgba(0, 0, 0, .06)',
            'default' => '0 .125rem .25rem rgba(0, 0, 0, .075)',
            'lg' => '0 .25rem .5rem rgba(0, 0, 0, .10)',
            'inset' => 'inset 0 1px 2px rgba(0, 0, 0, .05)',
        ],
        'normal' => [
            'sm' => '0 .125rem .25rem rgba(0, 0, 0, .075)',
            'default' => '0 .5rem 1rem rgba(0, 0, 0, .15)',
            'lg' => '0 1rem 3rem rgba(0, 0, 0, .175)',
            'inset' => 'inset 0 1px 2px rgba(0, 0, 0, .075)',
        ],
        'strong' => [
            'sm' => '0 .25rem .5rem rgba(0, 0, 0, .12)',
            'default' => '0 .75rem 1.5rem rgba(0, 0, 0, .20)',
            'lg' => '0 1.5rem 4rem rgba(0, 0, 0, .25)',
            'inset' => 'inset 0 1px 2px rgba(0, 0, 0, .10)',
        ],
    ];

    public function getCss(): string
    {
        $space = $this->coreSpaceHelper->getCurrentSpace();
        $config = $this->coreTemplateHelper->getTemplateConf('tpl_main', $space->id);

        // Les imports locaux doivent précéder toutes les règles CSS.
        return $this->coreFontHelper->css([
            $config->params->theme_font_family ?? 'arial',
            $config->params->theme_heading_font_family ?? 'georgia',
        ]) . "\n" . $this->renderThemeCss($config->params)
            . "\n\n"
            . $this->getStringParam($config->params, 'theme_custom_compiled_css', '');
    }

    public function renderThemeCss(object $params): string
    {
        $colors = [
            'primary' => $this->getColorParam($params, 'theme_primary', '#1A6B6B'),
            'secondary' => $this->getColorParam($params, 'theme_secondary', '#2C3E50'),
            'success' => $this->getColorParam($params, 'theme_success', '#198754'),
            'info' => $this->getColorParam($params, 'theme_info', '#0DCAF0'),
            'warning' => $this->getColorParam($params, 'theme_warning', '#FFC107'),
            'danger' => $this->getColorParam($params, 'theme_danger', '#DC3545'),
        ];
        $bodyBackground = $this->getColorParam($params, 'theme_body_bg', '#FFFFFF');

        // Avant la séparation, une même couleur habillait le body et les composants.
        $componentsBackground = $this->getColorParam($params, 'theme_components_bg', $bodyBackground);
        $surfaceBackground = $this->getColorParam($params, 'theme_surface_bg', '#F8F9FA');
        $surfaceAltBackground = $this->getColorParam($params, 'theme_surface_alt_bg', '#E9ECEF');
        $bodyColor = $this->getColorParam($params, 'theme_body_color', '#1A1A1A');
        $headingColor = $this->getColorParam($params, 'theme_heading_color', '#1A1A1A');
        $borderColor = $this->getColorParam($params, 'theme_border_color', '#DEE2E6');

        // Garder les anciens fonds et la couleur de navigation en l'absence de réglage dédié.
        $headerBackground = $this->getColorParam($params, 'theme_header_bg', $surfaceBackground);
        $headerColor = $this->getColorParam($params, 'theme_header_color', $bodyColor);

        // Les nouveaux états du menu reprennent l'habillage du header tant qu'ils sont absents.
        $menuHoverBackground = $this->getColorParam($params, 'theme_menu_hover_bg', '#FFFFFF');
        $menuActiveBackground = $this->getColorParam($params, 'theme_menu_active_bg', $headerBackground);
        $menuHoverColor = $this->getColorParam($params, 'theme_menu_hover_color', $headerColor);
        $menuActiveColor = $this->getColorParam($params, 'theme_menu_active_color', $headerColor);

        $subheaderBackground = $this->getColorParam($params, 'theme_subheader_bg', $surfaceBackground);
        $subheaderColor = $this->getColorParam($params, 'theme_subheader_color', $headingColor);
        $footerBackground = $this->getColorParam($params, 'theme_footer_bg', $surfaceBackground);
        $footerColor = $this->getColorParam($params, 'theme_footer_color', '#212529');

        $bodyFontFamily = $this->coreFontHelper->stack($params->theme_font_family ?? 'arial');
        $headingFontFamily = $this->coreFontHelper->stack($params->theme_heading_font_family ?? 'georgia', 'georgia');
        $fontSize = $this->getNumberParam($params, 'theme_font_size', 16, 12, 24);
        $headerHeight = $this->getNumberParam($params, 'theme_header_height', 80, 40, 200);
        $logoHeight = $this->getNumberParam($params, 'theme_logo_height', 40, 20, 200);
        $lineHeight = $this->getNumberParam($params, 'theme_line_height', 1.5, 1, 2.5);
        $borderWidth = $this->getNumberParam($params, 'theme_border_width', 1, 0, 4);
        $borderRadius = $this->getNumberParam($params, 'theme_border_radius', 8, 0, 32);
        $boxShadows = self::BOX_SHADOWS[$this->getChoiceParam($params, 'theme_box_shadow', 'normal', self::BOX_SHADOWS)] ?? self::BOX_SHADOWS['normal'];

        $primary = $colors['primary'];
        $focusBorderColor = self::tint($primary, 40);
        $focusShadow = '0 0 0 .25rem ' . self::rgba($primary, .25);

        $rootDeclarations = [
            '--bs-font-sans-serif: ' . $bodyFontFamily,
            '--bs-body-font-family: ' . $bodyFontFamily,
            '--bs-body-font-size: 1rem',
            '--bs-body-font-weight: 400',
            '--bs-body-line-height: ' . self::formatNumber($lineHeight),
            '--bs-body-color: ' . $bodyColor,
            '--bs-body-color-rgb: ' . self::toRgbCss($bodyColor),
            '--bs-body-bg: ' . $componentsBackground,
            '--bs-body-bg-rgb: ' . self::toRgbCss($componentsBackground),
            '--theme-page-bg: ' . $bodyBackground,
            '--bs-emphasis-color: ' . $bodyColor,
            '--bs-emphasis-color-rgb: ' . self::toRgbCss($bodyColor),
            '--bs-secondary-color: ' . self::rgba($bodyColor, .75),
            '--bs-secondary-color-rgb: ' . self::toRgbCss($bodyColor),
            '--bs-secondary-bg: ' . $surfaceAltBackground,
            '--bs-secondary-bg-rgb: ' . self::toRgbCss($surfaceAltBackground),
            '--bs-tertiary-color: ' . self::rgba($bodyColor, .5),
            '--bs-tertiary-color-rgb: ' . self::toRgbCss($bodyColor),
            '--bs-tertiary-bg: ' . $surfaceBackground,
            '--bs-tertiary-bg-rgb: ' . self::toRgbCss($surfaceBackground),
            '--bs-light: ' . $surfaceBackground,
            '--bs-light-rgb: ' . self::toRgbCss($surfaceBackground),
            '--bs-heading-color: ' . $headingColor,
            '--bs-link-color: ' . $primary,
            '--bs-link-color-rgb: ' . self::toRgbCss($primary),
            '--bs-link-hover-color: ' . self::shade($primary, 20),
            '--bs-link-hover-color-rgb: ' . self::toRgbCss(self::shade($primary, 20)),
            '--bs-highlight-color: ' . $bodyColor,
            '--bs-highlight-bg: ' . self::tint($colors['warning'], 80),
            '--bs-border-width: ' . self::formatPx($borderWidth),
            '--bs-border-color: ' . $borderColor,
            '--bs-border-color-translucent: ' . self::rgba($borderColor, .5),
            '--bs-border-radius-sm: ' . self::formatPx($borderRadius / 2),
            '--bs-border-radius: ' . self::formatPx($borderRadius),
            '--bs-border-radius-lg: ' . self::formatPx($borderRadius * 2),
            '--bs-border-radius-xl: ' . self::formatPx($borderRadius * 2),
            '--bs-border-radius-xxl: ' . self::formatPx($borderRadius * 4),
            '--bs-border-radius-2xl: var(--bs-border-radius-xxl)',
            '--bs-border-radius-pill: 50rem',
            '--bs-box-shadow-sm: ' . $boxShadows['sm'],
            '--bs-box-shadow: ' . $boxShadows['default'],
            '--bs-box-shadow-lg: ' . $boxShadows['lg'],
            '--bs-box-shadow-inset: ' . $boxShadows['inset'],
            '--bs-focus-ring-width: .25rem',
            '--bs-focus-ring-opacity: .25',
            '--bs-focus-ring-color: ' . self::rgba($primary, .25),
            '--bs-form-valid-color: ' . $colors['success'],
            '--bs-form-valid-border-color: ' . $colors['success'],
            '--bs-form-invalid-color: ' . $colors['danger'],
            '--bs-form-invalid-border-color: ' . $colors['danger'],
            '--theme-heading-font-family: ' . $headingFontFamily,
            '--theme-header-bg: ' . $headerBackground,
            '--theme-header-color: ' . $headerColor,
            '--theme-menu-hover-bg: ' . $menuHoverBackground,
            '--theme-menu-active-bg: ' . $menuActiveBackground,
            '--theme-menu-hover-color: ' . $menuHoverColor,
            '--theme-menu-active-color: ' . $menuActiveColor,
            '--theme-subheader-bg: ' . $subheaderBackground,
            '--theme-subheader-color: ' . $subheaderColor,
            '--theme-footer-bg: ' . $footerBackground,
            '--theme-footer-color: ' . $footerColor,
        ];

        $darkDeclarations = [];
        foreach ($colors as $name => $color) {
            $rootDeclarations[] = "--bs-$name: $color";
            $rootDeclarations[] = "--bs-$name-rgb: " . self::toRgbCss($color);
            $rootDeclarations[] = "--bs-$name-text-emphasis: " . self::shade($color, 60);
            $rootDeclarations[] = "--bs-$name-bg-subtle: " . self::tint($color, 80);
            $rootDeclarations[] = "--bs-$name-border-subtle: " . self::tint($color, 60);

            $darkDeclarations[] = "--bs-$name-text-emphasis: " . self::tint($color, 40);
            $darkDeclarations[] = "--bs-$name-bg-subtle: " . self::shade($color, 80);
            $darkDeclarations[] = "--bs-$name-border-subtle: " . self::shade($color, 40);
        }

        return implode("\n\n", [
            self::cssRule(":root,\n[data-bs-theme=\"light\"]", $rootDeclarations),
            self::cssRule('[data-bs-theme="dark"]', $darkDeclarations),
            self::cssRule('html', ['font-size: ' . self::formatPx($fontSize)]),
            self::cssRule('body', [
                'font-family: var(--bs-body-font-family)',
                'font-size: var(--bs-body-font-size)',
                'font-weight: var(--bs-body-font-weight)',
                'line-height: var(--bs-body-line-height)',
                'color: var(--bs-body-color)',
                'background-color: var(--theme-page-bg)',
            ]),
            self::cssRule('h1, .h1, h2, .h2, h3, .h3, h4, .h4, h5, .h5, h6, .h6', [
                'font-family: var(--theme-heading-font-family)',
                'color: var(--bs-heading-color)',
            ]),
            $this->getLayoutCss($headerColor, $footerColor, $headerHeight, $logoHeight),
            $this->getComponentCss($colors, $focusBorderColor, $focusShadow),
        ]);
    }

    private function getLayoutCss(
        string $headerColor,
        string $footerColor,
        float $headerHeight,
        float $logoHeight,
    ): string
    {
        // La couleur est déjà validée en hexadécimal ; encoder son # dans l'icône mobile.
        $togglerIcon = "url(\"data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3e%3cpath stroke='"
            . str_replace('#', '%23', $headerColor)
            . "' stroke-linecap='round' stroke-miterlimit='10' stroke-width='2' d='M4 7h22M4 15h22M4 23h22'/%3e%3c/svg%3e\")";

        return implode("\n\n", [
            self::cssRule('.main-template-header', [
                // Exposer la dimension au style sans imposer de hauteur à l'élément.
                '--header-height: ' . self::formatPx($headerHeight),
                'background-color: var(--theme-header-bg)',
                'color: var(--theme-header-color)',
            ]),
            // Surcharger les variables sur la navbar elle-même, où Bootstrap les redéfinit.
            self::cssRule('.main-template-header .navbar', [
                '--bs-navbar-color: ' . self::rgba($headerColor, .65),
                '--bs-navbar-hover-color: ' . self::rgba($headerColor, .8),
                '--bs-navbar-disabled-color: ' . self::rgba($headerColor, .3),
                '--bs-navbar-active-color: var(--theme-header-color)',
                '--bs-navbar-brand-color: var(--theme-header-color)',
                '--bs-navbar-brand-hover-color: var(--theme-header-color)',
                '--bs-navbar-toggler-border-color: ' . self::rgba($headerColor, .15),
                '--bs-navbar-toggler-icon-bg: ' . $togglerIcon,
            ]),
            // Appliquer la hauteur au logo du site sans dépendre de celle du header.
            self::cssRule('.main-template-header .navbar-brand > img', [
                'height: ' . self::formatPx($logoHeight),
                'width: auto',
            ]),
            self::cssRule('.main-template-subheader', [
                'background-color: var(--theme-subheader-bg)',
                'color: var(--theme-subheader-color)',
                '--bs-heading-color: var(--theme-subheader-color)',
            ]),
            // Les titres et liens non personnalisés suivent le texte du pied de page.
            self::cssRule('.main-template-footer', [
                'background-color: var(--theme-footer-bg)',
                'color: var(--theme-footer-color)',
                '--bs-heading-color: var(--theme-footer-color)',
                '--bs-link-color: var(--theme-footer-color)',
                '--bs-link-color-rgb: ' . self::toRgbCss($footerColor),
                '--bs-link-hover-color: var(--theme-footer-color)',
                '--bs-link-hover-color-rgb: ' . self::toRgbCss($footerColor),
            ]),
        ]);
    }

    /** @param array<string,string> $colors */
    private function getComponentCss(array $colors, string $focusBorderColor, string $focusShadow): string
    {
        $rules = [];

        foreach ($colors as $name => $color) {
            $contrastColor = self::contrastColor($color);
            $hoverBackground = self::shade($color, 15);
            $hoverBorder = self::shade($color, 20);
            $activeBackground = self::shade($color, 20);
            $activeBorder = self::shade($color, 25);
            $rgb = self::toRgbCss($color);

            $rules[] = self::cssRule(".btn-$name", [
                '--bs-btn-color: ' . $contrastColor,
                '--bs-btn-bg: ' . $color,
                '--bs-btn-border-color: ' . $color,
                '--bs-btn-hover-color: ' . $contrastColor,
                '--bs-btn-hover-bg: ' . $hoverBackground,
                '--bs-btn-hover-border-color: ' . $hoverBorder,
                '--bs-btn-focus-shadow-rgb: ' . $rgb,
                '--bs-btn-active-color: ' . $contrastColor,
                '--bs-btn-active-bg: ' . $activeBackground,
                '--bs-btn-active-border-color: ' . $activeBorder,
                '--bs-btn-disabled-color: ' . $contrastColor,
                '--bs-btn-disabled-bg: ' . $color,
                '--bs-btn-disabled-border-color: ' . $color,
            ]);

            $rules[] = self::cssRule(".btn-outline-$name", [
                '--bs-btn-color: ' . $color,
                '--bs-btn-border-color: ' . $color,
                '--bs-btn-hover-color: ' . $contrastColor,
                '--bs-btn-hover-bg: ' . $color,
                '--bs-btn-hover-border-color: ' . $color,
                '--bs-btn-focus-shadow-rgb: ' . $rgb,
                '--bs-btn-active-color: ' . $contrastColor,
                '--bs-btn-active-bg: ' . $activeBackground,
                '--bs-btn-active-border-color: ' . $activeBorder,
                '--bs-btn-disabled-color: ' . $color,
                '--bs-btn-disabled-bg: transparent',
                '--bs-btn-disabled-border-color: ' . $color,
            ]);

            $rules[] = self::cssRule(".text-bg-$name", [
                'color: ' . $contrastColor . ' !important',
            ]);
            $rules[] = self::cssRule(".link-$name:hover,\n.link-$name:focus", [
                'color: ' . $activeBackground . ' !important',
                'text-decoration-color: ' . $activeBackground . ' !important',
            ]);
        }

        $primary = $colors['primary'];
        $primaryContrast = self::contrastColor($primary);
        $primaryHover = self::shade($primary, 20);

        $rules[] = self::cssRule(".dropdown-menu,\n.navbar-nav .dropdown-menu", [
            '--bs-dropdown-link-active-color: ' . $primaryContrast,
            '--bs-dropdown-link-active-bg: ' . $primary,
        ]);
        $rules[] = self::cssRule('.nav-pills', [
            '--bs-nav-pills-link-active-color: ' . $primaryContrast,
            '--bs-nav-pills-link-active-bg: ' . $primary,
        ]);
        $rules[] = self::cssRule('.pagination', [
            '--bs-pagination-color: ' . $primary,
            '--bs-pagination-focus-color: ' . $primaryHover,
            '--bs-pagination-focus-bg: var(--bs-tertiary-bg)',
            '--bs-pagination-focus-box-shadow: 0 0 0 .25rem ' . self::rgba($primary, .25),
            '--bs-pagination-hover-color: ' . $primaryHover,
            '--bs-pagination-hover-bg: var(--bs-tertiary-bg)',
            '--bs-pagination-hover-border-color: var(--bs-border-color)',
            '--bs-pagination-active-color: ' . $primaryContrast,
            '--bs-pagination-active-bg: ' . $primary,
            '--bs-pagination-active-border-color: ' . $primary,
        ]);
        $rules[] = self::cssRule('.list-group', [
            '--bs-list-group-active-color: ' . $primaryContrast,
            '--bs-list-group-active-bg: ' . $primary,
            '--bs-list-group-active-border-color: ' . $primary,
        ]);
        $rules[] = self::cssRule('.progress', [
            '--bs-progress-bar-bg: ' . $primary,
        ]);
        $rules[] = self::cssRule('.accordion', [
            '--bs-accordion-btn-focus-box-shadow: ' . $focusShadow,
            '--bs-accordion-active-color: var(--bs-primary-text-emphasis)',
            '--bs-accordion-active-bg: var(--bs-primary-bg-subtle)',
        ]);
        $rules[] = self::cssRule('.form-control:focus,\n.form-select:focus', [
            'border-color: ' . $focusBorderColor,
            'box-shadow: ' . $focusShadow,
        ]);
        $rules[] = self::cssRule('.form-check-input:focus', [
            'border-color: ' . $focusBorderColor,
            'box-shadow: ' . $focusShadow,
        ]);
        $rules[] = self::cssRule('.form-check-input:checked,\n.form-check-input[type="checkbox"]:indeterminate', [
            'background-color: ' . $primary,
            'border-color: ' . $primary,
        ]);
        $rules[] = self::cssRule('.btn-close:focus', [
            'box-shadow: ' . $focusShadow,
        ]);

        return implode("\n\n", $rules);
    }

    /** @param list<string> $declarations */
    private static function cssRule(string $selector, array $declarations): string
    {
        return $selector . " {\n    " . implode(";\n    ", $declarations) . ";\n}";
    }

    /** @param array<string,mixed> $choices */
    private function getChoiceParam(object $params, string $name, string $default, array $choices): string
    {
        $value = $params->{$name} ?? null;

        return is_string($value) && array_key_exists($value, $choices) ? $value : $default;
    }

    private function getColorParam(object $params, string $name, string $default): string
    {
        $value = $params->{$name} ?? null;
        if (!is_string($value) || preg_match('/^#[0-9A-Fa-f]{6}$/D', $value) !== 1) {
            return $default;
        }

        return '#' . strtoupper(substr($value, 1));
    }

    private function getNumberParam(object $params, string $name, float $default, float $minimum, float $maximum): float
    {
        $value = $params->{$name} ?? null;
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)) {
            return $default;
        }

        return min($maximum, max($minimum, (float) $value));
    }

    private function getStringParam(object $params, string $name, string $default): string
    {
        $value = $params->{$name} ?? null;

        return is_string($value) ? $value : $default;
    }

    private static function formatPx(float $value): string
    {
        return self::formatNumber($value) . 'px';
    }

    private static function formatNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private static function tint(string $color, float $weight): string
    {
        return self::mix($color, '#FFFFFF', $weight);
    }

    private static function shade(string $color, float $weight): string
    {
        return self::mix($color, '#000000', $weight);
    }

    private static function mix(string $from, string $to, float $toWeight): string
    {
        $fromRgb = self::toRgb($from);
        $toRgb = self::toRgb($to);
        $fromWeight = 1 - ($toWeight / 100);
        $toWeight /= 100;

        return sprintf(
            '#%02X%02X%02X',
            (int) round(($fromRgb[0] * $fromWeight) + ($toRgb[0] * $toWeight)),
            (int) round(($fromRgb[1] * $fromWeight) + ($toRgb[1] * $toWeight)),
            (int) round(($fromRgb[2] * $fromWeight) + ($toRgb[2] * $toWeight)),
        );
    }

    private static function rgba(string $color, float $opacity): string
    {
        return 'rgba(' . self::toRgbCss($color) . ', ' . self::formatNumber($opacity) . ')';
    }

    private static function toRgbCss(string $color): string
    {
        return implode(', ', self::toRgb($color));
    }

    /** @return array{0:int,1:int,2:int} */
    private static function toRgb(string $color): array
    {
        $hex = substr($color, 1);

        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }

    private static function contrastColor(string $background): string
    {
        $whiteContrast = self::contrastRatio($background, '#FFFFFF');

        return $whiteContrast >= 4.5 ? '#FFFFFF' : '#000000';
    }

    private static function contrastRatio(string $first, string $second): float
    {
        $firstLuminance = self::relativeLuminance($first);
        $secondLuminance = self::relativeLuminance($second);

        return (max($firstLuminance, $secondLuminance) + .05) / (min($firstLuminance, $secondLuminance) + .05);
    }

    private static function relativeLuminance(string $color): float
    {
        $channels = self::toRgb($color);
        $luminanceChannels = array_map(static function (int $channel): float {
            $value = $channel / 255;

            return $value <= .04045
                ? $value / 12.92
                : (($value + .055) / 1.055) ** 2.4;
        }, $channels);

        return (.2126 * $luminanceChannels[0]) + (.7152 * $luminanceChannels[1]) + (.0722 * $luminanceChannels[2]);
    }
}
