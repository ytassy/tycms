# Cohérence des permissions et des accès d’administration

## Périmètre retenu

Les permissions déclarent leurs prérequis avec `RequiresPermissions`. Un rôle
doit contenir explicitement tous les prérequis transitifs de ses permissions.
La validation ne cumule jamais les droits de plusieurs rôles et le calcul des
ACL ne complète pas les permissions à la lecture. Les droits restent hérités
entre Spaces selon le contrat actuel.

ROOT désigne le super administrateur de l’installation : permission `ROOT`
sur le Space 1, jamais contrôle du rôle exact pour autoriser l’appelant.
Les protections d’identité et de liens des rôles/groupes système restent
distinctes de cette autorisation.

Les dépendances ne remplacent ni les contrôles sur le Space réel, ni ceux de
délégation des privilèges. Aucun changement de données existantes par migration
n’est prévu. Les nouveaux contrats imposent une remise en cohérence explicite
des rôles existants avant leur enregistrement.

## Lots et point de reprise

| Lot | Contenu | État |
| --- | --- | --- |
| 1 | Annotation, graphe des prérequis Core/Automatisation, validation serveur par rôle, aide dans l’éditeur et tests ciblés. Déclaration de `GROUP_ROLES_ASSIGN`. | Réalisé |
| 2 | ROOT uniforme sur le Space 1, sérialisation ORM, restrictions d’attribution de ROOT et rôles cohérents pour les nouvelles installations. | Réalisé |
| 3 | Lectures de comptes par `USERS_READ`, données d’attribution limitées aux droits correspondants, `GROUP_ROLES_ASSIGN` dans les WF/interface et délégation commune. | Réalisé |
| 4 | Contrôles de lecture simplifiés, correction des pages jointes aux menus, purification HTML dans Pages, validation de brouillon commune et vérifications finales. | Réalisé |

Le lot 3 a remplacé les lectures alternatives des comptes par `USERS_READ` et
raccordé `GROUP_ROLES_ASSIGN` aux WF et à l'interface des groupes. La délégation
des permissions s'applique aux attributions de rôles et de groupes. Les
catalogues ne proposent que les éléments délégables ; une écriture directe
reste refusée.

Le lot 4 simplifie les lectures Pages, Langues et Médias sur leur permission
de lecture, corrige les catalogues de pages et de menus pour qu'ils utilisent
`PAGE_READ` et `MENU_READ` sans repli sur le Space 1, filtre leurs options sur
le Space du formulaire et réserve les entrées de menu de type page à une page
publiée du même Space. Le menu principal et le pied de page de la template
restent dans le Space configuré ou un de ses parents. Le rendu d'une page
absente, non publiée ou d'un autre Space répond 404. La sauvegarde, l'aperçu,
le canevas, `validate-draft` et la validation IA partagent la même validation
de document.

Le chantier est terminé. Le catalogue et l’éditeur savent détecter et
compléter les rôles incomplets ; les nouvelles installations enregistrent des
rôles Root, Administrateur et Membre cohérents avec le graphe Core. Les dossiers
initiaux des médias Root et Site sont distincts pour respecter leur unicité.
ROOT est contrôlé uniformément par sa permission globale sur le Space 1 en PHP
et Vue ; les champs ORM internes de User utilisent également ce contrôle et
les secrets restent cachés. Seul un ROOT peut transmettre ROOT manuellement
sur le Space 1, jamais sur un objet ni automatiquement à l’inscription. Les
liens de groupes et de leurs parents sont couverts, y compris le retrait.
Toutes les modifications administratives de comptes ROOT sont réservées à ROOT,
avec des capacités d’interface cohérentes et une lecture ciblée groupée par page.
Le lot 3 exige `USERS_READ` pour lire un compte, filtre les groupes et rôles
selon `USER_GROUPS_READ` et `USER_ROLES_READ`, et réserve les rôles de groupe à
`GROUP_ROLES_ASSIGN`. La délégation commune `canDelegateGlobalPermissions`
contrôle les rôles, les groupes, leurs parents et leurs Spaces d'attribution
dans les WF de comptes, de groupes et du tableau croisé. Aucune migration de
rôle existant n'a été ajoutée : un rôle qui possédait `USER_GROUPS_CRUD` doit
recevoir `GROUP_ROLES_ASSIGN` dans l'éditeur pour continuer à modifier les
rôles de groupe.

## Graphe fonctionnel retenu

Les prérequis d’une ligne sont cumulés ; leurs propres prérequis s’appliquent.

| Permission Core | Prérequis directs |
| --- | --- |
| `PAGE_CRUD` | `PAGE_READ` |
| `MENU_CRUD` | `MENU_READ`, `PAGE_READ` |
| `MEDIA_CRUD` | `MEDIA_READ` |
| `USERS_CRUD`, `USERS_ADMIN` | `USERS_READ` |
| `USER_GROUPS_CRUD` | `USER_GROUPS_READ` |
| `USER_GROUPS_ASSIGN` | `USER_GROUPS_READ`, `USERS_READ` |
| `USER_ROLES_READ` | `ROLE_READ` |
| `USER_ROLES_CRUD` | `USER_ROLES_READ`, `USERS_READ` |
| `GROUP_ROLES_ASSIGN` | `USER_GROUPS_READ`, `ROLE_READ` |
| `ROLE_CRUD` | `ROLE_READ` |
| `SPACE_EDIT` | `MENU_READ`, `MEDIA_READ` |
| `TEMPLATE_EDIT` | `PAGE_READ`, `MEDIA_READ` |
| `FONT_MANAGE` | `FONT_ADD` |
| `AI_GENERATE_IMAGE` | `AI_USE` |
| `SPACE_ADMIN` | `MEMBER`, `SPACE_EDIT`, `PAGE_CRUD`, `MENU_CRUD`, `MEDIA_CRUD`, `TEMPLATE_EDIT`, `LANG_OVERRIDE_CRUD`, `EMAIL_TEMPLATE_EDIT`, `USERS_CRUD`, `USER_GROUPS_CRUD`, `USER_GROUPS_ASSIGN`, `USER_ROLES_CRUD`, `GROUP_ROLES_ASSIGN`, `ROLE_CRUD` |
| `ROOT` | `SPACE_ADMIN`, `USERS_ADMIN`, `EXTENSION_EDIT`, `SSO_CRUD`, `FONT_MANAGE`, `AI_GENERATE_IMAGE` |

Les autres permissions Core n’ont pas de prérequis. `SPACE_ADMIN` n’exige pas
`USERS_ADMIN`, `SSO_CRUD`, `EXTENSION_EDIT` ou `FONT_MANAGE`. ROOT couvre toutes
les permissions Core par ses prérequis transitifs ; aucune dépendance Core ne
doit rendre obligatoire une extension optionnelle.

| Permission Automatisation | Prérequis directs |
| --- | --- |
| `VIEW` | Aucun |
| `EDIT`, `PUBLISH`, `RUN` | `VIEW` |
| `RUN_MANAGE` | `RUN` |
| `RUN_AS_AUTHOR` | `PUBLISH` |
| `ADMIN` | `VIEW` |

`ADMIN` n'est pas exposée à l'édition ordinaire des rôles : ROOT la reçoit par
la migration `1.0.1` et peut l'attribuer ensuite à un autre rôle. Elle contrôle
la configuration sensible de l'extension (Google, notifications, rétention),
pas la publication des scénarios.

Publier ne demande pas le droit de modifier un scénario. Un rôle d’exécution
reste soumis aux autorisations métier des actions réellement effectuées.

## Vérifications et suites

Pour le lot 1 : tests PHP du catalogue et des refus de sauvegarde, tests Node
de sélection/retrait des prérequis, syntaxe PHP et build Vue. L’essai visuel de
l’éditeur reste manuel avec Yann. Les validations ordinaires ne demandent pas
de journal applicatif ; les catalogues invalides doivent produire une erreur
technique explicite, sans être remplacés par un graphe permissif.

Le lot 2 se vérifie par les refus de stockage et d’attribution ROOT, la
sérialisation des champs sensibles, l’absence de privilège implicite, les rôles
initiaux et les contrôles PHP/Vue cohérents. Le test API d’attribution utilise
uniquement la base dédiée et contrôle l’absence de relations après refus.

Le lot 3 se vérifie par les tests unitaires de `canDelegateGlobalPermissions`,
les tests d’intégration des lectures filtrées, des refus de délégation de rôles
et de groupes, du parent de groupe et des capacités `GROUP_ROLES_ASSIGN`, puis
par le build Vue. Les tests ciblés utilisent uniquement la base dédiée.

Le lot 4 se vérifie par les tests des catalogues de pages et de menus, les
refus d’une page de menu non publiée ou d’un autre Space, la validation commune
des brouillons et les tests Pages/Menus existants. Les essais visuels des liens
de menu et de l’aperçu de page restent manuels avec Yann.

Les contrats techniques livrés sont décrits dans `Docs/ROLES.md`. Les résultats
des commandes sont rapportés dans la conversation, pas conservés comme journal
de tests dans ce document.
