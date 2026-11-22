<?php

namespace YTassy\Tycms\CoreExts\Core\Migration;

use Override;
use YTassy\Tycms\Extensions\BaseMigrationVersion;

class Mig_1_0_0_p3 extends BaseMigrationVersion
{
	
	#[Override]
	public function run(): array
	{
        $db = $this->db;
		$res = [];

		$db->executeMultiple(<<<SQL
			CREATE TABLE `config` (
				`space_id` bigint unsigned NOT NULL,
				`extension` varchar(50) NOT NULL,
				`params` mediumtext NOT NULL,
				PRIMARY KEY (`space_id`, `extension`),
				KEY `idx_config_ext_space` (`extension`, `space_id`),
				CONSTRAINT `fk_config_space` FOREIGN KEY (`space_id`) REFERENCES `spaces` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT
			) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
		SQL);

        // Préserver la configuration initiale du Space racine sur une nouvelle installation.
        $rootTemplate = [
            'main_menu_id' => 1,
            'logo' => ASSETS_CORE_PATH . '/tpl_main/images/my_logo.webp',
            'theme_logo_height' => 40,
            'favicon' => ASSETS_CORE_PATH . '/tpl_main/images/my_favicon.png',
            'footer_page_id' => 3,
            'logo_email' => '',
            'email_logo_max_width' => 320,
            'email_logo_max_height' => 120,
            'email_default_outer_background' => '#F0F0F0',
            'email_default_content_background' => '#FFFFFF',
            'email_default_card_width' => 630,
            'email_default_content_padding' => 30,
            'email_default_divider_color' => '#E6E6E6',
            'email_default_footer_background' => '#142B5B',
            'email_default_footer_color' => '#FFFFFF',
            'email_footer_text' => '© {space} {year}',
            'email_footer_url' => '/',
            'email_footer_link_text' => '',
            'email_blank_background' => '#FFFFFF',
            'email_body_font' => 'arial',
            'email_heading_font' => 'arial',
            'email_body_size' => 16,
            'email_heading_size' => 24,
            'email_line_height' => 1.5,
            'email_text_color' => '#222222',
            'email_heading_color' => '#142B5B',
            'email_link_color' => '#0066CC',
            'theme_primary' => '#1A6B6B',
            'theme_secondary' => '#2C3E50',
            'theme_success' => '#198754',
            'theme_info' => '#0DCAF0',
            'theme_warning' => '#FFC107',
            'theme_danger' => '#DC3545',
            'theme_body_bg' => '#FFFFFF',
            'theme_body_color' => '#1A1A1A',
            'theme_heading_color' => '#1A1A1A',
            'theme_components_bg' => '#FFFFFF',
            'theme_surface_bg' => '#F8F9FA',
            'theme_surface_alt_bg' => '#E9ECEF',
            'theme_border_color' => '#DEE2E6',
            'theme_header_bg' => '#F8F9FA',
            'theme_header_color' => '#1A1A1A',
            'theme_menu_hover_bg' => '#FFFFFF',
            'theme_menu_hover_color' => '#1A1A1A',
            'theme_menu_active_bg' => '#F8F9FA',
            'theme_menu_active_color' => '#1A1A1A',
            'theme_subheader_bg' => '#F8F9FA',
            'theme_subheader_color' => '#1A1A1A',
            'theme_footer_bg' => '#F8F9FA',
            'theme_footer_color' => '#212529',
            'theme_font_family' => 'system',
            'theme_heading_font_family' => 'bona_nova',
            'theme_font_size' => 16,
            'theme_line_height' => 1.5,
            'theme_header_height' => 80,
            'theme_border_width' => 1,
            'theme_border_radius' => 8,
            'theme_box_shadow' => 'normal',
            'theme_custom_scss' => '',
            'theme_custom_compiled_css' => '',
        ];
        $res[] = $db->execute(
            'INSERT INTO config (space_id, extension, params) VALUES (%, %, %)',
            [1, 'tpl_main', json_encode($rootTemplate, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)],
        );

		return $res;
	}


}
