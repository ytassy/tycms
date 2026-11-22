<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\TyCatalog;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\BasePermissions;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\PermDef;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\RoleCrudManageable;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\RequiresPermissions;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\UiGroup;

final class Permissions extends BasePermissions
{
    #[PermDef('Voir les catalogues', 'Consulter les catalogues hébergés et leurs versions.')]
    #[UiGroup('Catalogues', 'Consultation')]
    #[RoleCrudManageable]
    public const VIEW = 'plg_ty_catalog__VIEW';

    #[PermDef('Gérer les catalogues', 'Créer, modifier, activer ou désactiver un catalogue et ses références amies.')]
    #[UiGroup('Catalogues', 'Gestion')]
    #[RoleCrudManageable]
    #[RequiresPermissions(self::VIEW)]
    public const EDIT = 'plg_ty_catalog__EDIT';

    #[PermDef('Publier les versions', 'Déposer, publier, retirer ou réactiver une version de paquet.')]
    #[UiGroup('Catalogues', 'Publication')]
    #[RoleCrudManageable]
    #[RequiresPermissions(self::VIEW)]
    public const PUBLISH = 'plg_ty_catalog__PUBLISH';

    /**
     * Configuration sensible : racine privée de stockage et clés de confiance.
     * Réservée à ROOT et non exposée à l'édition des rôles.
     */
    #[PermDef('Administrer TyCatalog', 'Choisir la racine privée et les clés de confiance des catalogues.')]
    #[UiGroup('Catalogues', 'Configuration')]
    #[RequiresPermissions(self::VIEW)]
    public const ADMIN = 'plg_ty_catalog__ADMIN';
}
