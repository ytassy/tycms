<?php

namespace YTassy\Tycms\CoreExts\Core\Migration;

use Override;
use YTassy\Tycms\Extensions\BaseMigrationVersion;
use YTassy\Tycms\CoreExts\Core\Helper\Acl\InitialRolePermissions;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;

class Mig_1_0_0_p2 extends BaseMigrationVersion
{
	private function getSpaceParams(){
		$dev = $this->app->getConf()->dev;

		$devDomain = $dev ? ('devdomain' . rand(10000,99999) . '.dev') : '';

		return [
			'main_host' => $dev ? ('localhost:' . $_ENV['DOCKER_HTTP_PORT'] ?? '') : '',
			'short_description' => '',
			'description' => '',
			'timezone' => 'Europe/Paris',
			'mail_online' => true,
			'mailer' => 'smtp',
			'mail_from' => $dev ? ('dev@' . $devDomain): '',
			'mail_from_name' => $dev ? 'DEV' : '',
			'mail_smtp_auth' => false,
			'mail_smtp_user' => '',
			'mail_smtp_pass' => '',
			'mail_smtp_pass_key_version' => 0,
			'mail_smtp_host' => $dev ? 'mailpit' : '',
			'mail_smtp_secure' => $dev ? '' : 'ssl',
			'mail_smtp_port' => $dev ? 1025 : 465,
			'page_browser_title' => 'Mon site',
			'page_browser_description' => 'Mon site',
			'page_meta_robots' => 'index, follow',
			"sso_provider_ids" => [ ],
			"sso_auto_link_existing_account" => 0,
			"registration_enabled" => false,
			"registration_auto_member_role" => true,
			// Espace principal des nouveaux comptes publics ; null conserve l'espace d'inscription.
			"registration_target_space_id" => null,
			
			"registration_roles" => [
				//"this" => [ 3 ] // Signifie : rôle 3 sur l'espace d'inscription.
				// 1 => [ 3, 4 ] // Signifie : rôles 3 et 4 sur l'espace 1.
			],
			"user_roles" => [
				// Les rôles supplémentaires sont configurés ici ; le rôle 5 est ajouté selon registration_auto_member_role.
				// 1 => [ 3, 4 ] // Signifie : rôles 3 et 4 sur l'espace 1.
			],

			"registration_groups" => [
				// Array d'Id de groupe
			],
			"user_groups" => [
				// Array d'Id de groupe
			],

		];
	}
	
	#[Override]
	public function run(): array
	{
        $db = $this->db;
		$res = [];

		$res[] = $db->execute("INSERT INTO `spaces` (`id`, `hosts`, `parent_id`, `template`, `medias_dir`, `title`, `params`) VALUES
			(%, %, %, %, %, %, %);", [
			1, '[]', null, 'tpl_main', 'root', 'Root', $this->getSpaceParams()
		]);

		$res[] = $db->execute("INSERT INTO `spaces` (`id`, `hosts`, `parent_id`, `template`, `medias_dir`, `title`, `params`) VALUES
			(%, %, %, %, %, %, %);", [
			2, '[]', 1, 'tpl_main', 'main', 'Site', $this->getSpaceParams()
		]);

		$res[] = $db->execute("INSERT INTO `users` (`id`, `first_name`, `last_name`, `email`, `password`, `params`) VALUES
			(1, %, %, %, %, % );", [
			'Admin', 'Admin', $this->app->getConf()->first_admin_email,
				password_hash($this->app->getConf()->first_admin_password, PASSWORD_BCRYPT), []
		]);

		$res[] = $db->executeMultiple("INSERT INTO `user_groups` (id, parent_id, `type`, name) VALUES
(1, NULL, 0, 'Invité'), (2, NULL, 0, 'Utilisateurs'), (3, NULL, 0, 'Super Admin'),
(100, 2, 1, 'Admin');
ALTER TABLE `user_groups` AUTO_INCREMENT = 1000;

INSERT INTO `user_groups_map` (user_id, group_id) VALUES
(1,3),(1,100);
ALTER TABLE `user_groups_map` AUTO_INCREMENT = 1000;


INSERT INTO `acl_roles` (id, name, description, type) VALUES
(1, 'Invité', 'Rôle attribué à une personne non connectée', 0),
(2, 'Super Admin', 'Rôle Super administrateur. Dispose de tous les Droits sur tous les Espaces', 0),
(3, 'Utilisateur', 'Rôle attribué à tout utiliseur de facto sur l\'espace Racine', 0),
(4, 'Administrateur', 'Administrateur d\'un Espace', 0),
(5, 'Membre', 'Membre d\'un Espace', 0);
ALTER TABLE `acl_roles` AUTO_INCREMENT = 1000;


ALTER TABLE `acl_permissions` AUTO_INCREMENT = 1000;


INSERT INTO `acl_map` (role_id, group_id, space_id) VALUES
(1, 1, 1),(2, 3, 1), (4, 3, 1), (3, 2, 1);
ALTER TABLE `acl_map` AUTO_INCREMENT = 1000;


INSERT INTO `menus` (id, space_id, user_defined, name, description, alias) VALUES
(1, 1, 1, 'Menu principal Root', '', ''),
(2, 1, 1, 'Menu Legal Root', '', 'legal'),
(3, 2, 1, 'Menu principal', '', ''),
(4, 2, 1, 'Menu Legal', '', 'legal');
ALTER TABLE `menus` AUTO_INCREMENT = 1000;

INSERT INTO `menu_items` (id, menu_id, ordering, parent_id, status, extension, type, 
params, title, alias, css, icon, display_in_menu, browser_title, meta_description, 
meta_robots, page_title, access_role_id) VALUES
(1, 1, 10, NULL, 1, 'plg_core', 'page', '{ \"page_id\": 1 }', 'Accueil',              '', '', '', 1, '', '', 'index, follow', 'Accueil', NULL),
(2, 1, 20, NULL, 1, 'plg_core', 'admin', '{ }', 'Admin', '', '', '', 1, '', '', 'index, follow', '', 2),
(3, 1, 40, NULL, 1, 'plg_core', 'login', '{ }', 'Se connecter', 'login', '', '', 1, '', '', 'index, follow', 'Se connecter', 1),
(4, 1, 50, NULL  , 1, 'plg_core', 'logout', '{ }', 'Se déconnecter', 'logout', '', '', 1, '', '', 'index, follow', '', 3),
(5, 2, 11, NULL, 1, 'plg_core', 'page', '{ \"page_id\": 2 }', 'Mentions légales', 'mentions-legales', '', '', 1, '', '', 'index, follow', 'Mentions légales', null),

(10, 3, 10, NULL, 1, 'plg_core', 'page', '{ \"page_id\": 11 }', 'Accueil',              '', '', '', 1, '', '', 'index, follow', '', NULL),
(15, 3, 20, NULL, 1, 'plg_core', 'admin', '{ }', 'Admin', '', '', '', 1, '', '', 'index, follow', '', 2),
(20, 3, 20, NULL, 1, 'plg_core', 'text', '{ \"page_id\": 12 }', 'Mon espace', '', '', '', 1, '', '', 'index, follow', '', 3),
(50, 3, 30, 20,   1, 'plg_core', 'page', '{ \"page_id\": 12 }', 'Mon espace', 'mon-espace', '', '', 1, '', '', 'index, follow', 'Mon espace', 3),
(60, 3, 40, NULL, 1, 'plg_core', 'login', '{ }', 'Se connecter', 'login', '', '', 1, '', '', 'index, follow', 'Se connecter', 1),
(70, 3, 50, 20  , 1, 'plg_core', 'logout', '{ }', 'Se déconnecter', 'logout', '', '', 1, '', '', 'index, follow', '', 3),

(500, 4, 100, NULL, 1, 'plg_core', 'page', '{ \"page_id\": 21 }', 'Mentions légales', 'mentions-legales', '', '', 1, '', '', 'index, follow', 'Mentions légales', null),
(501, 4, 101, NULL, 1, 'plg_core', 'page', '{ \"page_id\": 22 }', 'Conditions Générales de Vente', 'cgv', '', '', 1, '', '', 'index, follow', 'Conditions Générales de Vente', null),
(502, 4, 102, NULL, 1, 'plg_core', 'page', '{ \"page_id\": 23 }', 'Conditions Générales d\'utilisation', 'cgu', '', '', 1, '', '', 'index, follow', 'Conditions Générales d\'utilisation', null),
(503, 4, 103, NULL, 1, 'plg_core', 'page', '{ \"page_id\": 24 }', 'Confidentialité et protection des données', 'confidentialite', '', '', 1, '', '', 'index, follow', 'Confidentialité et protection des données', null)
;
ALTER TABLE `menu_items` AUTO_INCREMENT = 1000;


UPDATE spaces SET menu_item_home_id = 1 WHERE id = 1;
UPDATE spaces SET menu_item_user_space_id = 1  WHERE id = 1;

UPDATE spaces SET menu_item_home_id = 10 WHERE id = 2;
UPDATE spaces SET menu_item_user_space_id = 50  WHERE id = 2;

", [ ], true);



		$homePageData = $this->getSimplePageData(<<<HTML
			<h1>Bienvenu sur la page d'accueil !</h1>
			<p>Je vous souhaite une bonne navigation</p>
			HTML, true);

		$monEspacePageData = $this->getSimplePageData(<<<HTML
			<h1>Bienvenu sur votre espace utilisateur !</h1>
			<p>Je vous souhaite une bonne navigation</p>
			HTML);

		$res[] = $db->executeMultiple("INSERT INTO `pages` (id, space_id, user_defined, name, description,
			type, raw_html, raw_scss, raw_css, data, access_edit_role_id) VALUES
		(%, %, %, %, %, %, %, %, %, %, %),
		(%, %, %, %, %, %, %, %, %, %, %),
		(%, %, %, %, %, %, %, %, %, %, %),
		(%, %, %, %, %, %, %, %, %, %, %),
		(%, %, %, %, %, %, %, %, %, %, %),
		(%, %, %, %, %, %, %, %, %, %, %),
		(%, %, %, %, %, %, %, %, %, %, %),
		(%, %, %, %, %, %, %, %, %, %, %),
		(%, %, %, %, %, %, %, %, %, %, %),
		(%, %, %, %, %, %, %, %, %, %, %);
		ALTER TABLE `pages` AUTO_INCREMENT = 1000;

		", [
			1, 1, 1, 'Accueil', '', 2, '', '', '', $homePageData, 1,
			2, 1, 1, 'Mentions légales', '', 1, 'Mentions légales', '', '', [], 1,
			3, 1,  1, 'Footer', '', 2, '', '', '', $this->getFooterPageData(2), 1,

			11, 2, 1, 'Accueil', '', 2, '', '', '', $homePageData, 1 ,
			12, 2, 1, 'Mon espace', '', 2, '', '', '', $monEspacePageData, 1,

			20, 2, 1, 'Footer', '', 2, '', '', '', $this->getFooterPageData(4), 1,
			21, 2, 1, 'Mentions légales', '', 1, 'Mentions légales', '', '', [], 1,
			22, 2, 1, 'Conditions Générales de Vente', '', 1, 'CGV', '', '', [], 1,
			23, 2, 1, 'Conditions Générales d\'utilisation', '', 1, 'CGU', '', '', [], 1,
			24, 2, 1, 'Confidentialité et protection des données', '', 1, 'RGPD', '', '', [], 1,

		], true);

        // Une base neuve reçoit des rôles complets, calculés depuis le catalogue courant.
        // Cette initialisation ne s'exécute pas sur les installations déjà migrées.
        foreach (InitialRolePermissions::fromCoreCatalog((new Permissions($this))->getPermissions()) as $roleId => $permissions) {
            foreach ($permissions as $permission) {
                [$extension, $name] = explode('__', $permission, 2);
                $db->execute('INSERT INTO acl_permissions (role_id, extension, name) VALUES (%, %, %)',
                    [$roleId, $extension, $name]);
            }
        }

		return $res;
	}



    /** Les pages installées utilisent directement le contrat v3, sans reprise historique. */
    private function getSimplePageData(string $html, bool $addLoginComp = false): array
    {
		$login = [[
                'id' => 'row_login', 'type' => 'core.row', 'props' => (object) [],
                'zones' => ['columns' => [[
                    'id' => 'column_login', 'sizes' => ['xs' => 12, 'lg' => 5], 'props' => (object) [],
                    'items' => [
						$this->component(
							'login_5616511',
							'core.login',
							[
								"form_enabled" => true,
								"sso_enabled" => true,
								"form_text" => "",
								"sso_text" => "",
								"show_forgot_password" => true,
								"forgot_password_text" => "Mot de passe oublié ?",
								"common" => ["role_ids" => [ 1 ]]
							])
					],
                ]]],
            ]];

		if ($addLoginComp == false) $login = [];

        return [
            'version' => 3,
            'container' => ['fluid' => false],
            'items' => array_merge(
				[
					[
					'id' => 'initial_row', 'type' => 'core.row', 'props' => (object) [],
					'zones' => ['columns' => [[
						'id' => 'initial_column', 'sizes' => ['xs' => 12], 'props' => (object) [],
						'items' => [$this->component('initial_html', 'core.html', ['content' => $html])],
					]]]]
				], $login)
        ];
    }

    private function getFooterPageData(int $menuId): array
    {
        return [
            'version' => 3,
            'container' => ['fluid' => false],
            'items' => [[
                'id' => 'footer_row', 'type' => 'core.row', 'props' => (object) [],
                'zones' => ['columns' => [
                    [
                        'id' => 'footer_menu_column', 'sizes' => ['xs' => 12, 'md' => 6], 'props' => (object) [],
                        'items' => [$this->component('footer_menu', 'core.menu', ['menu_id' => $menuId, 'display_type' => 'simple'])],
                    ],
                    [
                        'id' => 'footer_logo_column', 'sizes' => ['xs' => 12, 'md' => 6],
                        'props' => ['common' => ['style' => ['text_align' => 'right']]],
                        'items' => [
                            $this->component('footer_logo', 'core.image', [
                                'src' => ASSETS_CORE_PATH . '/tpl_main/images/my_logo.webp', 'alt' => '',
                                'max_width' => '250px', 'max_height' => '250px', 'click_action' => 'none',
                            ]),
                            $this->component('footer_welcome', 'core.html', ['content' => '<p>Bienvenue sur le site</p>']),
                        ],
                    ],
                    [
                        'id' => 'footer_separator_column', 'sizes' => ['xs' => 12], 'props' => (object) [],
                        'items' => [$this->component('footer_separator', 'core.hr', [])],
                    ],
                    [
                        'id' => 'footer_copyright_column', 'sizes' => ['xs' => 12],
                        'props' => ['common' => ['style' => ['text_align' => 'right']]],
                        'items' => [$this->component('footer_copyright', 'core.html', ['content' => '<p>© 2026 Votre Entreprise. Tous droits réservés.</p>'])],
                    ],
                ]],
            ]],
        ];
    }

    private function component(string $id, string $type, array $props): array
    {
        return ['id' => $id, 'type' => $type, 'props' => (object) $props, 'zones' => (object) []];
    }
}
