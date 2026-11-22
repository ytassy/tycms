<?php

namespace YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl;


class Permissions extends BasePermissions
{
    #[PermDef('ROOT', 'Super administrateur')]
    #[UiGroup('Administration', 'Accès système')]
    #[RequiresPermissions(
        self::SPACE_ADMIN,
        self::USERS_ADMIN,
        self::EXTENSION_EDIT,
        self::SSO_CRUD,
        self::FONT_MANAGE,
        self::AI_GENERATE_IMAGE,
    )]
    public const ROOT = 'plg_core__ROOT';

    #[PermDef('MEMBER', 'Membre d\'un espace')]
    #[UiGroup('Administration', 'Accès système')]
    #[RoleCrudManageable]
    public const MEMBER = 'plg_core__MEMBER';

    #[PermDef('Edition des extensions', '')]
    #[UiGroup('Administration', 'Extensions et SSO')]
    public const EXTENSION_EDIT = 'plg_core__EXTENSION_EDIT';

    #[PermDef('Administration des SSO', '')]
    #[UiGroup('Administration', 'Extensions et SSO')]
    public const SSO_CRUD = 'plg_core__SSO_CRUD';

    #[PermDef('Utiliser l’assistance IA', 'Utiliser les modèles IA configurés dans un espace')]
    #[UiGroup('IA', 'Utilisation')]
    #[RoleCrudManageable]
    public const AI_USE = 'plg_core__AI_USE';

    #[PermDef('Générer des images avec l’IA', 'Autoriser la génération d’images IA dans un espace')]
    #[UiGroup('IA', 'Utilisation')]
    #[RoleCrudManageable]
    #[RequiresPermissions(self::AI_USE)]
    public const AI_GENERATE_IMAGE = 'plg_core__AI_GENERATE_IMAGE';


    #[PermDef('Ajouter une police', 'Installer une police partagée par tous les espaces')]
    #[UiGroup('Site', 'Polices')]
    #[RoleCrudManageable]
    public const FONT_ADD = 'plg_core__FONT_ADD';

    #[PermDef('Gérer les polices', 'Gérer la bibliothèque commune depuis l’espace racine')]
    #[UiGroup('Administration', 'Polices')]
    #[RequiresPermissions(self::FONT_ADD)]
    public const FONT_MANAGE = 'plg_core__FONT_MANAGE';

    // Users
    
    #[PermDef('Voir les utilisateurs', '')]
    #[UiGroup('Utilisateurs', 'Comptes')]
    public const USERS_READ = 'plg_core__USERS_READ';
    
    #[PermDef('Modifier les utilisateurs', 'Créer, modifier, supprimer des utilisateurs')]
    #[UiGroup('Utilisateurs', 'Comptes')]
    #[RequiresPermissions(self::USERS_READ)]
    public const USERS_CRUD = 'plg_core__USERS_CRUD';

    #[PermDef('Administrer les utilisateurs', 'Modification du mot de passe et des informations de connexion et de sécurité.')]
    #[UiGroup('Utilisateurs', 'Comptes')]
    #[RequiresPermissions(self::USERS_READ)]
    public const USERS_ADMIN = 'plg_core__USERS_ADMIN';

    #[PermDef('Voir les groupes d’utilisateurs', '')]
    #[UiGroup('Utilisateurs', 'Groupes')]
    public const USER_GROUPS_READ = 'plg_core__USER_GROUPS_READ';

    #[PermDef('Modifier les groupes d’utilisateurs', 'Créer, modifier, supprimer des groupes d’utilisateurs')]
    #[UiGroup('Utilisateurs', 'Groupes')]
    #[RequiresPermissions(self::USER_GROUPS_READ)]
    public const USER_GROUPS_CRUD = 'plg_core__USER_GROUPS_CRUD';

    #[PermDef('Attribuer les groupes aux utilisateurs', 'Ajouter et retirer les appartenances directes aux groupes')]
    #[UiGroup('Utilisateurs', 'Attribution des groupes')]
    #[RequiresPermissions(self::USER_GROUPS_READ, self::USERS_READ)]
    public const USER_GROUPS_ASSIGN = 'plg_core__USER_GROUPS_ASSIGN';

    #[PermDef('Attribuer les rôles aux groupes', 'Ajouter et retirer les rôles des groupes, avec contrôle de délégation')]
    #[UiGroup('Utilisateurs', 'Attribution des rôles')]
    #[RequiresPermissions(self::USER_GROUPS_READ, self::ROLE_READ)]
    public const GROUP_ROLES_ASSIGN = 'plg_core__GROUP_ROLES_ASSIGN';

    // ACL
    #[PermDef('Voir les rôles des utilisateurs', '')]
    #[UiGroup('Utilisateurs', 'Attribution des rôles')]
    #[RequiresPermissions(self::ROLE_READ)]
    public const USER_ROLES_READ = 'plg_core__USER_ROLES_READ';

    #[PermDef('Modifier les rôles des utilisateurs', 'Créer, modifier, supprimer des rôles des utilisateurs')]
    #[UiGroup('Utilisateurs', 'Attribution des rôles')]
    #[RequiresPermissions(self::USER_ROLES_READ, self::USERS_READ)]
    public const USER_ROLES_CRUD = 'plg_core__USER_ROLES_CRUD';

    #[PermDef('Voir les rôles', '')]
    #[UiGroup('Utilisateurs', 'Définition des rôles')]
    public const ROLE_READ = 'plg_core__ROLE_READ';

    #[PermDef('Modifier les rôles', 'Créer, modifier, supprimer des rôles')]
    #[UiGroup('Utilisateurs', 'Définition des rôles')]
    #[RequiresPermissions(self::ROLE_READ)]
    public const ROLE_CRUD = 'plg_core__ROLE_CRUD';

    // Site
    #[PermDef('Modifier le site', '')]
    #[UiGroup('Site', 'Espaces')]
    #[RequiresPermissions(self::MENU_READ, self::MEDIA_READ)]
    public const SPACE_EDIT = 'plg_core__SPACE_EDIT';

    #[PermDef('Administrer le site', '')]
    #[UiGroup('Site', 'Espaces')]
    #[RequiresPermissions(
        self::MEMBER,
        self::SPACE_EDIT,
        self::PAGE_CRUD,
        self::MENU_CRUD,
        self::MEDIA_CRUD,
        self::TEMPLATE_EDIT,
        self::LANG_OVERRIDE_CRUD,
        self::EMAIL_TEMPLATE_EDIT,
        self::USERS_CRUD,
        self::USER_GROUPS_CRUD,
        self::USER_GROUPS_ASSIGN,
        self::USER_ROLES_CRUD,
        self::GROUP_ROLES_ASSIGN,
        self::ROLE_CRUD,
    )]
    public const SPACE_ADMIN = 'plg_core__SPACE_ADMIN';

    #[PermDef('Surcharger les traductions', 'Créer des clés propres au Space et modifier ses traductions')]
    #[UiGroup('Site', 'Traductions')]
    #[RoleCrudManageable]
    public const LANG_OVERRIDE_CRUD = 'plg_core__LANG_OVERRIDE_CRUD';

    #[PermDef('Personnaliser les modèles d’e-mail', 'Modifier l’objet et le contenu des e-mails dans cet espace')]
    #[UiGroup('Site', 'E-mails')]
    #[RoleCrudManageable]
    public const EMAIL_TEMPLATE_EDIT = 'plg_core__EMAIL_TEMPLATE_EDIT';

    #[PermDef('Modifier la template', '')]
    #[UiGroup('Site', 'Template')]
    #[RoleCrudManageable]
    #[RequiresPermissions(self::PAGE_READ, self::MEDIA_READ)]
    public const TEMPLATE_EDIT = 'plg_core__TEMPLATE_EDIT';

    #[PermDef('Voir les médias', '')]
    #[UiGroup('Site', 'Médias')]
    #[RoleCrudManageable]
    public const MEDIA_READ = 'plg_core__MEDIA_READ';

    #[PermDef('Modifier les médias', 'Créer, modifier, supprimer des médias')]
    #[UiGroup('Site', 'Médias')]
    #[RoleCrudManageable]
    #[RequiresPermissions(self::MEDIA_READ)]
    public const MEDIA_CRUD = 'plg_core__MEDIA_CRUD';

    #[PermDef('Voir les menus', '')]
    #[UiGroup('Site', 'Menus')]
    #[RoleCrudManageable]
    public const MENU_READ = 'plg_core__MENU_READ';

    #[PermDef('Modifier les menus', 'Créer, modifier, supprimer des menus')]
    #[UiGroup('Site', 'Menus')]
    #[RoleCrudManageable]
    #[RequiresPermissions(self::MENU_READ, self::PAGE_READ)]
    public const MENU_CRUD = 'plg_core__MENU_CRUD';

    #[PermDef('Voir les pages', '')]
    #[UiGroup('Site', 'Pages')]
    #[RoleCrudManageable]
    public const PAGE_READ = 'plg_core__PAGE_READ';

    #[PermDef('Modifier les pages', 'Créer, modifier, supprimer des pages')]
    #[UiGroup('Site', 'Pages')]
    #[RoleCrudManageable]
    #[RequiresPermissions(self::PAGE_READ)]
    public const PAGE_CRUD = 'plg_core__PAGE_CRUD';

}
