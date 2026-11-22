# Éditeur de rôles

La page Vue **Rôles** utilise l'API `/administrator/api/roles`. Le contrôleur
transmet au workflow `WFRole`, qui relit le rôle en base avant chaque écriture.
`RoleAdminHelper` porte les requêtes SQL. Les écritures POST utilisent le jeton
CSRF de la session et la transaction de la route.
La liste occupe la largeur disponible et conserve l'ordre alphabétique fourni
par le serveur, sans tri dans le tableau ; la création, la lecture et la modification
s'ouvrent dans une modale commune. Les rôles système affichent aussi le nom
technique de leur constante PHP, transmis par l'API. Les noms techniques des
permissions ne sont pas affichés dans l'éditeur.

## Présentation des permissions

Une constante de permission peut porter `#[UiGroup('Onglet', 'Groupe')]`, en plus
de `#[PermDef]`. L'API transmet ces deux libellés ; la modale place les
permissions dans des onglets, puis sous des titres de groupe simples. Par
exemple :

```php
#[PermDef('Voir les rapports', '')]
#[UiGroup('Rapports', 'Consultation')]
public const REPORT_READ = 'comp_reports__READ';
```

Cette annotation est facultative pour les composants et autres extensions.
Sans elle, la permission apparaît dans l'onglet `Extensions`, sous un titre
portant le nom de l'extension qui l'a déclarée. L'ordre des permissions dans
chaque groupe suit leur ordre de déclaration. `UiGroup` n'accorde aucun droit :
`RoleCrudManageable` et les contrôles du workflow restent seuls responsables
des permissions modifiables.

## Portée des droits

Un rôle appartient à `acl_roles.space_id`. La lecture exige `ROLE_READ` ou
`ROLE_CRUD` sur cet espace. Créer, modifier ou supprimer exige `ROLE_CRUD` sur
l'espace propriétaire, avec l'héritage normal des permissions depuis les espaces
parents. Un super administrateur (`ROOT` sur l'espace racine) peut gérer tous
les espaces. Les rôles système ne sont modifiables que par lui et ne sont jamais
supprimables. Un rôle utilisateur attribué à un groupe ne peut pas être supprimé.

L'espace et le type sont choisis à la création et restent fixes ensuite. La
modification de permissions d'un rôle déjà attribué vérifie également
`ROLE_CRUD` et la permission concernée sur chaque espace d'attribution.

## ROOT à l’échelle de l’installation

L’autorité super administrateur est la permission `plg_core__ROOT` reçue par
une attribution globale sur le **Space 1**, quel que soit le rôle qui la porte.
Utiliser `AclHelper::isRoot()` ; `hasGlobalPermission(ROOT, $space)` et son
équivalent par ID utilisateur consultent aussi exclusivement cette racine.
ROOT sur un autre Space, un type ou une instance d’objet n’accorde jamais
cette autorité. Le rôle système 2 et le groupe 3 restent des identités protégées,
pas le critère d’autorisation. Le menu public peut encore viser un rôle exact
pour sa visibilité ; cela ne remplace aucun contrôle d’administration.

Un rôle portant ROOT doit appartenir au Space 1, être global de type `0` et
contenir explicitement ses prérequis. Les rôles reçus automatiquement Invité,
Utilisateur et Membre ne peuvent pas porter ROOT. Avant de convertir un rôle
existant en rôle ROOT, le serveur refuse ses attributions sur un autre Space,
sur un objet ou aux groupes implicites 1 et 2.

Seul un ROOT connecté peut transmettre un rôle portant ROOT, exclusivement
sur le Space 1 et hors objets et groupes implicites. La même protection couvre
l’ajout d’un compte à un groupe donnant ROOT et le changement de parent d’un
groupe ; les parents réels sont relus. Une inscription, y compris une création
administrative, ne transmet jamais ROOT par ses rôles ou groupes configurés.
Une configuration ancienne qui le demande fait échouer l’inscription et annule
les écritures de la route.

Toutes les modifications administratives d’un compte qui possède ROOT sont
réservées à un appelant ROOT : profil, e-mail, mot de passe, SSO, blocage,
suppression et attributions, y compris les lots et le tableau croisé. Ce statut
est déterminé par la permission sur le Space 1, groupes parents et rôles
personnalisés compris. Les groupes donnant ROOT ne sont pas modifiables par un
non-ROOT, même pour retirer ce droit. Cela empêche également la prise de compte
par changement d’e-mail suivi d’une récupération de mot de passe. Les capacités
retournées à Vue appliquent cette restriction sans interdire la consultation
avec les droits de lecture habituels.

Ces contrôles dans les helpers complètent les ACL du WF. Les rôles, permissions
et groupes concernés sont relus avec verrou dans la transaction d’écriture pour
ne pas décider à partir du catalogue ou des droits envoyés par le navigateur.
ROOT ne donne aucun autre droit implicite dans `AclHelper` : les permissions
ordinaires doivent toujours être présentes explicitement dans les rôles.

Les nouvelles installations construisent les permissions des rôles Root,
Administrateur et Membre à partir de leurs permissions de départ et du graphe
Core (`InitialRolePermissions`, migration initiale). Toutes les permissions
requises sont réellement insérées en base. Les extensions optionnelles gardent
leur propre initialisation. Aucune migration ne réécrit les rôles existants.

## Rôles implicites Invité et Utilisateur

Les rôles système 1 (`ROLE_GEST`, Invité) et 3 (`ROLE_USER`, Utilisateur) sont
attribués par les liens système des groupes 1 et 2. Ils ne peuvent jamais être
ajoutés à un groupe ni à un compte, y compris par ROOT : les catalogues
d'attribution les omettent et les workflows refusent une écriture directe ou un
lot qui les contient. Les listes `registration_roles` et `user_roles` les
excluent et `UserRegistrationHelper::enroll()` les rejette également.

Leur identité reste figée : espace, type, nom et description ne sont pas
modifiables. Seul un super administrateur peut modifier leurs permissions,
depuis l'éditeur de rôle habituel, qui affiche une mention sur leur portée. Le
rôle Invité sert aux personnes non connectées ; le rôle Utilisateur est reçu
automatiquement par toute personne connectée. Les liens de rôle des groupes
Invité et Utilisateurs ne se modifient pas depuis l'éditeur de groupes. Voir
[Groupes d'utilisateurs](USER_GROUPS.md).

## Permissions éditables

### Prérequis explicites dans chaque rôle

Une constante peut déclarer des prérequis dans son catalogue PHP :

```php
#[RequiresPermissions(Permissions::MENU_READ, Permissions::PAGE_READ)]
public const MENU_CRUD = 'plg_core__MENU_CRUD';
```

Importer `RequiresPermissions` depuis `Core\ExtensionApi\Acl`. Tous les noms
sont qualifiés par leur extension. Les prérequis sont cumulés et transitifs :
le rôle doit contenir explicitement chaque permission requise. Aucun droit
d’un autre rôle, de l’utilisateur qui édite ou d’un Space parent ne complète
la définition du rôle enregistré. L’héritage des droits effectifs entre Spaces
reste inchangé ; `AclHelper` n’ajoute aucune permission implicite.

`BasePermissions` lit l’annotation dans `PermDef::requiresPermissions`.
Après l’enregistrement des extensions chargées, `PermissionRequirements`
vérifie l’ensemble du graphe : prérequis inconnus, autoréférences et cycles
sont des erreurs techniques, y compris pour une permission non utilisée.
Les dépendances entre extensions chargées sont possibles ; Core ne doit pas
dépendre d’une extension optionnelle. Un enregistrement supplémentaire de
définitions invalide le graphe mis en cache par `AclHelper`.

`WFRole` refuse un rôle incomplet avant les écritures et nomme les prérequis
manquants. `RoleAdminHelper::replacePermissions()` protège également les
appels internes avant de supprimer les anciennes lignes. Les validations de
délégation existantes restent applicables à chaque permission ajoutée ou
retirée, prérequis compris. Les anciennes permissions d’extensions absentes
peuvent être conservées si elles étaient déjà dans le rôle ; elles n’acquièrent
aucun prérequis inventé et n’autorisent pas l’ajout d’une permission inconnue.

L’API du catalogue expose `direct_required_permissions` pour l’explication et
`required_permissions` pour la liste transitive. Vue utilise ces listes,
sans recopier le graphe métier. Cocher une permission ajoute ses prérequis au
brouillon, seulement si tous les ajouts sont attribuables par l’éditeur. Le
retrait d’un prérequis reste bloqué tant qu’une permission sélectionnée le
requiert ; retirer d’abord les dépendants. Ces ajouts ne sont persistés qu’au
clic sur Enregistrer.

Les rôles existants ne sont pas migrés ni complétés automatiquement. Un
avertissement propose **Ajouter les prérequis au brouillon**, puis l’utilisateur
vérifie et enregistre. Même une modification du nom d’un rôle incomplet exige
sa remise en cohérence. Cette règle s’applique également au rôle système Root,
dont les prérequis couvrent explicitement les permissions Core.

Le graphe retenu et les lots suivants sont décrits dans le
[chantier de cohérence des permissions](IA/CurrentWorks/ACL_PERMISSIONS.md).
`GROUP_ROLES_ASSIGN` protège l'attribution des rôles aux groupes, séparément
de `USER_GROUPS_CRUD`. Les WF de comptes et de groupes appliquent la délégation
des permissions aux attributions qu'ils écrivent ; les lectures de comptes
utilisent `USERS_READ`.

Un rôle de groupe peut être ajouté ou retiré par un titulaire de
`GROUP_ROLES_ASSIGN` sur l'espace du groupe. La définition du groupe (nom,
parent, description) reste soumise à `USER_GROUPS_CRUD`. Les deux droits
peuvent être réunis, mais aucun ne remplace l'autre.

### Délégation des modifications

Une permission est modifiable par un titulaire de `ROLE_CRUD` seulement si sa
constante porte `#[RoleCrudManageable]` **et** si l'éditeur possède cette
permission sur l'espace concerné. L'absence de l'annotation interdit toute
modification à un non-root, y compris le retrait d'une permission déjà présente.
Cette règle empêche notamment de s'enlever `ROLE_CRUD` via cet écran. Les
permissions réservées restent visibles en lecture. Les extensions peuvent
ajouter l'annotation à leurs propres constantes de permissions quand elles
veulent déléguer leur gestion.

Core marque `MEMBER`, `AI_USE`, `AI_GENERATE_IMAGE`, `LANG_OVERRIDE_CRUD`,
`FONT_ADD`, `EMAIL_TEMPLATE_EDIT`, `TEMPLATE_EDIT`, `MEDIA_READ`, `MEDIA_CRUD`, `MENU_READ`,
`MENU_CRUD`, `PAGE_READ` et `PAGE_CRUD`. `ROOT`, les droits de comptes, rôles,
espaces, SSO et `EXTENSION_EDIT` restent réservés au super administrateur. Le rôle 5
doit toujours conserver `MEMBER`, son type global et son rattachement racine ;
le workflow rejette une soumission contraire, même si elle contourne Vue.
Le rôle système 2 conserve également `ROOT` pour éviter de retirer le dernier
accès super administrateur par mégarde.

## Droits sur une instance d'objet

`AclHelper::hasPermissionOn($permission, $extension, $object, $space, $objectId)`
accepte un cinquième argument optionnel. Sans cet argument, le comportement
historique reste celui d'un contrôle sur un type d'objet. Avec un ID positif,
le contrôle cumule les permissions globales, les droits sur le type et les
droits sur cette instance précise, avec l'héritage des Spaces et des groupes.
Un ID nul ou négatif passé explicitement comme entier est refusé ; `null`
conserve le contrôle de type. Un droit sur A ne donne aucun droit sur B.

`getObjectTypeId($extension, $object)` retrouve le type déclaré dans
`acl_objects` ; l'extension doit l'enregistrer par une migration. Les lignes de
`acl_map` ciblant une instance renseignent `space_id`, `acl_object_id` et
`object_id`. Le calcul des droits globaux exclut les lignes qui renseignent
l'un des deux identifiants d'objet, même si la ligne est incohérente.

### Délégation sans augmentation des privilèges

`canDelegatePermissionsOn($managePermission, $permissions, $extension, $object,
$objectId, $space)` vérifie une permission dédiée de gestion des accès, puis
**toutes** les permissions que les rôles modifiés accordent. Les noms fournis
sont complets, par exemple `plg_ty_catalog__UPLOAD`. Ils doivent appartenir à
l'extension ciblée ; le type doit être enregistré et les IDs strictement positifs. ROOT peut
déléguer les permissions valides sans rôle local ; une requête mal formée ne
devient pas valide parce que l'appelant est ROOT.

Un gestionnaire qui possède dépôt et gestion des accès sur A peut transmettre
le dépôt sur A ; il ne peut pas transmettre publication s'il ne la possède pas,
ni agir sur B avec ses seuls droits sur A. Les droits détenus par héritage
comptent. Pour un ajout ou retrait de rôle, fournir ses permissions réellement
lues en base, et jamais celles transmises par le navigateur. Pour modifier un
ensemble de rôles, vérifier l'union des permissions des rôles ajoutés et retirés.

`AclHelper::canDelegateGlobalPermissions($managePermission, $permissions,
$spaceId)` applique le même principe aux attributions globales, sans type
d'objet. Les WF de comptes et de groupes l'utilisent après avoir relu et
verrouillé les rôles concernés. Pour un groupe, les permissions de ses parents
et de tous ses Spaces d'attribution sont prises en compte. Les catalogues
d'attribution ne proposent que les rôles et groupes réellement délégables ;
une écriture directe reste refusée par le WF. Un gestionnaire qui déplace un
groupe sous un parent doit aussi posséder les permissions que ce parent
transmet.

Pour une attribution globale, le WF relit le compte, le rôle ou le groupe,
verrouille les définitions et leurs permissions, puis vérifie l'union des
permissions ajoutées ou retirées. Le retrait d'un rôle ou d'un groupe plus
puissant est refusé comme son ajout ; ROOT peut effectuer ce retrait. Les
rôles de groupe exigent `GROUP_ROLES_ASSIGN`, les attributions de comptes
`USER_ROLES_CRUD` ou `USER_GROUPS_ASSIGN` selon le type.

`canDelegatePermissionsOn()` est un contrôle réutilisable, pas une API
d'attribution. Le WF de la ressource doit, dans la transaction de la route :

1. Relire et verrouiller la ressource, puis utiliser son Space réel.
2. Relire/verrouiller le groupe et les rôles ajoutés/retirés avant le contrôle
   de leurs permissions. Ces verrous doivent précéder la vérification de
   délégation pour empêcher une modification concurrente des rôles.
3. Limiter leurs permissions à une liste métier explicite autorisée pour ce
   type d'objet. Appartenir à la même extension ne suffit pas : une permission
   de configuration globale ne doit pas être introduite dans un rôle de catalogue.
4. Appeler `canDelegatePermissionsOn()` avec le périmètre relu et refuser si
   le résultat vaut `false`. Un droit de gestion seul ne permet pas de retirer
   un rôle plus puissant que ses propres droits ; ROOT peut effectuer ce retrait.
5. Écrire les attributions via `coreUserGroupAdminHelper`.

### Persistance des attributions locales

`UserGroupAdminHelper::getObjectRoleIds($groupId, $spaceId, $aclObjectId, $objectId)`
lit les attributions exactes, sans confondre droits globaux et hérités.
`replaceObjectRoleAssignments(..., $roleIds)` remplace seulement ces quatre
clés de périmètre, dans la transaction du runner. Une liste vide révoque ces
attributions directes ; elle ne retire pas un droit hérité d'un autre périmètre.
Les autres objets, groupes, Spaces et attributions globales restent intacts.

Les rôles doivent exister, être locaux (`type=1`) ou mixtes (`type=2`) et
appartenir au même Space que la ressource et le groupe. Les groupes ordinaires
et personnels sont acceptés, sauf les groupes implicites 1 et 2 dont les liens
restent fixes. Les doublons et IDs invalides sont refusés avant remplacement.
Le helper de persistance ne remplace pas l'autorisation métier du WF.

Les permissions par instance sont relues à chaque contrôle : une révocation
est visible immédiatement. Ces écritures ne changent pas les appartenances aux
groupes ni les attributions globales mises en cache. Le raccordement à TyCatalog,
avec ses permissions, son type d'objet, ses WF et son interface, appartient au
[lot 3 du chantier](IA/CurrentWorks/TYCATALOG.md) ; aucune route générique
d'attribution sur un objet arbitraire n'est exposée.
