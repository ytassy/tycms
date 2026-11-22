<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\BasePermissions;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\PermDef;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\RoleCrudManageable;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\RequiresPermissions;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\UiGroup;

final class Permissions extends BasePermissions
{
    #[PermDef('Voir les automatisations', 'Consulter les scénarios et les runs du Space.')]
    #[UiGroup('Automatisation', 'Scénarios')]
    #[RoleCrudManageable]
    public const VIEW = 'plg_automation__VIEW';

    #[PermDef('Modifier les automatisations', 'Créer et modifier les brouillons de scénarios.')]
    #[UiGroup('Automatisation', 'Scénarios')]
    #[RoleCrudManageable]
    #[RequiresPermissions(self::VIEW)]
    public const EDIT = 'plg_automation__EDIT';

    #[PermDef('Publier les automatisations', 'Publier, désactiver ou archiver un scénario.')]
    #[UiGroup('Automatisation', 'Scénarios')]
    #[RoleCrudManageable]
    #[RequiresPermissions(self::VIEW)]
    public const PUBLISH = 'plg_automation__PUBLISH';

    #[PermDef('Exécuter les automatisations', 'Lancer un run manuel ou un dry-run.')]
    #[UiGroup('Automatisation', 'Exécution')]
    #[RoleCrudManageable]
    #[RequiresPermissions(self::VIEW)]
    public const RUN = 'plg_automation__RUN';

    #[PermDef('Gérer les exécutions', 'Annuler, relancer ou purger un run.')]
    #[UiGroup('Automatisation', 'Exécution')]
    #[RoleCrudManageable]
    #[RequiresPermissions(self::RUN)]
    public const RUN_MANAGE = 'plg_automation__RUN_MANAGE';

    #[PermDef('Publier en mode auteur', 'Autoriser un scénario à s’exécuter avec les droits de son auteur.')]
    #[UiGroup('Automatisation', 'Mode auteur')]
    #[RoleCrudManageable]
    #[RequiresPermissions(self::PUBLISH)]
    public const RUN_AS_AUTHOR = 'plg_automation__RUN_AS_AUTHOR';

    /**
     * Configuration sensible : comptes de service, jetons de notification,
     * rétention. Réservée à ROOT et non exposée à l'édition des rôles.
     */
    #[PermDef('Administrer les automatisations', 'Modifier les comptes de service, les canaux de notification et la rétention.')]
    #[UiGroup('Automatisation', 'Configuration')]
    #[RequiresPermissions(self::VIEW)]
    public const ADMIN = 'plg_automation__ADMIN';
}
