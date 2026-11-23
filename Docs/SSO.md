# SSO OIDC et intégrations OAuth 2.0

Les fournisseurs se configurent dans l'administration, sous « Fournisseurs SSO ». Les connexions OIDC et les autorisations OAuth 2.0 par utilisateur utilisent des parcours séparés.

## Édition des fournisseurs dans l'administration

La page « Fournisseurs SSO » liste les fournisseurs dont l'utilisateur peut administrer l'espace propriétaire avec `SSO_CRUD`. Un fournisseur peut être créé avec `oidc` ou `oauth2`. Son espace propriétaire et son protocole sont figés après création. Les lectures hydratent `SsoProvider` ; ses propriétés `config`, `mapping` et `secret_data` sont masquées lors d'une sérialisation du modèle. Les insertions et modifications restent des requêtes SQL dans `SsoProviderHelper`. La page de liste ne renvoie ni `config`, ni `mapping`, ni `secret_data` ; le formulaire d'un fournisseur autorisé renvoie les deux JSON et seulement un booléen `hasSecret` pour le secret.

Les onglets du formulaire couvrent l'identité, les capacités, les JSON et le secret. `config_json` et `mapping_json` sont des objets JSON édités dans `core.code`, limités chacun à 32 Ko. Le serveur les revalide et les stocke en JSON normalisé. Aucun de ces JSON ne doit contenir de secret ni de jeton ; les clés usuelles correspondantes sont refusées même si elles sont imbriquées. Le secret client se saisit séparément, est chiffré avec `CryptHelper` avant écriture et n'est jamais prérempli. En modification, l'action explicite « Conserver », « Remplacer » ou « Supprimer » évite toute ambiguïté sur une saisie vide.

Un fournisseur OAuth 2.0 ne peut pas activer `login_enabled` ; un fournisseur OIDC ne peut pas activer `integration_enabled`. Pour la connexion OIDC, Google et le pilote générique sont disponibles. Le fournisseur actif exige un Client ID, une URI `/sso/callback` HTTPS (HTTP réservé à localhost), un mapping `subject` vers `claims.*` et un secret client. Pour les intégrations OAuth 2.0, l'URI vise `/integrations/oauth/callback`. Le statut « Connexion configurée » indique que les champs locaux sont valides ; il ne vérifie pas l'enregistrement du client distant.

L'activation d'un fournisseur ne l'ajoute pas automatiquement à la connexion. Chaque espace choisit ses fournisseurs et l'ordre des boutons dans l'éditeur d'espace. Pour retirer un fournisseur sans rompre ses liens d'identité et références dans les espaces, le désactiver. L'interface ne propose pas de suppression définitive.

## Schéma

`Mig_1_0_0_p4.php` crée `sso_providers`, `sso_links` et `oauth_grants`, et élargit `users.password` à 255 caractères.

- `sso_providers.space_id` est l'espace propriétaire. Le fournisseur est identifié par son `id` numérique ; `protocol` accepte `oidc` ou `oauth2`. `secret_data` contient le secret client chiffré, jamais en clair.
- `sso_links` associe un `user_id`, un `provider_id` et un `external_subject` unique pour le fournisseur. `data` contient les claims d'identité OIDC chiffrés, sans jetons OAuth.
- `oauth_grants` associe un fournisseur OAuth 2.0 à chaque utilisateur. Le jeton d'accès, le jeton de rafraîchissement éventuel et l'expiration sont chiffrés dans `token_data` avec une clé dérivée distincte. Un utilisateur peut retirer son autorisation sans toucher aux liens SSO.
- Le mapping lit les chemins `claims.*` de l'ID token vérifié. `subject` donne l'identifiant externe. `email_verified` désigne le claim booléen de vérification de l'adresse : seul `true` permet l'inscription et le rattachement en mode 1. Si le claim est absent, non booléen ou non mappé, il est traité comme non vérifié. Le profil local ne change pas lors des connexions suivantes.

`sso_provider_ids`, `sso_auto_link_existing_account` et `registration_enabled` sont lus dans `spaces.params`. L'ordre de la liste définit l'ordre des boutons. Un fournisseur doit appartenir à l'espace courant ou à un ancêtre. `sso_auto_link_existing_account` vaut `0` (désactivé), `1` (rattachement si le claim mappé `email_verified` vaut `true`) ou `2` (rattachement sur correspondance d'adresse, même sans vérification pour un compte déjà membre). Une nouvelle adhésion par correspondance d'adresse exige toujours un e-mail vérifié. Une valeur absente ou inconnue désactive le rattachement ; les anciennes valeurs booléennes `false` et `true` restent interprétées comme `0` et `1`. L'inscription reste désactivée si `registration_enabled` est absent. Lors de la première inscription SSO, `registration_target_space_id` du Space de connexion détermine l’espace principal du compte et de son groupe personnel ; les rôles, groupes, adhésions et l’e-mail de bienvenue restent ceux du Space de connexion. Les comptes existants gardent leur espace principal. Voir `Docs/REGISTRATION.md` pour le repli sans cible, les rôles, les composants et les API d’inscription.

`plg_core__SSO_CRUD` autorise l'édition du fournisseur dans son espace propriétaire. Dans l'éditeur d'espace, `SPACE_ADMIN` contrôle la liste `sso_provider_ids` de l'espace administré et de ses enfants ; les fournisseurs de l'espace et de ses ancêtres y figurent, y compris ceux qui sont désactivés ou réservés aux intégrations, avec leur état. L'ordre des éléments sélectionnés est l'ordre des boutons de connexion effectifs. La liste se configure après la création de l'espace. La migration attribue `SSO_CRUD` au rôle root ; le SQL de provisionnement ci-dessous est une opération d'administration directe et ne passe pas par ces contrôles applicatifs.

`registration_enabled` est modifiable avec `SPACE_ADMIN` ou `SPACE_EDIT` ; `sso_auto_link_existing_account` exige `SPACE_EDIT`. Sans `SPACE_ADMIN`, `sso_provider_ids` est absent du formulaire de modification et toute soumission qui force ce champ est rejetée par la validation du serveur. Les permissions héritées d'un ancêtre s'appliquent aussi.

## Récupération d'un mot de passe local

L'option `spaces.params.sso_allow_reset_local_password`, désactivée par défaut,
autorise un compte lié au SSO à utiliser « Mot de passe oublié » pour définir
un mot de passe local. Seul son espace principal (`users.space_id`) fait
autorité, même si la demande est faite depuis un autre espace. L'option exige
`SPACE_ADMIN` dans l'éditeur de Space. Le lien est recontrôlé lors de son usage.
Voir `Docs/PASSWORD_RESET.md`.

## Configuration Google

1. Créer un client OAuth de type « Application Web » dans Google Cloud et autoriser exactement l'URI `https://votre-domaine/sso/callback` (ou `http://localhost/sso/callback` en développement). Le site doit servir ce chemin sur le même hôte que la page Login.
2. Définir `Configuration.secret` avec une valeur non vide, longue et aléatoire, puis la conserver durablement. Son format est libre. Une valeur facile à deviner ou son changement sans rechiffrement fragilise ou empêche le SSO. Les clés des fournisseurs, liens et autorisations OAuth sont dérivées séparément par `CryptHelper` ; voir `Docs/CRYPTO.md`.
3. Lancer `src/tools/sso_encrypt_secret.php`, saisir le secret client Google à l'invite et relever sa sortie hexadécimale. L'outil ne modifie pas la base. Éviter de mettre le secret brut dans l'historique du terminal ou dans un fichier SQL.

Le chiffrement SSO utilise maintenant AES-256-GCM. Les valeurs produites par
l’ancien `SsoCrypto` (libsodium) ne sont plus déchiffrables. Aucun traitement
automatique n’est prévu pour la base de développement : réenregistrer les
secrets fournisseurs, se reconnecter pour réécrire les liens SSO et renouveler
les autorisations OAuth concernées.

Sur `localhost` en HTTP avec `PhpSession`, configurer le cookie PHP sans l'attribut `Secure` pour que le navigateur renvoie la session au callback. En HTTPS, conserver `Secure` ; `DatabaseSession` l'applique selon le schéma de la requête.

## Provisionnement SQL

Pour configurer Google directement en SQL, remplacer les quatre valeurs de la première section. Le secret chiffré est la sortie de l'outil précédent.

```sql
SET @space_id = 2;
SET @google_client_id = 'VOTRE_CLIENT_ID.apps.googleusercontent.com';
SET @redirect_uri = 'https://votre-domaine/sso/callback';
SET @encrypted_client_secret_hex = 'SORTIE_HEX_DE_L_OUTIL';

INSERT INTO sso_providers (
    space_id, name, enabled, protocol, login_enabled,
    integration_enabled, auto_link_account, config, secret_data, mapping,
    login_button_text, register_button_text
) VALUES (
    @space_id, 'Google', 1, 'oidc', 1,
    0, 0,
    JSON_OBJECT('driver', 'google', 'client_id', @google_client_id, 'redirect_uri', @redirect_uri),
    UNHEX(@encrypted_client_secret_hex),
    JSON_OBJECT(
        'subject', 'claims.sub',
        'email', 'claims.email',
        'email_verified', 'claims.email_verified',
        'first_name', 'claims.given_name',
        'last_name', 'claims.family_name'
    ),
    'Continuer avec Google', 'S’inscrire avec Google'
);

SET @provider_id = LAST_INSERT_ID();
UPDATE spaces
SET params = JSON_SET(params, '$.sso_provider_ids', JSON_ARRAY(@provider_id))
WHERE id = @space_id;
```

L'exemple remplace la liste des fournisseurs de cet espace par un seul ID. Pour une liste existante, définir explicitement tous les IDs souhaités dans `JSON_ARRAY(...)`, dans l'ordre des boutons. Un espace enfant doit aussi inscrire l'ID du fournisseur parent dans sa propre liste ; rien n'est hérité automatiquement à l'affichage.

Si un fournisseur parent sert des espaces enfants sur plusieurs hôtes, enregistrer chaque URI de retour dans Google Cloud puis remplacer `config.redirect_uri` par `config.redirect_uris`, un tableau JSON d'URI telles que `https://hote-enfant/sso/callback`. Le serveur choisit seulement l'URI correspondant à l'hôte courant ; le callback reste ainsi dans la session du navigateur.

À ce stade, un compte déjà lié peut se connecter. Pour autoriser une première connexion sans lien, choisir **une** des politiques suivantes sur l'espace concerné :

```sql
-- Autoriser la création d'un utilisateur local depuis un email Google vérifié.
UPDATE spaces
SET params = JSON_SET(params, '$.registration_enabled', true)
WHERE id = @space_id;

-- Ou autoriser le rattachement d'un compte existant par email vérifié.
-- Le fournisseur ET l'espace doivent tous deux le permettre.
UPDATE spaces
SET params = JSON_SET(params, '$.sso_auto_link_existing_account', 1)
WHERE id = @space_id;
UPDATE sso_providers SET auto_link_account = 1 WHERE id = @provider_id;

-- Pour autoriser le rattachement sans preuve de vérification de l'email :
-- UPDATE spaces SET params = JSON_SET(params, '$.sso_auto_link_existing_account', 2) WHERE id = @space_id;
```

Lors du rattachement automatique, le mot de passe local est remplacé par le hash d'un secret aléatoire inconnu. Un nouvel inscrit reçoit le rôle Membre (ID 5) sur l'espace, ainsi que les rôles supplémentaires prévus par `registration_roles` et `user_roles`. Un utilisateur actif disposant déjà de `MEMBER` sur l'espace, notamment par héritage d'un Space parent ou via un autre rôle, peut s'y connecter sans inscription ni attribution supplémentaire, même si `registration_enabled` est désactivé. Sans cette permission, un compte déjà lié peut rejoindre l'espace si `registration_enabled` est actif ; il y reçoit aussi le rôle 5. Un utilisateur trouvé seulement par e-mail peut faire de même si l'e-mail est vérifié. `users.space_id` conserve l’espace principal fixé à la création du compte et aucun champ de profil local n'est réécrit lors des connexions suivantes.

Le mode 1 utilise le claim `email_verified` indiqué dans le mapping du fournisseur, pour Google comme pour tout autre fournisseur OIDC. Il n'applique pas de règle spécifique au domaine Gmail ou Workspace. Le mode 2 ignore ce claim pour un compte déjà membre, mais conserve la nécessité d'un e-mail valide. Dans les deux modes, `sso_providers.auto_link_account` doit aussi être activé. Un fournisseur qui permet à un utilisateur de déclarer librement une adresse non vérifiée peut rendre le mode 2 dangereux pour les comptes locaux portant cette adresse. L'inscription d'un nouveau compte ou l'adhésion à un autre espace exige toujours `email_verified=true` si le lien SSO n'existait pas déjà.

## Diagnostic en production

Les refus connus portent des codes de journalisation stables, dont
`SSO_MEMBER_REQUIRED` pour un compte sans permission MEMBER sur le Space de
connexion. Les causes internes d’un échec technique sont conservées sous
forme de classes et localisations assainies, sans arguments, email, jetons,
code OAuth, state ou message brut de bibliothèque. Les messages publics
restent génériques ; les [journaux applicatifs](LOGGING.md) sont consultables
uniquement par ROOT. Ces événements ne modifient aucune condition d’accès.

## Parcours et sécurité

Le flux utilise l'interface de session Tycms (`$this->session`) avec
`DatabaseSession` ou `PhpSession`, jamais un stockage direct dans `$_SESSION`.
Le premier gestionnaire n'alimente pas la session PHP native. Un client OAuth
doit permettre au WF de stocker/restaurer `state`, `nonce` et le vérificateur
PKCE via cette interface, sans démarrer sa propre session.

`league/oauth2-client` et `league/oauth2-google` portent le transport OAuth.
La présence d'un `id_token` dans leur réponse ne prouve pas son identité :
`firebase/php-jwt` vérifie la signature, puis Core contrôle les claims OIDC.
Un mapping `claims.*` reste un chemin déclaratif dans ces données vérifiées,
sans expression ni code exécutable. Le jeton distant ne devient jamais la
session locale de l'application.

Les composants Login et Register envoient un formulaire POST vers
`/sso/start` avec `providerId`, le jeton de configuration, l’acceptation des CGU
et le CSRF de session. L’ancien GET `/sso/start/{providerId}` est retiré.
Le `303` renvoyé vers l’IdP est couvert par la CSP : `form-action` autorise les
origines des fournisseurs de connexion du Space, car Chromium contrôle aussi les
redirections d’un POST de formulaire. Voir [Sécurité](PLUGIN_SECURITE.md).
Login et `/login-fb` ne créent ni compte ni adhésion ; Register le permet
uniquement si sa page source est publiée, les inscriptions ouvertes et
l’option SSO active. Le lien d’inscription dans la navigation du Space est facultatif.
Lorsque les CGU sont activées, la case est obligatoire pour Register. La configuration
et l’acceptation sont contrôlées au départ et au retour ; le jeton de formulaire est lié à la session et à ses paramètres publiés.
Le retour OIDC est `/sso/callback`. Le serveur conserve `state`, `nonce`, le vérificateur PKCE et l'espace pendant dix minutes dans la session Tycms, accepte plusieurs onglets et consomme chaque état une seule fois. Il recontrôle la liste de fournisseurs de l'espace avant d'échanger le code. La signature RS256 de l'ID token est vérifiée avec les clés publiques du fournisseur ; `iss`, `aud`, `azp` s'il existe, `exp`, `iat`, `nonce`, `sub` et, pour Google si configuré, `hosted_domain` sont vérifiés. L'identifiant de session locale est renouvelé après connexion.

Une marge de 60 secondes est appliquée à `iat` (et à `nbf` par la bibliothèque JWT) pour absorber un léger décalage d'horloge avec Google. L'expiration `exp` reste contrôlée sans marge. Si le décalage dépasse 60 secondes, synchroniser l'horloge du serveur PHP ; la marge ne remplace pas cette synchronisation.

## Fournisseurs génériques

Le pilote `generic` utilise `league/oauth2-client` avec le flux Authorization Code et PKCE S256. La configuration commune contient `client_id`, `authorization_endpoint`, `token_endpoint`, `redirect_uri` ou `redirect_uris`, et éventuellement `scopes` (tableau). Les endpoints doivent être HTTPS. Le secret client, s'il existe, reste dans le champ chiffré dédié. Les URI de retour doivent correspondre exactement à l'hôte de la requête ; HTTP n'est accepté que sur localhost.

Pour OIDC, ajouter `issuer` et `jwks_uri` HTTPS. Le serveur demande le scope `openid`, vérifie la signature RS256 de l'ID token avec les clés JWKS et contrôle `iss`, `aud`, `azp`, `exp`, `iat`, `nonce` et `sub`. Les mappings `claims.*` se rapportent à cet ID token vérifié. Un fournisseur générique peut aussi rattacher un compte existant si le fournisseur et l'espace l'autorisent, selon le mode `0`, `1` ou `2` de l'espace.

Pour OAuth 2.0, `mapping` vaut `{}`. La section « Mes intégrations » du
composant [Mon compte](MY_ACCOUNT.md) remplace la route `/integrations` et le
type de menu Core `integrations`, retirés sans migration. Un utilisateur connecté
y choisit un fournisseur actif de son espace ou d'un ancêtre, puis autorise son
propre compte externe depuis une API POST avec CSRF. Le retour `/integrations/oauth/callback` consomme un `state` à usage unique lié à sa session, son utilisateur et son espace. Les jetons restent côté serveur ; `WFOAuthIntegration::getAccessToken($providerId)` fournit un jeton valide aux helpers d'intégration et tente un rafraîchissement si nécessaire. Le bouton « Déconnecter » supprime la copie locale des jetons ; la révocation distante dépend du fournisseur et n'est pas effectuée. Le consentement OAuth ne sert jamais de connexion au site.

La liaison explicite d'une identité OIDC depuis Mon compte conserve le profil
et le mot de passe, refuse une identité déjà liée à un autre utilisateur et
revient à la page source par `/sso/callback`. Elle est distincte de la connexion
et du rattachement automatique ; ses protections sont décrites dans
[Mon compte](MY_ACCOUNT.md#liaison-sso-et-dernier-moyen-de-connexion).
SAML et les appels à une API externe précise restent hors de ce contrat générique.
