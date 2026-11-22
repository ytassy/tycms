# Mot de passe oublié

## Parcours public

Le lien de récupération des composants Login et de `/login-fb` mène à
`GET /forgot-password`. Son libellé est configurable dans le composant Login.
Le formulaire PHP transmet l'adresse e-mail à `POST /forgot-password` avec le
jeton CSRF de la session. La confirmation est identique pour une adresse
inconnue, un compte bloqué, un compte SSO non autorisé, une demande trop
rapprochée et un échec d'envoi. Le réglage `send_email` du compte ne bloque pas
ce message de récupération demandé explicitement.

La récupération est globale : aucune adhésion à l'espace de la demande n'est
exigée. Le SMTP, le modèle `plg_core__password_reset`, sa langue et son habillage
sont ceux de l'espace courant. L'URL est construite avec son `main_host`
configuré, jamais avec l'en-tête HTTP `Host`. Elle utilise HTTPS, sauf
`localhost:<port>` en mode développement, qui utilise HTTP.

Le lien mène à `GET /reset-password/{token}`. L'ouverture affiche le formulaire
sans consommer le jeton. Le parcours reste accessible depuis un autre navigateur
et lorsque le choix initial du Space est ambigu ; dans ce dernier cas, la
présentation utilise l'espace racine. Le jeton autorise le compte concerné,
indépendamment de cet espace de présentation.

`POST /reset-password/{token}` vérifie le CSRF, le jeton et sa date, l'état du
compte, la politique SSO, puis le mot de passe confirmé (12 à 72 octets sans caractère nul,
comme à l'inscription). Le changement réussi renvoie à la connexion sans
authentification automatique. Un lien invalide ou expiré propose une nouvelle
demande. Les pages envoient `Cache-Control: private, no-store`,
`Referrer-Policy: no-referrer` et `X-Robots-Tag: noindex, nofollow`.

## Configuration

Les deux valeurs sont dans la classe `Configuration`, en secondes :

| Propriété | Défaut | Effet |
| --- | --- | --- |
| `password_reset_request_delay` | `60` | Délai minimal entre demandes acceptées pour un même compte, partagé entre espaces et navigateurs. `0` désactive ce délai. |
| `password_reset_token_lifetime` | `1800` | Durée de validité du lien ; l'expiration est stricte, sans marge. Le minimum appliqué est 1 seconde. |

L'espace principal du compte (`users.space_id`) décide pour un compte
possédant au moins une ligne dans `sso_links` :
`spaces.params.sso_allow_reset_local_password` doit être `true` (ou `1`).
Une valeur absente vaut `false`. Aucun héritage d'espace ne s'applique.
L'option est modifiable avec `SPACE_ADMIN` dans « Connexion et inscription »
de l'éditeur de Space ; elle est absente du schéma sans ce droit. Un compte
lié au SSO peut ainsi définir un mot de passe local uniquement si son espace
principal l'autorise. La règle est relue à la validation du lien, même si
celui-ci a été émis avant la désactivation.

## Persistance, sécurité et sessions

`WFPasswordReset` porte les règles ; `PasswordResetHelper` porte les lectures
et écritures SQL. Les accès typés sont dans `TraitWF` et
`TraitCoreAdditionalHelpers`. Le contrôleur ne manipule pas la base.

Le jeton comprend 32 octets aléatoires encodés en hexadécimal. Seule son
empreinte SHA-256 est stockée dans `users.reset_password_token`. Les champs
existants suffisent ; aucune migration supplémentaire n'est ajoutée.
`reset_password_request_date` fixe le délai et l'expiration avec l'horloge SQL.
Chaque demande acceptée remplace l'empreinte précédente. Les demandes limitées
ne changent ni le jeton ni sa date.

La consommation verrouille le compte et effectue une écriture conditionnelle
qui change le hash du mot de passe, efface le jeton, renseigne
`reset_password_last_reset_date` et incrémente `reset_count`. La transaction
de la route inclut la révocation des sessions. Une soumission répétée ne peut
plus consommer le même jeton. Un changement de mot de passe par `UserHelper`,
un rattachement SSO qui remplace le mot de passe, un changement d'e-mail ou
un blocage administratif invalident également les jetons en circulation.
Les écritures de mot de passe local renseignent aussi
`users.params.local_password_enabled`, utilisé par [Mon compte](MY_ACCOUNT.md)
pour protéger le dernier moyen de connexion lors du retrait d'un lien SSO.

`SessionManager::deleteUserSessions(int $userId): bool` centralise la révocation.
Son accès typé `sessionManager` est fourni par `TraitFrameworkSharedExtensions`.
Avec `session_handler = 'database'`, toutes les lignes de sessions du compte
sont supprimées et la méthode renvoie `true`. Avec `php`, les sessions des
autres navigateurs restent actives et la méthode renvoie `false`. Dans les
deux modes, la session courante est détruite si elle appartient au compte.
Le contrôleur recrée alors une session invitée pour la confirmation.
Cette limite du mode PHP est indiquée dans les classes de configuration.

L'envoi reste synchrone dans la transaction existante, comme les autres mails
métier ; il n'y a pas de file d'envoi différé. Un échec SMTP efface le jeton
nouvellement généré, conserve la date du délai et garde la réponse générique.
Les erreurs d'envoi absorbées par le WF sont diagnostiquées dans le canal
`mail` : `PASSWORD_RESET_MAIL_FAILED` avec les causes internes, ou
`PASSWORD_RESET_MAIL_NOT_SENT` si le transport renvoie `false` (envoi désactivé
inclus). La réponse publique reste identique, sans adresse ni jeton dans les
journaux ; voir [Journaux](LOGGING.md).

Le délai limite les envois par compte ; il ne constitue pas une limitation
globale par adresse IP et la durée des réponses n'est pas uniformisée.

Les tests d'intégration utilisent la base dédiée avec un transport mail
enregistré en mémoire. Le rendu visuel, le SMTP réel et une charge réellement
concurrente restent à vérifier séparément.
