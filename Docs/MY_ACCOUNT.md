# Mon compte

`core.my-account`, dans la catégorie **Authentification** de l'éditeur de pages,
affiche le compte de l'utilisateur connecté. Un visiteur déconnecté ne voit
aucun contenu. Le canevas et l'aperçu fidèle montrent des exemples fictifs,
sans lire les données personnelles de l'éditeur.
Ils utilisent le même conteneur neutre que le rendu public, sans carte ni
cadre ajouté au composant.

## Options et interface

L'onglet Général utilise un formulaire Vue organisé sous les titres
**Présentation**, **Informations personnelles**, **Connexions SSO** et
**Mes intégrations**. Les informations personnelles se règlent dans une grille
Afficher / Modifier, avec une lecture seule globale.

Le paramètre `layout` accepte `simple` (défaut historique, libellé au-dessus de
la valeur), `list` (libellé et valeur sur une même ligne, avec retour à la ligne
si nécessaire) ou `table` (deux colonnes sans ligne d’en-tête). Il concerne seulement
les informations personnelles ; les sections SSO et intégrations gardent leur
présentation. Le canevas et l'aperçu PHP reprennent aussi cette disposition.
Un mode inconnu est refusé à l'entrée du document.

L'affichage et l'édition du prénom, du nom, de l'e-mail, du fuseau horaire et du
mot de passe se règlent séparément.
Les connexions SSO et les intégrations disposent chacune d'une option
d'affichage et d'options indépendantes d'ajout et de suppression.
Un champ masqué n'est pas proposé à l'édition. La lecture seule retire toutes
les commandes de modification, y compris celles du SSO et des intégrations.

Le bouton **Modifier** ouvre la modale des informations personnelles.
Le mot de passe et la gestion des connexions utilisent des modales distinctes,
avec les composants communs de confirmation et les raccourcis `ctrl-s` / `esc`.
Les mots de passe restent dans le brouillon de leur modale et sont effacés
à sa fermeture ; ils ne vont ni dans Pinia ni dans le stockage du navigateur.

Ces options sont **des réglages d'interface, pas des permissions**. L'utilisateur
connecté peut gérer les champs personnels de son compte par l'API, indépendamment
des options d'une page. Les API ne reçoivent aucun jeton de configuration du
composant et ne prennent jamais un ID utilisateur comme autorisation.

## API et sécurité du compte

`MyAccountController` expose `/account/api`. `WFMyAccount` identifie le
propriétaire depuis la session, refuse un compte absent ou bloqué et applique
les règles métier ; `MyAccountHelper` porte les lectures et écritures SQL.
Le contrôleur ne modifie pas la base. Les routes POST bénéficient du CSRF et
des transactions du routeur. Toutes les réponses réussies du contrôleur,
y compris les POST, sont privées et `no-store` : elles peuvent contenir un profil ou une URL d’autorisation.
Le HTML public ne contient que les options et l'état connecté ; Vue charge
les données personnelles par l'API. Les réponses sont projetées explicitement,
sans hash de mot de passe, paramètres internes, claims SSO ni jetons OAuth.

| Méthode et chemin relatif | Contrat |
| --- | --- |
| `GET /` | Informations personnelles, état du mot de passe local, liens et fournisseurs SSO, intégrations du Space courant et de ses ancêtres. |
| `POST /save` | Objet `data` partiel limité à `first_name`, `last_name`, `email`, `timezone`. Les autres champs sont refusés. |
| `POST /password` | `current`, `password`, `confirmation` ; ancien mot de passe obligatoire, nouveau confirmé de 12 à 72 octets, sans caractère nul. |
| `POST /sso/start` | `providerId`, `returnPath` ; lance une liaison explicite OIDC. |
| `POST /sso/unlink` | `linkId` appartenant au compte courant, sous protection du dernier moyen de connexion. |
| `POST /integrations/start` | `providerId`, `returnPath` ; lance une autorisation OAuth individuelle. |
| `POST /integrations/disconnect` | `providerId` ; retire uniquement l'autorisation du compte courant. |

Les noms fournis doivent être non vides et bornés à 100 caractères ; une
modification partielle ne force pas à compléter les noms absents d'un ancien
profil SSO. L'e-mail est normalisé, validé et unique globalement. Il est
appliqué immédiatement, sans validation par e-mail, et invalide les liens de
récupération existants. Le fuseau doit figurer dans la liste PHP des identifiants.
L'espace principal, les rôles, groupes et états administratifs ne sont pas éditables.

Le changement de mot de passe vérifie l'ancien secret et invalide les liens de
récupération. La politique commune `Security/LocalPasswordPolicy` refuse les
nouveaux mots de passe dépassant 72 octets UTF-8 ou contenant un caractère nul,
pour éviter la troncature silencieuse de bcrypt et les erreurs de hachage.
L'inscription, l'administration et la récupération appliquent la même règle ;
la connexion conserve l'acceptation des anciens secrets jusqu'à 4096 octets.
Le changement de mot de passe révoque les sessions, puis invite à se reconnecter.
La limite
du gestionnaire de sessions PHP reste celle de [Mot de passe oublié](PASSWORD_RESET.md) :
les sessions d'autres navigateurs ne peuvent pas être révoquées dans ce mode.
Pour un compte lié au SSO, `sso_allow_reset_local_password` du **Space principal**
doit autoriser le mot de passe local. Un compte SSO ne connaissant pas de mot
de passe local utilise la récupération par e-mail si cette politique le permet.

## Liaison SSO et dernier moyen de connexion

La liaison réutilise les fournisseurs de connexion sélectionnés et disponibles
dans le Space courant. Le POST CSRF crée un flux distinct de la connexion,
contenant utilisateur, Space, destination locale, `state`, `nonce` et PKCE.
Le retour conserve `/sso/callback` et consomme l'état une seule fois, sous
dix minutes. Il refuse un changement d'utilisateur ou de Space, recontrôle
le fournisseur et vérifie l'identité OIDC avant l'écriture transactionnelle.
`WFMyAccount::linkIdentity()` recontrôle aussi sa disponibilité et son périmètre
après l'échange réseau, en rechargeant aussi la sélection des fournisseurs du
Space avant de créer le lien.
L'identité externe unique ne peut pas être transférée depuis un autre compte.
Le profil et le mot de passe existants sont conservés ; aucun rapprochement
par e-mail ni nouvelle connexion locale n'est effectué lors d'une liaison.

`users.params.local_password_enabled` distingue un mot de passe local connu
du secret aléatoire généré par le SSO, sans migration de schéma. Un hash absent ou inconnu ne compte jamais comme moyen local utilisable.
Les écritures de mot de passe et une connexion locale réussie le placent à `1`.
La connexion verrouille le compte dans la transaction avant de vérifier le
secret, de le rehacher si nécessaire et de confirmer ce marqueur : un changement
concurrent de mot de passe ne peut pas être écrasé ou attesté à tort. Une création
ou un rattachement automatique SSO le place à `0`. La liaison explicite conserve
son état, en considérant un compte sans lien préexistant comme local.
Sans marqueur, un compte historique ayant déjà des liens SSO est traité
prudemment comme SSO seul : une connexion locale réussie, un changement ou une
récupération de mot de passe permet de confirmer son moyen local.

La suppression verrouille le compte et refuse de retirer le dernier moyen
utilisable : il faut un mot de passe local connu ou un autre lien vers un
fournisseur de connexion disponible dans le Space courant. Un lien d'un autre
utilisateur est toujours refusé. Le nettoyage des jetons associés réutilise
le helper de gestion des liens SSO.

## Intégrations et limites

Le composant remplace l'écran `/integrations`, sa vue et le type de menu Core
`integrations`. Le provisionnement initial ne crée plus ces menus. Aucune
migration ni redirection des anciennes entrées n'est fournie ; une installation
de développement existante doit les retirer et composer sa page manuellement.
Le départ et la déconnexion passent par les API POST du compte ; seul le
retour technique `/integrations/oauth/callback` reste dans l'ancien contrôleur.
La destination de retour est un chemin local validé, conservé dans le flux
SSO/OAuth à usage unique, jamais une redirection externe fournie au callback.

La déconnexion OAuth supprime les jetons locaux sans révocation distante.
Leur stockage chiffré, les fournisseurs accessibles et le rafraîchissement
restent décrits dans [SSO et OAuth](SSO.md).
La réauthentification des actions sensibles et la validation des adresses
par e-mail (inscription et changement d'adresse) restent à définir. Le mot de
passe actuel est demandé uniquement pour changer le mot de passe.
