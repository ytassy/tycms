# Administration des utilisateurs

La page **Utilisateurs** utilise `/administrator/api/users`. Le contrôleur
adapte les entrées HTTP ; `WFAdminUser` valide les droits et les données ;
`UserAdminHelper` exécute les lectures groupées et les écritures SQL. Les routes
POST utilisent le jeton CSRF et la transaction du routeur.
`WFUser` reste consacré à la connexion et à l'inscription publiques.
Le composant public **Mon compte** et `WFMyAccount` permettent au propriétaire
de gérer son propre profil, sans droits administratifs : voir [Mon compte](MY_ACCOUNT.md).

## Liste et périmètre

`GET /administrator/api/users` accepte `page` (à partir de 1), `perPage`
(10, 20, 50, 100 ou 500) et `search` (nom, prénom ou e-mail). Le tri est stable :
nom, prénom, identifiant. Le total et la page sont calculés en SQL ; les
appartenances, groupes et rôles de la page sont chargés par requêtes groupées.
La page Vue utilise son propre tableau et sa pagination serveur. Le tableau
s'ajuste à son contenu, sans largeur de 100 % ni largeur minimale imposée.

Les composants de `src/ria/src/components/administrator/users` séparent
l'affichage et les brouillons : `UserTable.vue` pour le tableau,
`UserAccountEditor.vue` pour la création et l'édition du compte,
`UserSecurityEditor.vue` pour le mot de passe et les connexions SSO, et
`UserAssignmentsEditor.vue` pour les attributions. Les modales émettent leurs
actions vers `index.vue`, qui conserve la pagination, les appels API et leurs
résultats. Les saisies temporaires restent dans les modales ; le mot de passe
de sécurité est effacé à leur fermeture ou démontage.

Dans le tableau, un petit bouton **Modifier** à côté des colonnes **Groupes**
et **Rôles** ouvre une modale dédiée. Les ajouts et retraits restent dans son
brouillon jusqu'à **Enregistrer**. Chaque modale transmet les listes avant et
après modification dans une seule requête POST (`groups/save` ou `roles/save`) ;
le serveur refuse un brouillon
devenu obsolète et applique le lot dans la transaction de la route. La modale
du compte est réservée aux informations générales ; elle se ferme après un
enregistrement réussi et reste ouverte si l'API refuse la modification. Les
choix disponibles s'affichent dans la modale avec une recherche locale, et les
catalogues de groupes et rôles sont rechargés à son ouverture.

Le droit de lecture d'un compte se vérifie sur `users.space_id`, son espace
principal. `USERS_READ` suffit pour ouvrir sa fiche ; les autres permissions
de comptes l'ont pour prérequis explicite. La liste ne contient que les
comptes dont cet espace est lisible. Les colonnes **Groupes** et **Rôles**
présentent les appartenances directes aux groupes `type=0` et les attributions
du groupe personnel `type=1`, y compris le rôle Membre. Les groupes ne sont
transmis qu'avec `USER_GROUPS_READ` sur leur espace, et les rôles qu'avec
`USER_ROLES_READ` sur leur espace cible. Les espaces d'appartenance calculés
par MEMBER suivent `USER_ROLES_READ`. Le groupe personnel lui-même n'est jamais
présenté. Les espaces d'appartenance ne sont pas affichés ni éditables dans
cette interface ; l'espace principal reste affiché.

## Espace principal du compte

`users.space_id` désigne l’**espace principal** : le rattachement administratif
du compte, utilisé pour les droits de lecture, de modification, de suppression,
d’attribution et de sécurité. Cet espace détermine également la politique de
récupération d’un mot de passe local pour un compte lié au SSO.

Il est choisi explicitement lors de la création administrative. À la première
inscription publique locale ou SSO, il vient de `registration_target_space_id`
du Space d’inscription, avec repli sur ce Space si l’option est vide. Le groupe
personnel suit ce rattachement, tandis que les adhésions, rôles, groupes et
bienvenues restent ceux du Space d’inscription. Voir `Docs/REGISTRATION.md`.
L’espace principal reste ensuite fixe. Rejoindre un autre espace ne le modifie pas. Les appartenances
et les rôles sur les autres espaces sont gérés séparément. L’espace actif de
navigation est résolu par le choix en session, l’hôte et le contexte des droits ;
l’espace principal ne lui donne pas de priorité.

L’interface utilise le libellé **Espace principal** ; la colonne SQL et le champ
API restent `space_id`. Ce renommage ne change ni les données ni les droits.

## Compte, mot de passe et SSO

Le parcours public « Mot de passe oublié » est décrit dans
`Docs/PASSWORD_RESET.md`. Il récupère un compte global depuis tout espace et
révoque ses sessions en base après changement. Pour un compte lié au SSO,
`sso_allow_reset_local_password` doit être activé dans son espace principal.
Un changement administratif de mot de passe, d'e-mail ou un blocage invalide
les liens de récupération en attente.

`USERS_CRUD` sur `users.space_id` permet la création, la modification des
informations générales et la suppression du compte. L'espace principal est
fixe. Le fuseau horaire se choisit dans la table fournie au contexte Vue par la
template MainTemplate : la clé est l'identifiant IANA enregistré et le libellé montre
son décalage UTC à la date courante. Les choix sont classés par décalage, puis
par identifiant. La création installe le groupe personnel puis applique
l'adhésion et les attributions automatiques de l'espace comme une
inscription. `registration_auto_member_role` contrôle l’ajout implicite du rôle
Membre. Si les rôles et groupes appliqués n’accordent pas `MEMBER` effectif sur
cet espace, la création entière est annulée avant toute bienvenue ; voir
[Inscription](REGISTRATION.md). Vue génère un mot de passe si le champ est vide, l'envoie à l'API de
création et l'affiche une seule fois. Le serveur exige de 12 à 72 octets UTF-8 sans caractère nul et
stocke seulement son hash. La modale coche par défaut « Envoyer l'e-mail de
bienvenue » ; `send_welcome_email` est facultatif dans l'API et absent signifie
aucun envoi, pour préserver les appels existants. Le modèle
`admin_account_welcome` contient le mot de passe initial dans le corps du
message. En cas d'échec SMTP, le compte reste créé, `welcome_email_sent` vaut
`false` dans la réponse et un avertissement est placé dans les messages en
attente. L'option est indépendante du réglage `send_email` du compte.

Le bouton **Connexion et sécurité** de la colonne **Actions** ouvre une modale
dédiée au compte sélectionné, visible uniquement avec `USERS_ADMIN`. Le compte
et ses droits sont rechargés à l'ouverture. Le formulaire **Nouveau mot de
passe** a son bouton **Enregistrer le mot de passe** sous le champ ; un succès
ferme la modale et efface le secret saisi, tandis qu'un refus affiche l'erreur dans
la modale. Fermer sans enregistrer efface aussi le secret. Les connexions SSO
sont listées dans cette même modale ; leur suppression demande confirmation,
s'applique immédiatement et affiche son résultat sur place.

`USERS_ADMIN` sur `users.space_id` permet de changer le mot de passe et de
supprimer un lien SSO. La suppression du dernier lien d'un fournisseur supprime
aussi son jeton OAuth. Le rôle Root reçoit `USERS_ADMIN` par la migration
Core 1.0.7. Bloquer un compte révoque ses sessions et empêche les connexions
locales et SSO ; on ne peut pas bloquer son propre compte.

## Espaces, rôles et groupes

`USER_ROLES_CRUD` sur `users.space_id`, sur l'espace cible de l'attribution et
sur l'espace propriétaire du rôle permet les changements de rôles. Un rôle
direct doit être global (type 0 ou 2) et appartenir à l'espace cible ou à un de
ses ancêtres. L'ajout ou le retrait d'un groupe exige séparément
`USER_GROUPS_ASSIGN` sur l'espace principal du compte et sur celui du groupe.
Un groupe doit être de type 0 dans l'espace cible. `USER_GROUPS_CRUD` gère la
définition des groupes, sans autoriser l'affectation des comptes ;
l'attribution des rôles à un groupe exige `GROUP_ROLES_ASSIGN`, avec les
prérequis `USER_GROUPS_READ` et `ROLE_READ`.
La migration Core `1.0.14` ajoute `USER_GROUPS_ASSIGN` aux rôles qui possèdent
déjà `USER_ROLES_CRUD`, sans doublon et sans modifier leurs autres permissions.
Sur une installation existante, elle est exécutée explicitement par le système
de mise à jour, comme les autres migrations en attente.

Les rôles système Invité (1) et Utilisateur (3) et les groupes système Invité
(1) et Utilisateurs (2) sont absents des catalogues d'attribution. Le serveur
refuse aussi une attribution directe ou un lot qui tenterait de les ajouter,
même pour ROOT. Leurs liens système restent inchangés ; seul un super
administrateur modifie les permissions des deux rôles. Voir
[Rôles](ROLES.md#rôles-implicites-invité-et-utilisateur) et
[Groupes](USER_GROUPS.md).

Un compte possédant ROOT ne peut être modifié administrativement que par un
ROOT : profil, e-mail, mot de passe, SSO, blocage, suppression et attributions.
La protection ne dépend ni du rôle système 2 ni de l’espace principal du
compte ; les groupes parents sont inclus. La lecture reste soumise aux droits
habituels. Le WF relit ce statut pour toute écriture après verrouillage du
compte. La fiche, la liste et le tableau d’attributions renvoient leurs
capacités d’écriture à `false` pour un appelant non-ROOT. La lecture ciblée de
ROOT est groupée par page dans `UserAdminHelper::getRootUserIds()`, sans
reconstruire toutes les permissions de chaque compte ni utiliser un cache ACL
pour les écritures.

La transmission de ROOT exige un appelant ROOT et une attribution globale
sur le Space 1. L’ajout à un groupe donnant ROOT, parents compris, applique
cette même restriction. Les créations de comptes utilisent l’inscription et
n’accordent jamais ROOT automatiquement. Ces protections sont communes aux
anciennes modales, aux lots et au tableau d’attributions, dans les helpers ;
voir [ROOT](ROLES.md#root-à-léchelle-de-linstallation).

L'attribution
d'un rôle ou d'un groupe n'exige pas que `MEMBER` soit déjà effectif dans cet
espace ; les permissions sur les espaces concernés restent obligatoires.
L'API historique expose `can_assign` pour les rôles et `can_assign_groups`
pour les groupes, à la fois sur le compte et dans les capacités des Spaces du
répertoire. `can_assign` exige `USER_ROLES_CRUD` et `USER_ROLES_READ` ;
`can_assign_groups` exige `USER_GROUPS_ASSIGN` et `USER_GROUPS_READ`. Le
catalogue de groupes exige `USER_GROUPS_READ` et `USER_GROUPS_ASSIGN`.

Chaque attribution est contrôlée par la délégation : l'appelant ne peut ajouter
ou retirer qu'un rôle ou un groupe dont il possède toutes les permissions sur
les Spaces concernés, parents de groupe et attributions historiques compris.
Les catalogues de la fiche et du tableau croisé ne proposent donc que les
éléments délégables ; une écriture directe reste refusée par le WF.

La permission `plg_core__MEMBER` permet d'entrer dans un Space sans devoir s'y
inscrire. Elle définit les appartenances effectives : tout rôle global qui la
contient suffit, qu'il soit attribué au groupe personnel ou reçu par un groupe
collectif et ses parents. Les permissions descendent la hiérarchie des Spaces :
un membre d'un Space accède aussi à tous ses descendants, sans nouvelle
inscription et sans application des rôles/groupes d'inscription des enfants.
Une attribution sur un enfant n'accorde aucun accès au parent ni aux frères.

Les appartenances sont une conséquence des attributions, sans action autonome
d'ajout ou de retrait d'un Space. Les anciennes routes `users/space` et
`users/spaces/save`, leurs méthodes de WF et le nettoyage associé ont été
supprimés. Le rôle Membre est désormais attribuable et retirable comme les
autres rôles globaux, avec les mêmes droits `USER_ROLES_CRUD` sur le compte,
la cible et le propriétaire du rôle. Retirer un rôle ne supprime ni les autres
rôles ni les groupes ; une autre source de `MEMBER` peut maintenir l'accès.

L'API de liste et de fiche conserve `spaces` (IDs des Spaces où `MEMBER` est
effectif) et expose `inherited_spaces` (sous-ensemble reçu uniquement par les
Spaces ancêtres, sans source sur le Space lui-même). Le serveur calcule ces
listes, triées et dédupliquées, à partir des permissions des rôles, des groupes
et des ancêtres de chaque Space, avec des lectures groupées pour la page.
Ces données sont informatives ; elles ne sont pas des attributions modifiables.

La suppression d'un compte exige `USERS_CRUD` et `USER_ROLES_CRUD` sur son
espace principal, puis `USER_ROLES_CRUD` sur **tous** les espaces où `MEMBER`
est effectif. Elle exige aussi `USER_GROUPS_ASSIGN` sur l'espace principal et
sur chaque espace propriétaire d'un groupe direct, même si ce groupe n'accorde
pas `MEMBER`. Le serveur recalcule ces espaces au moment de la suppression.
La suppression de son propre compte est refusée.

Le compte courant ne peut jamais se retirer lui-même du groupe système
**Super Admin** (ID 3). `WFAdminUser` refuse explicitement le retrait direct du
groupe, y compris lors de l'enregistrement d'un lot.

## API dédiée au tableau croisé des attributions

La page **Attributions utilisateurs**, accessible depuis le menu Utilisateurs
et ACLs à `/administrator#/user_assignments`, utilise
`/administrator/api/user-assignments`, avec `WFAdminUser` et `UserAdminHelper`.
Elle apparaît avec `USERS_READ` sur au moins un Space, même si ce n'est pas le
Space courant. Les modales de la page Utilisateurs utilisent aussi
`can_assign_groups` pour les groupes, indépendamment de `can_assign` pour les rôles.

### Présentation et interactions

Le sélecteur de Space porte uniquement sur les colonnes, séparées en deux blocs
Rôles et Groupes. Les interrupteurs `input_switch` « Afficher les rôles » et
« Afficher les groupes », activés par défaut, permettent de masquer indépendamment ces blocs. Ils sont
locaux à l'écran et ne déclenchent ni requête ni modification des attributions.
Les utilisateurs restent affichés même si les deux blocs sont masqués.
Tous les comptes dont l'espace principal est lisible restent
recherchables, avec pagination serveur de 10, 20, 50, 100 ou 500 lignes. Une case
représente une attribution directe, sans déduire de coche des groupes ou des
Spaces parents. La première colonne et les deux lignes d'en-têtes restent
fixes dans la zone de défilement ; le survol colore la ligne et la colonne,
avec une intersection accentuée.

`components/administrator/user_assignments/index.vue` porte les filtres et
`AssignmentMatrix.vue` le tableau et son survol. Le store
`admin_user_assignments.js` porte les lectures et écritures. Il ignore les
réponses périmées lors d'un changement de recherche ou de Space. Une recherche
en attente bloque les cases jusqu'au chargement de ses résultats.

La cellule entière est cliquable avec une grande icône de case à cocher. Par
choix fonctionnel, les boutons des cases sont exclus de la tabulation et les
activations clavier ou clics synthétiques sont ignorés. Le clavier conserve
l'accès aux filtres, à la pagination et au défilement du tableau. Une case
désactivée indique dans son infobulle le droit manquant ou la protection du
groupe Super Admin courant.

Chaque clic enregistre immédiatement une seule cellule. Le store bloque les
autres écritures pendant la requête ; un indicateur d'attente occupe la case
concernée, qui ne change qu'après confirmation exacte de l'API. Aucun bandeau
de succès ne déplace le tableau. Après un refus, une réponse incohérente ou une
coupure réseau, le répertoire et la page sont relus avant de permettre une
nouvelle écriture. Modifier ses propres attributions recharge aussi les
capacités et les colonnes autorisées.

### Contrat des endpoints

- `GET /directory` retourne les Spaces où l'administrateur possède exactement
  le droit `USERS_READ`, via `AclHelper::getSpacesHavingGlobalPermission()`.
  Les capacités `can_assign_roles` et `can_assign_groups` indiquent les blocs
  de colonnes accessibles dans chacun de ces Spaces.
- `GET /` exige `spaceId`, avec `page`, `perPage` et `search` selon les mêmes
  bornes et le même tri que la liste historique. Le Space sélectionné doit être
  lisible avec `USERS_READ`. Il filtre uniquement les colonnes ; les lignes
  couvrent tous les comptes dont l'espace principal possède `USERS_READ`,
  sans condition d'appartenance ou d'attribution dans le Space sélectionné.
- Le catalogue `roles` contient uniquement les rôles globaux de type `0` ou
  `2` créés dans le Space sélectionné, hors rôles implicites Invité et
  Utilisateur. Il exige simultanément `ROLE_READ` et `USER_ROLES_CRUD` sur ce
  Space. Le catalogue `groups` contient uniquement les groupes de type `0` de
  ce Space, hors groupes système Invité et Utilisateurs, avec
  `USER_GROUPS_READ` et `USER_GROUPS_ASSIGN`. Un catalogue non autorisé et ses
  attributions sont vides. Chaque catalogue est ensuite filtré par la
  délégation : les rôles et groupes dont l'appelant ne possède pas toutes les
  permissions ne sont pas proposés.
- Chaque compte expose son identité, son espace principal, `role_ids` et
  `group_ids`. Ces listes représentent seulement les attributions directes
  dans ce périmètre ; aucun groupe personnel, rôle reçu par groupe ou héritage
  n'est présenté comme une attribution directe. Les capacités par ligne
  ajoutent le droit d'attribution correspondant sur l'espace principal.
  `protected_group_ids` indique la protection du groupe Super Admin courant.
- `POST /save` reçoit `data` : `user_id`, `space_id`, `kind` (`role` ou
  `group`), `item_id`, `before` et `assigned` (booléens distincts). Le WF relit
  le compte, le rôle ou groupe et son propriétaire réel, puis vérifie les
  droits de lecture, d'attribution et la délégation des permissions. Il refuse
  notamment les rôles d'un ancêtre dans cette API et le retrait de son propre
  groupe Super Admin.

Le verrou du compte sérialise les modifications. La comparaison avec `before`
utilise une lecture verrouillée pour voir l'état courant même si une lecture
antérieure a ouvert un instantané SQL `REPEATABLE READ`. Un état obsolète est
refusé et demande d'actualiser le tableau. L'écriture bénéficie du CSRF et de la
transaction du routeur. Elle renvoie uniquement la cellule confirmée :
`user_id`, `space_id`, `kind`, `item_id`, `assigned`.

Les lectures sont groupées pour les seuls comptes de la page. Cette API
n'appelle pas `getRelations()` et ne reconstruit ni l’ensemble des permissions
ni les appartenances des comptes affichés, y compris après une écriture. Une
lecture ciblée et groupée de ROOT protège leurs capacités d’attribution ; le
serveur revérifie ce statut lors de l’écriture. Les permissions ordinaires
contrôlées sont celles de l’administrateur. Les autres routes historiques
conservent leur contrat de fiche et d'appartenances calculées.
