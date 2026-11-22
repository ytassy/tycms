# Inscription dans un espace

## Parcours publics

Les pages de connexion et d’inscription sont des pages de l’éditeur contenant
respectivement `core.login` et `core.register`. Le panneau **Navigation** des
Spaces désigne leurs entrées de menu avec `spaces.menu_item_login_id` et
`spaces.menu_item_register_id`. Les liens sont résolus dans le Space réel et
la langue publique. Ces liens sont configurables librement : ils ne sont pas
limités aux pages Core, et aucun contrôle de cohérence éditoriale n’est imposé.
La référence doit appartenir au Space. Login propose le lien d’inscription si
les inscriptions sont ouvertes et qu’une URL est résoluble.
Les composants peuvent être placés sur d’autres pages du Space, chacun avec
ses options et ses CGU. Pour leurs actions, le serveur contrôle la publication
de la page source et la visibilité du composant et de ses ancêtres dans la
langue courante, avec les rôles de la session.

L’inscription exige aussi `spaces.params.registration_enabled=true`.
Le composant Register fonctionne sans entrée d’inscription configurée. Le sélecteur
**Changer de Space** exige en revanche une URL d’inscription configurée et résoluble
pour les non-membres, dans la langue du Space cible. Sans cette destination,
le Space est masqué ou désactivé selon les options du sélecteur et son choix
est refusé sans changer la session. Le sélecteur utilise cette URL après sélection ;
il ne crée ni compte, ni rôle, ni appartenance à un groupe. Les anciennes
routes `/login`, `/register`, `/register/join` et le type de menu Core `login`
sont retirés, sans redirection. Les anciennes entrées Core de type `login` ou
`register` sont supprimées avec toute leur branche. Les références de navigation
vers ces entrées ou leurs descendants sont remises à `null`. `/login-fb` est une connexion
de secours sans lien d’inscription, indépendante de la configuration des pages.
Elle utilise elle aussi l’API pour la connexion locale ; son SSO ne crée jamais
de compte et ne fait rejoindre aucun Space.

La migration `Mig_1_0_12.php` ajoute les deux colonnes nullables et leurs clés
étrangères, puis supprime les anciennes branches Core `login` et `register`.
Aucune page ni destination d’authentification n’est créée ou configurée.
Pour ouvrir les inscriptions, créer la page et son entrée
de menu, puis la sélectionner dans le Space.

## Formulaire, API et CGU

Le composant Register propose le formulaire local, le SSO disponible et une
option d’acceptation des CGU avec texte riche multilingue. Lorsqu’elle est
activée, l’acceptation est obligatoire côté serveur pour la création locale,
l’adhésion d’un compte connecté et le départ en SSO. La même case couvre les
deux moyens. Les textes riches sont purifiés à l’enregistrement et au rendu.
L’acceptation est contrôlée pour l’opération ; aucun historique de consentements
n’est stocké en base.

Un visiteur crée un compte avec prénom, nom, e-mail et mot de passe confirmé
de 12 à 72 octets UTF-8, sans caractère nul (borne bcrypt commune aux changements
de mot de passe et à la récupération). L’e-mail est unique entre les Spaces : un compte
existant doit d’abord se connecter, puis revenir pour rejoindre le Space.
Un compte déjà titulaire de `MEMBER`, y compris par héritage, n’est pas inscrit
une seconde fois et ne reçoit pas à nouveau les rôles ni les groupes.
Le formulaire et l’adhésion sont indisponibles si l’option formulaire est coupée.

Les appels du store `authentication` passent par le client API commun :

| API POST | Paramètres |
| --- | --- |
| `/auth/api/login` | `token`, `email`, `password` |
| `/auth/api/register` | `token`, `data` (objet JSON : `first_name`, `last_name`, `email`, `password`, `password_confirmation`), `acceptedTerms` (booléen) |
| `/auth/api/join` | `token`, `acceptedTerms` (booléen) |

Le CSRF de session est obligatoire dans `X-CSRF-Token`. Les réponses privées,
non mises en cache, fournissent `url` après succès. Le navigateur recharge le
document complet après connexion, car l’utilisateur, la session et le CSRF
changent. La destination reste l’espace utilisateur de la langue courante,
puis l’accueil. Les mots de passe ne sont pas stockés dans Pinia ni dans le
stockage du navigateur. En DEV strict (`Configuration.dev === true`), Login
et `/login-fb` préremplissent les champs avec `first_admin_email` et
`first_admin_password` de la configuration. Ces valeurs ne sont jamais émises
en production ni proposées dans Register.

`WFAuthentication` revalide le Space, le composant publié, son moyen activé et
les CGU. `AuthenticationHelper` fournit un jeton authentifié d’une heure,
lié à la session, au Space, à la page, au nœud, à la langue et à l’empreinte de
ses paramètres persistés. La clé aléatoire privée reste en session, séparée du
CSRF public : le secours local reste utilisable sans `Configuration.secret`.
Le navigateur ne peut modifier ni les options ni le texte accepté par ce jeton.
Une suppression, un masquage, une dépublication, un changement de page ou de
paramètres invalide une ancienne soumission. Les aperçus de l’éditeur sont
inertes et ne produisent aucun jeton d’action.

## Inscription SSO

Le départ est un POST de formulaire vers `/sso/start`, avec `providerId`,
`token`, `acceptedTerms` et `csrf_token`. Le callback reste `/sso/callback`.
L’état SSO conserve le jeton de configuration et l’acceptation ; le retour
recontrôle ces informations avant toute résolution d’identité et inscription.
Des CGU modifiées pendant le parcours imposent donc de recommencer.

Un départ depuis Login ou `/login-fb` autorise uniquement la connexion d’un
compte ayant déjà `MEMBER` sur le Space. Seul un départ depuis Register peut
créer un compte ou faire rejoindre le Space, si l’inscription et le SSO y sont
encore autorisés. Un lien SSO connu peut servir à rejoindre un autre Space dans
ce parcours. Lors d’un rattachement par e-mail, le fournisseur et le Space
doivent l’autoriser ; une nouvelle adhésion exige toujours un e-mail vérifié,
même si le mode de rattachement ordinaire vaut 2.

## Espace principal des nouveaux comptes

`spaces.params.registration_target_space_id` configure l’espace principal des
nouveaux comptes créés par inscription publique locale ou SSO. Depuis un espace
A ciblant B, `users.space_id` et l’espace propriétaire du groupe personnel sont
B. L’adhésion, les rôles, les groupes, la langue et l’e-mail de bienvenue restent
ceux de A. Le compte ne devient pas membre de B par ce seul rattachement ;
`registration_enabled` de A autorise l’inscription, même si B est fermé.

Une valeur absente, `null`, vide ou `0` utilise A. L’interface et l’API stockent
`null` pour « Cet espace » ou un ID entier positif. La cible est directe : le
réglage de B n’est pas suivi. Une cible inexistante ou de format invalide
interrompt l’inscription avant la création du compte, sans repli implicite.
Les inscriptions et adhésions d’un compte existant conservent son espace
principal. La création administrative conserve l’espace choisi explicitement.

Le choix « Espace principal des nouveaux inscrits » se trouve dans « Connexion
et inscription » de l’éditeur de Space, avec `SPACE_ADMIN` sur A. Choisir une
nouvelle cible B exige aussi `SPACE_ADMIN` sur B. Le formulaire ne propose que
ces cibles et le serveur revalide le choix. Une cible déjà enregistrée peut
être conservée après perte des droits sur B ; son omission dans un appel API
la conserve, tandis que `null` l’efface. Sans `SPACE_ADMIN`, le champ est absent
et une soumission forgée est refusée. Un Space ciblé par un autre Space ne peut
pas être supprimé tant que cette référence subsiste.

`Mig_1_0_0_p2.php` initialise l’option à `null`, comme les nouveaux Spaces créés
par `WFSpace`. Les Spaces existants sans cette clé gardent leur comportement ;
aucune nouvelle migration ni modification des comptes existants n’est requise.

## Groupe personnel et rôles

`UserRegistrationHelper` crée un seul groupe personnel de `type=1`, avec
`parent_id=UserGroups::GROUP_USERS` (ID 2), puis une ligne `user_groups_map`.
Les inscriptions ultérieures réutilisent ce groupe et ne créent pas de second
`users`. `users.space_id` désigne l’**espace principal** du compte, fixé lors
de la première inscription selon la cible configurée, ou du choix explicite
en création administrative ; une adhésion
ultérieure ne le modifie pas. Son rôle administratif est décrit dans
`Docs/USERS.md`. Le rôle Membre
`Roles::ROLE_MEMBER` (ID 5) est attribué par défaut sur l'espace rejoint.
Dans **Connexion et inscription**, `registration_auto_member_role` permet à un
administrateur `SPACE_ADMIN` de désactiver cette attribution implicite. Le champ
est booléen ; une soumission sans ce droit est refusée. La migration
`Mig_1_0_13.php` ajoute la valeur JSON `true` aux paramètres des Spaces sans
écraser un choix déjà enregistré ; les nouveaux Spaces l’initialisent également
à `true`. L’inscription lit directement cette option, sans repli pour une clé
absente. Une installation ayant déjà exécuté cette version de migration doit
recevoir également cette initialisation SQL avant d’utiliser ce code : le
journal de migrations ne réexécute pas une version déjà appliquée.
L’omission du champ dans un appel de modification du Space conserve la valeur
enregistrée. Aucun rôle ni compte existant n’est modifié.

L’option s’applique à la création locale, au SSO, à l’adhésion d’un compte existant
et à la création administrative. Elle est lue sur le Space d’inscription ou,
pour la création administrative, sur le Space choisi explicitement. La cible
`registration_target_space_id` n’en change pas la portée. La désactivation ne
retire aucune attribution existante et n’empêche pas de choisir explicitement
le rôle Membre dans les listes de rôles.

Ce rôle reste le moyen standard d'accorder l'accès lors d'une inscription ;
il n'est pas le critère d'appartenance. Toute permission `MEMBER` effective
suffit, quel que soit le rôle qui la porte, en incluant les groupes parents et
les Spaces ancêtres. `AclHelper::hasGlobalPermission(Permissions::MEMBER, $space)`
le vérifie pour la session courante ;
`userHasGlobalPermission($userId, Permissions::MEMBER, $space)` couvre aussi un
compte avant l'ouverture de sa session. Les deux méthodes utilisent le même
chargement des groupes et le même calcul des permissions, avec un instantané
par compte limité à la requête ; voir
[Architecture](ARCHITECTURE.md#acl-et-règles-de-sécurité).
Les méthodes `hasGlobalRoleOnSpace()` et
`userHasGlobalRoleOnSpace()` restent disponibles pour tester un rôle exact,
mais ne définissent plus l'appartenance.

Les deux listes `registration_roles` et `user_roles` de l'espace d'inscription
ajoutent leurs rôles, avec ou sans attribution implicite du rôle 5. Chaque liste
associe `"this"` ou un ID d'espace cible à des IDs de rôles globaux ; les doublons
d'attribution sont éliminés. Par exemple :

```json
{
  "registration_roles": {"this": [5], "1": [5]},
  "user_roles": {"this": [5]}
}
```

Un rôle supplémentaire doit appartenir à l'espace cible ou à un ancêtre et
être de type global (`0` ou `2`). Les rôles système Invité (1) et Utilisateur
(3) sont réservés aux liens système : le formulaire ne les propose pas,
`WFSpace` refuse de les enregistrer et `UserRegistrationHelper::enroll()` les
rejette même si un ancien réglage les contient encore. Le rôle 5 doit rester
global, défini à la racine et conserver la permission `plg_core__MEMBER` ; son
attribution échoue si ce contrat est cassé. L'éditeur des rôles vérifie cette
permission côté serveur et refuse la suppression du rôle 5. La création
administrative utilise les mêmes listes de rôles et de groupes que
l’inscription, décrites aussi dans `Docs/USERS.md`.

Aucune inscription ne transmet ROOT : `enroll()` refuse un rôle qui porte cette
permission et tout groupe qui la donne sur le Space 1, directement ou par un
parent. Le refus vaut aussi pour une création administrative par ROOT ; la
transaction annule le compte et ses attributions. Les rôles système reçus
automatiquement ne peuvent pas devenir des rôles ROOT. Une configuration
ancienne incompatible doit être corrigée explicitement ; elle n’est pas ignorée
silencieusement. Voir [ROOT](ROLES.md#root-à-léchelle-de-linstallation).

L'éditeur d'espace présente ces deux listes avec `core.json`, seulement après
création de l'espace et seulement avec `SPACE_ADMIN` sur l'espace source. Il
propose uniquement les espaces cibles administrables par la même personne.
Ses clés `space_<id>` sont converties en IDs numériques avant stockage ; la
clé `this` reste inchangée. Le formulaire revalidé par le WF refuse une cible
ou un rôle non proposés. Les paramètres initiaux sont dans `Mig_1_0_0_p2.php` et les
valeurs par défaut des nouveaux espaces dans `WFSpace`.

Les listes `registration_groups` et `user_groups` ajoutent des groupes
ordinaires du Space d'inscription dans `user_groups_map`. Elles se cumulent
sans doublons lors d'une inscription locale, SSO ou d'une adhésion ultérieure.
L'éditeur du Space les propose uniquement après création, avec `SPACE_ADMIN`,
et le serveur refuse un groupe système, personnel ou d'un autre Space. Un
groupe référencé par l'une de ces listes ne peut pas être supprimé. Voir
`Docs/USER_GROUPS.md` pour le contrat de gestion des groupes.

Après les attributions, `TraitUserEnrollment`, partagé par les WF local,
administratif et SSO, invalide l’instantané ACL du compte et vérifie sa permission
`MEMBER` effective sur le Space rejoint. Le contrôle tient donc compte des rôles,
des groupes et de leurs parents, ainsi que des Spaces ancêtres. Aucun calcul ACL
spécifique à l’inscription n’est ajouté.

Sans `MEMBER`, une erreur métier explicite interrompt l’opération : la transaction
du parcours annule le compte éventuel, le groupe personnel et les attributions
ajoutées, avant toute connexion ou bienvenue. Le diagnostic commun
`ENROLLMENT_MEMBER_REQUIRED` (WARNING, canal `registration`) conserve le Space
concerné, sans ajouter l'ID d'un compte cible encore susceptible d'être annulé.
Pour un compte existant, ses attributions antérieures restent intactes.
Le `finally` de la règle commune
invalide aussi l’instantané calculé dans cette transaction, afin qu’un rollback
ne laisse pas en mémoire des droits annulés. Les WF autorisent toujours leur
parcours avant d’appeler cette règle privée ; le helper reste responsable des
écritures techniques.

La création d'un compte local envoie le modèle `registration_welcome` ; la
première création par SSO envoie `registration_sso_welcome`. Les connexions et
adhésions ultérieures ne renvoient pas de bienvenue. Un échec d'envoi conserve
le compte et place un avertissement dans les messages en attente. La
validation du compte par lien e-mail reste à développer.
