# Notes de la version en préparation

Ce document présente uniquement les changements utiles aux utilisateurs de Tycms.

## Nouveautés

- Catalogues : ROOT voit les catalogues de tous les Espaces, peut éditer n’importe lequel et changer son Espace propriétaire depuis le formulaire, avec confirmation. Le catalogue, ses versions, ses jetons et ses références restent attachés ; seules les permissions d’administration de l’Espace cible s’appliquent ensuite. L’identifiant numérique du catalogue est affiché pour la configuration du script de publication.
- Mise à jour : l’onglet **Mises à jour** permet à ROOT de mettre à jour une extension installée vers la dernière version publiée, si `source_versions` l’autorise. Les fichiers de l’ancienne version sont sauvegardés, les migrations sont exécutées dans une requête neuve et l’ancienne version est restaurée si la finalisation échoue. En cas d’interruption, la consultation propose de reprendre ou de restaurer l’ancienne version ; les migrations SQL déjà exécutées ne sont pas annulées. L’onglet **Gestion** ne contacte pas les catalogues.
- Extensions : l’onglet **Gestion** filtre les extensions par provenance (Core ou catalogue) avec des badges cliquables, sans contacter les URLs des catalogues.
- Désinstallation : ROOT peut désinstaller une extension installée depuis un catalogue. L’extension exécute son hook `uninstallExtension()` pour supprimer ses tables, données, configuration et ACL ; le Core retire ensuite le code, les assets, l’historique de migration et la provenance. Les changements SQL déjà exécutés ne sont pas annulés. Une extension installée manuellement n’est pas désinstallable par ce chemin.
- Automatisations : trois actions lisent et modifient les données de session du navigateur : `core::sessionRead` lit une clé avec une valeur par défaut et un chemin facultatif, `core::sessionWrite` y range une valeur et `core::sessionRemove` l’efface. Chaque clé reçoit automatiquement un espace de noms de l’Espace puis le préfixe `automation_`. La valeur écrite est visible à la requête suivante du visiteur, pas dans la même. La lecture est toujours possible, y compris depuis un scénario en mode auteur (webhook, planification) ; l’écriture et l’effacement restent réservés à un scénario exécuté dans la requête d’un visiteur, les autres étant refusés avec un message explicite. Une valeur est limitée à 16 Kio et à une structure clé/valeur.
- Tableau de bord : une section liste les extensions à mettre à jour et signale les vérifications impossibles, avec un lien direct vers l’onglet **Mises à jour**.
- Administration : une variante d’interface **Moderne** aère la barre latérale, les cartes, les tableaux, les formulaires, les onglets et les modales, sans changer les couleurs du site. Elle se règle dans « Thème : administration » de la configuration de la template ; la variante **Classique** conserve le rendu historique.

## Changements

- Serveur de distribution : une version envoyée par la publication n’est plus servie immédiatement. Elle reste **en attente d’approbation** dans une interface d’administration (`index.php?action=admin`, compte unique configuré sur le serveur). L’administrateur peut l’approuver en **Bêta**, l’approuver directement en **Prod**, ou la **refuser** (suppression physique). Une version en bêta peut ensuite être promue en prod ; un retour en arrière est refusé. Le catalogue par défaut ne contient que les versions en prod : les sites déjà installés ne reçoivent une version qu’après approbation. La commande de publication annonce désormais une *proposition enregistrée, en attente d’approbation* au lieu d’annoncer une version publiée.
- Mises à jour : le manifeste signé d'une release précise désormais son canal de diffusion (`prod` ou `beta`). Le serveur de distribution sert les versions de prod par défaut et ne propose une bêta qu'aux installations qui la demandent ; les anciennes releases, sans cette information, restent servies comme des versions de prod. Une installation peut accepter les versions de bêta avec le réglage `update_channel` de sa configuration ; par défaut, elle ne reçoit que les versions de prod.
- Intégration continue : un push sur une branche de production construit la release et l’envoie en proposition d’approbation sur le serveur de distribution, après validation de l’environnement protégé. Les branches de développement et les pull requests vérifient la suite unitaire PHP, le frontend et la suite du serveur de distribution, sans base SQL. Les scripts de préparation et de vérification partagés sont dans `cicd/`, sans secret en argument.

## Corrections

- Journaux : désactiver le compte d'administration du serveur de distribution ferme désormais aussi les sessions déjà ouvertes.
- Tests : les contrôles de concurrence des journaux et du serveur de distribution ne dépendent plus de la charge de la machine.
- Build : les images de `plg_core` (dont le logo TyCMS) sont de nouveau incluses dans les releases.
