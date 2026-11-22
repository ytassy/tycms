<?php

namespace YTassy\Tycms\CoreExts\Core\Migration;

use Override;
use YTassy\Tycms\Extensions\BaseMigrationVersion;

class Mig_1_0_0_p1 extends BaseMigrationVersion
{

	#[Override]
	public function run(): array
	{
		$res = [];
        $db = $this->db;

        $db->executeMultiple(<<<SQL

			CREATE TABLE `spaces` (
				`id` bigint unsigned NOT NULL AUTO_INCREMENT,
				`parent_id` bigint unsigned NULL DEFAULT NULL,
				`template` varchar(50) NOT NULL,
				`medias_dir` varchar(50) NOT NULL,
				`hosts` varchar(300) NOT NULL,
				`title` varchar(50) NOT NULL,
				`params` text NOT NULL,

				PRIMARY KEY (`id`),
				KEY `idx_spaces_parent` (`parent_id`),
				KEY `idx_spaces_template` (`template`),
				CONSTRAINT `fk_spaces_parent` FOREIGN KEY (`parent_id`) REFERENCES `spaces` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT
			) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



			CREATE TABLE IF NOT EXISTS `users` (
				`id` bigint unsigned NOT NULL AUTO_INCREMENT,
				`space_id` bigint unsigned NOT NULL DEFAULT 1,
				`first_name` varchar(100)  NOT NULL DEFAULT '',
				`last_name` varchar(100)  NOT NULL DEFAULT '',
				`email` varchar(100)  NOT NULL DEFAULT '',
				`password` varchar(100)  NOT NULL DEFAULT '',
				`timezone` VARCHAR(50) NOT NULL DEFAULT 'Europe/Paris',
				`blocked` tinyint NOT NULL DEFAULT '0',
				`send_email` tinyint DEFAULT '0',
				`created` datetime NOT NULL DEFAULT current_timestamp(),
				`updated` datetime NULL DEFAULT NULL,
				`register_date` datetime NULL DEFAULT NULL,
				`last_visit_date` datetime DEFAULT NULL,
				`params` mediumtext  NOT NULL,

				`reset_password_token` varchar(100) NOT NULL DEFAULT '',
				`reset_password_request_date` datetime NULL DEFAULT NULL,
				`reset_password_last_reset_date` datetime DEFAULT NULL COMMENT 'Date of last password reset',
				`reset_count` int unsigned NOT NULL DEFAULT '0' COMMENT 'Count of password resets',
				PRIMARY KEY (`id`),
				UNIQUE KEY `idx_users_email` (`email`),
				KEY `idx_users_space` (`space_id`),
				KEY `idx_users_first_name` (`first_name`, `last_name`),
				KEY `idx_users_last_name` (`last_name`, `first_name`),
				KEY `idx_users_blocked` (`blocked`, `email`),
				KEY `idx_users_register_date` (`register_date`),
				KEY `idx_users_rpt` (`reset_password_token`),

				CONSTRAINT `fk_user_space` FOREIGN KEY (`space_id`) REFERENCES `spaces` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


			ALTER TABLE `users` AUTO_INCREMENT = 1000;

			CREATE TABLE `sessions` (
				`id` bigint unsigned NOT NULL AUTO_INCREMENT,
				`key` varchar(50) NOT NULL,
				`user_id` bigint unsigned NULL DEFAULT NULL,
				`start_date` datetime NOT NULL DEFAULT current_timestamp() COMMENT "Date de création de la session",
				`last_request_date` datetime NOT NULL DEFAULT current_timestamp() COMMENT "Date de dernière requête",
				`requests` bigint unsigned NOT NULL DEFAULT 1 COMMENT "Nombre de requête dans cette session",
				`data` mediumtext NOT NULL COMMENT "Data de la session encodé via les fonctions serialize de PHP",

				PRIMARY KEY (`id`),
				UNIQUE KEY `idx_sessions_key` (`key`),
				KEY `idx_sessions_user` (`user_id`),
				KEY `idx_sessions_requests` (`requests`, `last_request_date`),
				KEY `idx_sessions_last` (`last_request_date`),
				CONSTRAINT `fk_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE CASCADE
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


			CREATE TABLE `user_groups` (
				`id` bigint unsigned NOT NULL AUTO_INCREMENT,
				`space_id` bigint unsigned NOT NULL DEFAULT 1,
				`parent_id` bigint unsigned NULL,
				`type` tinyint NOT NULL DEFAULT '0' COMMENT "0=groupe; 1=Utilisateur unique",
				`name` varchar(100) NOT NULL DEFAULT '',
				`description` text NOT NULL DEFAULT '',
				`created` datetime NOT NULL DEFAULT current_timestamp(),
				`updated` datetime NULL DEFAULT NULL,

				PRIMARY KEY (`id`),
				UNIQUE KEY `idx_user_groups_space_parent_name` (`space_id`, `parent_id`, `name`),
				KEY `idx_user_groups_space` (`space_id`, `type`, `name`),
				KEY `idx_user_groups_parent1` (`parent_id`, `type`,`name`),
				KEY `idx_user_groups_type1` (`type`, `name`),
				KEY `idx_user_groups_type2` (`type`, `parent_id`),
				KEY `idx_user_groups_name` (`name`),
				CONSTRAINT `fk_user_group_space` FOREIGN KEY (`space_id`) REFERENCES `spaces` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
				CONSTRAINT `fk_user_group_parent` FOREIGN KEY (`parent_id`) REFERENCES `user_groups` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT
			) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


			CREATE TABLE `user_groups_map` (
				`id` bigint unsigned NOT NULL AUTO_INCREMENT,
				`user_id` bigint unsigned NOT NULL,
				`group_id` bigint unsigned NOT NULL,
				`created` datetime NOT NULL DEFAULT current_timestamp(),
				PRIMARY KEY (`id`),
				UNIQUE KEY `idx_user_groups_map_group` (`group_id`,`user_id`),
				UNIQUE KEY `idx_user_groups_map_user` (`user_id`,`group_id`),
				
				CONSTRAINT `fk_user_groups_map_group` FOREIGN KEY (`group_id`) REFERENCES `user_groups` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
				CONSTRAINT `fk_user_groups_map_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE CASCADE
			) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

			CREATE TABLE `acl_roles` (
				`id` bigint unsigned NOT NULL AUTO_INCREMENT,
				`space_id` bigint unsigned NOT NULL DEFAULT 1,
				`user_defined` tinyint NOT NULL DEFAULT '0' COMMENT "Role défini par un utilisateur",
				`name` varchar(100) NOT NULL DEFAULT '',
				`description` text NOT NULL DEFAULT '',
				`type` tinyint NOT NULL DEFAULT '0' COMMENT "0=global; 1=local; 2=Global et Local",
				`created` datetime NOT NULL DEFAULT current_timestamp(),
				`updated` datetime NULL DEFAULT NULL,

				PRIMARY KEY (`id`),
				UNIQUE KEY `idx_acl_roles1` (`space_id`,`name`),
				KEY `idx_acl_roles4` (`type`,`name`),
				KEY `idx_acl_roles5` (`name`),
				KEY `idx_acl_roles6` (`user_defined`, `space_id`),
				KEY `idx_acl_roles7` (`space_id`, `user_defined`),
				CONSTRAINT `fk_acl_roles_space` FOREIGN KEY (`space_id`) REFERENCES `spaces` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT
			) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


			CREATE TABLE `acl_objects` (
				`id` bigint unsigned NOT NULL AUTO_INCREMENT,
				`extension` varchar(50) NOT NULL DEFAULT '',
				`name` varchar(100) NOT NULL DEFAULT '',

				PRIMARY KEY (`id`),
				UNIQUE KEY `idx_acl_objects1` (`extension`, `name`),
				UNIQUE KEY `idx_acl_objects2` (`name`, `extension`)
			) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


			CREATE TABLE `acl_map` (
				`id` bigint unsigned NOT NULL AUTO_INCREMENT,
				`space_id` bigint unsigned NULL DEFAULT NULL,
				`role_id` bigint unsigned NOT NULL,
				`group_id` bigint unsigned NOT NULL,
				`acl_object_id` bigint unsigned NULL DEFAULT NULL,
				`object_id` bigint unsigned NULL DEFAULT NULL,
				`created` datetime NOT NULL DEFAULT current_timestamp(),

				PRIMARY KEY (`id`),
				UNIQUE KEY `idx_acl_map1` (`space_id`, `role_id`, `group_id`, `acl_object_id`, `object_id`),
				UNIQUE KEY `idx_acl_map2` (`space_id`, `group_id`, `role_id`, `acl_object_id`, `object_id`),
				UNIQUE KEY `idx_acl_map3` (`space_id`, `acl_object_id`, `object_id`, `role_id`, `group_id`),
				KEY `idx_acl_map4` (`space_id`, `acl_object_id`, `role_id`, `group_id`),
				KEY `idx_acl_map5` (`space_id`, `acl_object_id`, `group_id`, `role_id`),

				KEY `idx_acl_map6` (`role_id`, `group_id`, `acl_object_id`, `object_id`),
				KEY `idx_acl_map7` (`group_id`, `acl_object_id`, `object_id`),
				KEY `idx_acl_map8` (`group_id`, `role_id`, `acl_object_id`, `object_id`),
				KEY `idx_acl_map9` (`acl_object_id`, `object_id`, `role_id`, `group_id`),
				KEY `idx_acl_map10` (`acl_object_id`, `role_id`, `group_id`),
				KEY `idx_acl_map11` (`acl_object_id`, `group_id`, `role_id`),

				
				CONSTRAINT `fk_acl_map_role` FOREIGN KEY (`role_id`) REFERENCES `acl_roles` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
				CONSTRAINT `fk_acl_map_group` FOREIGN KEY (`group_id`) REFERENCES `user_groups` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
				CONSTRAINT `fk_acl_map_object` FOREIGN KEY (`acl_object_id`) REFERENCES `acl_objects` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
				CONSTRAINT `fk_acl_map_space` FOREIGN KEY (`space_id`) REFERENCES `spaces` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT
			) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


			CREATE TABLE `acl_permissions` (
				`id` bigint unsigned NOT NULL AUTO_INCREMENT,
				`role_id` bigint unsigned NOT NULL,
				`extension` varchar(50) NOT NULL DEFAULT '',
				`name` varchar(50) NOT NULL DEFAULT '',

				PRIMARY KEY (`id`),
				KEY `idx_acl_permissions1` (`extension`, `name`),
				KEY `idx_acl_permissions2` (`name`, `extension`),

				CONSTRAINT `fk_acl_permissions_role` FOREIGN KEY (`role_id`) REFERENCES `acl_roles` (`id`) ON UPDATE CASCADE ON DELETE CASCADE

			) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


			CREATE TABLE `menus` (
				`id` bigint unsigned NOT NULL AUTO_INCREMENT,
				`space_id` bigint unsigned NOT NULL DEFAULT 1,
				`user_defined` tinyint NOT NULL DEFAULT '0' COMMENT "Menu défini par un utilisateur",
				`name` varchar(100) NOT NULL,
				`description` text NOT NULL DEFAULT '',
				`alias` varchar(100) NOT NULL,
				`created` datetime NOT NULL DEFAULT current_timestamp(),
				`updated` datetime NULL DEFAULT NULL,

				PRIMARY KEY (`id`),
				UNIQUE KEY `idx_menus_space` (`space_id`, `user_defined`, `name`),
				KEY `idx_menus_1` (`user_defined`, `name`),
				KEY `idx_menus_2` (`name`, `space_id`),
				CONSTRAINT `fk_menus_space` FOREIGN KEY (`space_id`) REFERENCES `spaces` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


			CREATE TABLE `menu_items` (
				`id` bigint unsigned NOT NULL AUTO_INCREMENT,
				`menu_id` bigint unsigned NOT NULL,
				`ordering` int unsigned NOT NULL DEFAULT 0,
				`parent_id` bigint unsigned NULL DEFAULT NULL,
				`status` int NOT NULL DEFAULT '1' COMMENT "1=publié, 0=dépublié, -1: archivé, -2: corbeille",
				`extension` varchar(50) NOT NULL DEFAULT '',
				`type` varchar(50) NOT NULL,
				`params` mediumtext NOT NULL,
				`title` varchar(100) NOT NULL,
				`alias` varchar(100) NOT NULL,
				`css` varchar(100) NOT NULL,
				`icon` varchar(100) NOT NULL,
				`display_in_menu` tinyint NOT NULL DEFAULT 1,
				`browser_title` varchar(100) NOT NULL,
				`meta_description` text NOT NULL,
				`meta_robots` varchar(100) NOT NULL,
				`page_title` varchar(100) NOT NULL,
				`access_role_id` bigint unsigned NULL DEFAULT NULL,


				PRIMARY KEY (`id`),
				KEY `idx_menu_items_1` (`menu_id`, `parent_id`, `status`, `ordering`),
				KEY `idx_menu_items_2` (`menu_id`, `alias`),
				KEY `idx_menu_items_3` (`parent_id`, `ordering`),
				KEY `idx_menu_items_4` (`access_role_id`),
				CONSTRAINT `fk_menu_items_menu` FOREIGN KEY (`menu_id`) REFERENCES `menus` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
				CONSTRAINT `fk_menu_items_parent` FOREIGN KEY (`parent_id`) REFERENCES `menu_items` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
				CONSTRAINT `fk_menu_items_access_role` FOREIGN KEY (`access_role_id`) REFERENCES `acl_roles` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


			ALTER TABLE `spaces`
				ADD COLUMN menu_item_home_id bigint unsigned NULL DEFAULT NULL,
				ADD COLUMN menu_item_user_space_id bigint unsigned NULL DEFAULT NULL,
				ADD KEY `idx_spaces_menu_home` (`menu_item_home_id`),
				ADD KEY `idx_spaces_menu_user_space` (`menu_item_user_space_id`),
				ADD CONSTRAINT `fk_spaces_menu_home` FOREIGN KEY (`menu_item_home_id`) REFERENCES `menu_items` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
				ADD CONSTRAINT `fk_spaces_menu_user_space` FOREIGN KEY (`menu_item_user_space_id`) REFERENCES `menu_items` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT
				;


			CREATE TABLE `pages` (
				`id` bigint unsigned NOT NULL AUTO_INCREMENT,
				`space_id` bigint unsigned NOT NULL DEFAULT 1,
				`user_defined` tinyint NOT NULL DEFAULT '0' COMMENT "Page définie par un utilisateur",
				`name` varchar(100) NOT NULL,
				`description` text NOT NULL DEFAULT '',
				`created` datetime NOT NULL DEFAULT current_timestamp(),
				`updated` datetime NULL DEFAULT NULL,
				`type` int NOT NULL DEFAULT '1' COMMENT "1=HTML brut, 2=editeur",
				`raw_html` mediumtext NULL DEFAULT NULL,
				`raw_scss` mediumtext NULL DEFAULT NULL,
				`raw_css` mediumtext NULL DEFAULT NULL,
				`data` mediumtext NULL DEFAULT NULL,
				`access_edit_role_id` bigint unsigned NULL DEFAULT NULL,

				PRIMARY KEY (`id`),
				UNIQUE KEY `idx_pages_space` (`space_id`, `user_defined`, `name`),
				KEY `idx_pages_1` (`access_edit_role_id`),

				CONSTRAINT `fk_pages_edit_access_role` FOREIGN KEY (`access_edit_role_id`) REFERENCES `acl_roles` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
				CONSTRAINT `fk_pages_space` FOREIGN KEY (`space_id`) REFERENCES `spaces` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



			SQL);

		return $res;
	}
}



