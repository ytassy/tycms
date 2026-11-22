# Groupes d’utilisateurs

## Types et périmètre

`user_groups.type=0` désigne les groupes ordinaires gérés dans l’administration.
La liste API `/administrator/api/user-groups` ne charge que ce type. Les groupes
personnels `type=1` sont créés automatiquement pour chaque compte et ne sont
jamais transmis à l’éditeur. Les groupes système 1 (Invité), 2 (Utilisateurs)
et 3 (Super Admin) gardent leurs champs descriptifs en lecture seule et ne
peuvent être supprimés.

Les groupes 1 et 2 portent des liens fixes vers les rôles 1 et 3. Ils ne sont
jamais proposés comme parent, comme groupe attribuable à un compte ou comme
groupe d’inscription, et leurs attributions de rôles ne sont pas modifiables,
même par ROOT. Seules les permissions des rôles Invité et Utilisateur restent
éditables, avec une mention dans l’éditeur de groupe. Le groupe 3 conserve ses
attributions de rôles modifiables avec `GROUP_ROLES_ASSIGN`.

Chaque groupe appartient à un Space. Un groupe ordinaire peut ne pas avoir de
parent ou avoir un parent ordinaire du même Space. Le WF interdit les cycles,
le changement d’espace après création et le choix d’un groupe système comme
parent. Le groupe personnel garde son parent système 2, hors de cet éditeur.

La lecture exige `USER_GROUPS_READ` ou `USER_GROUPS_CRUD` sur le Space du groupe.
La modification de la définition (nom, parent, description) exige
`USER_GROUPS_CRUD` ; celle des rôles du groupe exige `GROUP_ROLES_ASSIGN`,
séparément. Les rôles attribués ne sont transmis qu'avec `ROLE_READ` sur cet
espace. Le serveur relit le groupe avant une écriture et ne se fie pas aux
options affichées par Vue. Une suppression est refusée si le groupe a des
enfants, des utilisateurs, des rôles dans `acl_map` ou une référence dans
`registration_groups` ou `user_groups` d’un Space.

## Rôles du groupe

La liste API expose les rôles attribués à chaque groupe ordinaire ou système,
ainsi qu'un catalogue limité aux rôles globaux de type `0` ou `2` des Spaces
visibles, hors rôles implicites Invité et Utilisateur. Le catalogue n'est
proposé que pour les Spaces où l'appelant possède `GROUP_ROLES_ASSIGN`, puis
filtré par la délégation : un rôle dont il ne détient pas toutes les permissions
n'y figure pas. Le tableau affiche les attributions en badges Bootstrap. La
même modale permet de les ajouter ou retirer avec `DropdownList`. Pour le groupe
système 3, seuls ces rôles sont enregistrables ; pour les groupes 1 et 2, les
liens système restent affichés mais aucune attribution ne peut être ajoutée ou
retirée. Le nom, l'espace, le parent et la description restent figés pour tous
les groupes système.

Une attribution modifiable porte `acl_map.group_id`, le `space_id` du groupe,
un rôle dont `acl_roles.space_id` est exactement celui du groupe, et aucun
objet ACL. Le WF vérifie `GROUP_ROLES_ASSIGN` sur l'espace réel du groupe,
refuse les rôles locaux et les doublons, contrôle la délégation des seuls rôles
ajoutés ou retirés, puis remplace les attributions de ce périmètre dans la
transaction de la route. Une attribution historique visant le Space du groupe
avec un rôle d'un autre Space reste visible et inchangée ; les attributions
visant un autre Space ne sont pas gérées ici. Sur le groupe système 3, le rôle
Root (ID 2) ne peut pas être retiré.

Attribuer un rôle portant ROOT exige en plus un appelant ROOT et une attribution
globale sur le Space 1. Ajouter un compte à un groupe qui donne ROOT exige
également ROOT ; les parents de groupe sont pris en compte. Créer ou déplacer
un groupe sous un parent donnant ROOT applique la même protection. Modifier un
groupe qui donne déjà ROOT exige également ROOT, même pour retirer ce droit. Les helpers
relisent et verrouillent ces liens avant l’écriture. Voir [ROOT](ROLES.md#root-à-léchelle-de-linstallation).

La délégation contrôle aussi le parent : créer ou déplacer un groupe sous un
parent n'est possible que si l'appelant possède les permissions que ce parent
transmet. Un refus s'applique même lorsque le groupe n'a encore aucun rôle.

## Attribution lors de l’inscription

Les options JSON `registration_groups` et `user_groups` d’un Space sont deux
listes d’IDs de groupes ordinaires de ce même Space. L’éditeur du Space les
propose après la création du Space, à une personne qui possède `SPACE_ADMIN`.
Le formulaire utilise `DropdownList` pour rechercher et ajouter les groupes.
Les listes sont validées par le formulaire PHP, puis le WF revérifie le type et
l’espace de chaque groupe. Si aucun groupe ordinaire n’existe, une note invite
à en créer un avant de configurer l’attribution.

`UserRegistrationHelper::enroll()` cumule les deux listes lors d’une inscription
locale, SSO ou d’une adhésion ultérieure. Chaque relation directe est ajoutée
une seule fois dans `user_groups_map`. L’éditeur des utilisateurs permet
d'ajouter ou retirer leurs groupes directs ; voir `Docs/USERS.md`.
Aucune attribution automatique ne peut donner ROOT, directement ou par un
parent. Si une configuration le demande, l’inscription est refusée et ses
écritures annulées, y compris lorsqu’un ROOT crée le compte administrativement.

Ces affectations de comptes exigent `USER_GROUPS_ASSIGN` sur l'espace principal
du compte et sur celui du groupe. Ce droit est distinct de `USER_GROUPS_CRUD`,
qui conserve la définition du groupe, et de `GROUP_ROLES_ASSIGN` pour ses rôles.
L'ajout ou le retrait est soumis à la délégation : l'appelant doit posséder
toutes les permissions du groupe, parents et attributions historiques compris.
La migration Core `1.0.14` reprend `USER_GROUPS_ASSIGN` depuis
`USER_ROLES_CRUD` pour préserver les capacités existantes.
