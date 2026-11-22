# Plan d'audit par lots

L'état courant des lots est dans [le suivi](SUIVI.md) ; le cadrage du lot 01 est
réalisé et son [rapport](LOT-01.md) est disponible. Les descriptions ci-dessous
fixent le périmètre de chaque lot, pas leurs résultats. Les priorités
expriment l'ordre d'examen conseillé, sans préjuger de la gravité des constats.
Les profils S, A et A+ sont définis dans [les réglages](MODELES.md).

Après le cadrage, traiter d'abord les accès et les comptes, puis les entrées
et sorties, les moteurs internes et la chaîne de distribution. Avancer un lot
si le cadrage révèle une exposition plus urgente. Découper un lot trop large
en sous-lots numérotés, avec leur couverture dans le suivi.

| Lot | Sujet | But principal | Profil |
| --- | --- | --- | --- |
| 01 | Cartographie et modèle de menace | Définir actifs, attaquants, routes, frontières et couverture attendue. | A |
| 02 | Dépendances et secrets exposés | Identifier les versions réellement embarquées et les secrets accidentellement livrés. | S, élevé |
| 03 | HTTP, routage, CSRF et protections navigateur | Vérifier les protections transverses et leurs exceptions. | A |
| 04 | ACL, ROOT et isolation des Spaces | Rechercher les lectures et écritures sans droit et l'escalade de privilèges. | A+ |
| 05 | Connexion, inscription, récupération et Mon compte | Examiner les prises de compte et les opérations sensibles. | A |
| 06 | Sessions | Vérifier création, renouvellement, expiration et révocation. | A |
| 07 | SSO et OAuth | Vérifier les identités, les retours externes et les jetons. | A+ |
| 08 | Entrées serveur, SQL et persistance | Rechercher injections, affectations excessives et défauts de transaction. | A |
| 09 | HTML, Vue et contenus éditoriaux | Rechercher les XSS et les sorties de l'isolation des aperçus. | A |
| 10 | Médias et système de fichiers | Rechercher traversées, fichiers actifs et accès indus. | A |
| 11 | Appels externes, IA et e-mails | Examiner SSRF, fuite de données, outils IA et abus de services. | A |
| 12 | Automatisation, webhooks et cron | Vérifier l'exécution déléguée, l'authentification des déclencheurs et les budgets. | A+ |
| 13 | Chiffrement, journaux et confidentialité | Examiner protection des secrets et divulgation dans les sorties et erreurs. | A |
| 14 | Build, distribution et extensions | Examiner la chaîne de confiance jusqu'au paquet installé. | A+ |
| 15 | Updater, migrations, sauvegarde et restauration | Vérifier les opérations autonomes privilégiées et les états interrompus. | A+ |
| 16 | Configuration et préparation du déploiement | Auditer les configurations versionnées et préparer la revue de l'hébergement avec Yann. | S, élevé ; A si ambiguïté |
| 17 | Revue croisée et bilan | Rechercher les attaques combinées et fermer les trous de couverture. | A+ |

## 01 — Cartographie et modèle de menace

- Partir de `src/public/index.php`, du bootstrap de release, de `Application`,
  des contrôleurs de toutes les extensions et des entrées autonomes.
- Inventorier routes publiques/admin, méthodes HTTP, authentification,
  CSRF/exemptions, WF, permissions, Space réel, effets et limites de taille.
  Inclure les routes de menu construites dynamiquement et les appels internes.
- Définir les profils : invité, membre, éditeur, administrateur de Space,
  ROOT, fournisseur externe, auteur de scénario et service d'automatisation.
- Clarifier les données qui doivent rester privées, notamment médias et HLS,
  et les extensions effectivement activées en production, à confirmer avec Yann.
- Produire les matrices routes/contrôles et exigences ASVS ; lister chaque
  dossier ou artefact restant à examiner, avec propriétaire de lot.

## 02 — Dépendances et secrets exposés

- Examiner `src/releases/vdev/composer.json` et son lockfile, ainsi que les
  manifestes/lockfiles de `src/ria`, `src/tools/mermaid` et du frontend LMS.
- Inventorier aussi le JavaScript/CSS embarqué sous `src/public`, l'updater,
  les bibliothèques copiées sans gestionnaire, les images Docker et outils SQL.
- Consulter les avis actuels avec les versions exactes. Distinguer dépendance
  de build et code livré ; vérifier l'applicabilité d'un avis avant classement.
- Rechercher les secrets dans les fichiers suivis, exemples, artefacts et
  historique Git local disponible, en ne rapportant que les emplacements et types.
  Un historique absent ou incomplet limite explicitement cette couverture.
- Utiliser les audits Composer/npm si disponibles, sans installation, scripts
  de paquet ni correction automatique ; conserver l'état des bases d'avis et
  signaler une vérification réseau impossible. Ne pas conclure à l'absence
  de vulnérabilité à partir du seul résultat de ces outils.

## 03 — HTTP, routage, CSRF et protections navigateur

- Lire `framework/Request`, `Routing`, `ApplicationRouteRunner.php`, les
  attributs de contrôleur et les clients API Vue.
- Vérifier normalisation, paramètres dupliqués, corps JSON/formulaires,
  Host/proxy, redirections, types de contenu, verbes et réponses d'erreur.
- Recenser tous les GET à effet de bord, routes non API et `withoutCsrf` ;
  démontrer leur protection explicite. Le CSRF du runner ne couvre pas tout.
- Examiner CSP, CORS, cache de réponses privées, framing et exemptions WAF,
  y compris erreurs précoces et fichiers servis directement par Apache.
- Tests de départ : `Routing/CsrfTest.php`, `RouterTest.php`,
  `Request/WebRequestTest.php` et `Plugins/Security` dans les tests unitaires.

## 04 — ACL, ROOT et isolation des Spaces

- Partir de `Core/Helper/Acl/AclHelper.php`, des WF, des permissions, modèles
  ORM, rôles/groupes et attributions ; examiner aussi pages, langues et menus.
- Relever pour chaque opération l'objet relu au serveur et son Space réel.
  Vérifier lectures, listes, recherches, aperçus, copie/déplacement, délégation
  et appels depuis l'automatisation ou une extension.
- Examiner héritage parent/enfant, droits d'instance, invalidation du cache
  ACL et protection de ROOT lors des modifications et retraits d'attributions.
- Matrice négative : même rôle/autre Space, même Space/autre objet,
  compte bloqué, droit retiré et changement d'identité dans la requête.
- Tests de départ : `AclHelperTest`, `ObjectAclDelegationTest`,
  `RootPermissionPolicyTest`, `RoleSecurityIntegrationTest` et
  `ObjectAclIntegrationTest`, sous `src/tests`.

## 05 — Connexion, inscription, récupération et Mon compte

- Lire les guides Authentication via [l'architecture](../../ARCHITECTURE.md),
  [Inscription](../../REGISTRATION.md), [Récupération](../../PASSWORD_RESET.md)
  et [Mon compte](../../MY_ACCOUNT.md), puis les contrôleurs, WF et helpers.
- Vérifier mots de passe, comptes bloqués, énumération, brute force, quotas,
  anti-abus des e-mails, unicité et concurrence, jetons expirés/consommés.
- Examiner modification d'e-mail, liaison/déliaison d'identité, dernier moyen
  de connexion et réauthentification. Présenter séparément les choix produit.
- Tests de départ : `LocalPasswordPolicyTest`, `MyAccountTest` et intégrations
  Authentication, Registration, PasswordReset, MyAccount et UserAdmin.

## 06 — Sessions

- Examiner `framework/Session`, les cookies, les gestionnaires PHP/base,
  le stockage, les champs sérialisés et le changement de Space.
- Vérifier fixation, régénération à la connexion, expiration, déconnexion,
  révocation après changement/récupération du mot de passe et blocage du compte.
- Tester plusieurs sessions d'un même compte et les requêtes concurrentes,
  sans présumer que la destruction courante révoque les autres navigateurs.
- Tests de départ : `SessionManagerTest`, `RunSessionTest`, `CsrfTest` et
  les intégrations de connexion/récupération sur base dédiée.

## 07 — SSO et OAuth

- Examiner `WFSso`, `WFOAuthIntegration`, les helpers SSO/OAuth et les callbacks.
- Vérifier `state`, nonce, PKCE selon le flux, issuer, audience, signatures,
  JWKS, dates, identifiant stable, e-mail vérifié et liaison d'un compte existant.
- Examiner URI de retour, rejeu, changement de session pendant le retour,
  politique du Space, chiffrement/renouvellement/révocation des jetons et SSRF.
- Tests de départ : `SsoTest`, `SsoProviderSettingsTest`, `SsoProviderFormTest`.
  Les retours réels OIDC seront exercés avec Yann, sans nouvelle intégration
  externe ni utilisation de secrets de production pour une preuve locale.

## 08 — Entrées serveur, SQL et persistance

- Lire `framework/DB`, l'ORM, `ObjectValidator`, `Forms` et les helpers métier.
- Vérifier paramètres SQL, identifiants/tri dynamiques, listes, pagination,
  affectation de champs non autorisés, JSON, désérialisation, regex et limites.
- Rechercher exécution de code, commandes système, imports, appels dynamiques
  et chemins de configuration ; vérifier leur origine et leur validation.
- Examiner verrous, unicité, doubles soumissions, rollback et effets externes
  avant/après commit ; rapprocher du lot 12 et de l'updater autonome.
- Choisir les tests selon les opérations trouvées ; intégrer MySQL uniquement
  lorsque les garanties de concurrence/persistance l'exigent.

## 09 — HTML, Vue et contenus éditoriaux

- Examiner `framework/Lib/HtmlSanitizer`, `Core/Helper/Page`, composants de page,
  shortcodes, Mermaid, SVG, SCSS, MainTemplate et rendu d'e-mails.
- Suivre sauvegarde, collage, propositions IA, brouillons et anciens formats,
  puis chaque rendu public/admin : échappement HTML/attribut/JSON/URL/CSS.
- Examiner `v-html`, Markdown, DOMPurify, `srcdoc`, sandbox, `postMessage`,
  sérialisation initiale et chargement dynamique d'assets/extensions.
- Tests de départ : `PageRawHtmlTest`, `PageDocumentTest`, `PageDiagramTest`,
  `PageMermaidTest`, `ShortCodePreviewTest`, `EmailTemplateContentTest` et
  les tests Node `pagePreviewDocument.test.js`, `pageAiTools.test.js`.
- Confier à Yann les essais navigateur précis que les tests ne représentent pas.

## 10 — Médias et système de fichiers

- Lire `WFMedia`, `MediaHelper`, `SecureVideoHelper`, `SecureVideoController`,
  gestion des polices et `src/public/.htaccess`.
- Vérifier chemins réels, liens symboliques, encodages de traversée, écrasement,
  MIME/extension, SVG/HTML actifs, fichiers polyglottes et exécution PHP côté web.
- Examiner quotas, édition de texte, renommage du dossier d'un Space,
  téléchargement de polices et serveurs tiers ; archives aux lots 14/15.
- Distinguer droit d'utiliser le gestionnaire et confidentialité de l'URL
  publique. Décider du besoin de médias privés avant toute nouvelle architecture.
- Tests de départ : `MediaHelperTest`, `SpaceMediasRootTest`, `SecureVideoTest`,
  `FontHelperTest` et `FontLibraryIntegrationTest`.

## 11 — Appels externes, IA et e-mails

- Inventorier tous les clients HTTP/SMTP : IA, OIDC, polices, notifications,
  catalogues, distribution et éventuels téléchargements de médias.
- Vérifier URL, DNS IPv4/IPv6, adresses privées/réservées, redirections,
  rebinding DNS, TLS, proxy, délais, taille/décompression des réponses et quotas.
- Examiner données envoyées aux fournisseurs, réponses non fiables, erreurs,
  confirmations des outils IA, ACL au moment de l'action et injection de prompt.
- Vérifier destinataires/en-têtes d'e-mail, modèles, abus d'envoi et coût des
  appels IA ; rapprocher les notifications du lot 12.
- Tests de départ : `AiGatewayTest`, `AiWorkflowTest`, `MailHelperTest`,
  `EmailRendererTest` et tests Node des outils IA.

## 12 — Automatisation, webhooks et cron

- Inclure tout `core_extensions/Automation`, les actions Core, les boutons de
  page et les appels `emit`/`triggerNow`. Ne pas se limiter aux éléments des guides.
- Vérifier validation/interpréteur, capacités accessibles, principal auteur/
  service/utilisateur, permissions actuelles et publication des versions.
- Examiner jetons de bouton, webhook et cron, signature, expiration/rejeu,
  URL à secret, exemptions CSRF/WAF et authentification avant toute action.
- Examiner déduplication, transactions/outbox, boucles, quotas concurrents,
  budgets CPU/mémoire, notifications et nettoyage des données d'exécution.
- Tests de départ : suites unitaires `Core/Automation`, `AutomationPluginTest`,
  `RunSessionTest` et tests Node d'automatisation. Inventorier les scénarios
  d'intégration effectivement disponibles avant de conclure à leur couverture.

## 13 — Chiffrement, journaux et confidentialité

- Lire [Chiffrement](../../CRYPTO.md), [Journaux](../../LOGGING.md),
  `CryptHelper`, les modèles `Hidden`/ACL et les sérialisations API.
- Vérifier génération/stockage des secrets, usages distincts, authentification
  du chiffrement, aléas et comparaisons ; ne pas inventer de cryptographie.
- Examiner rotation/récupération des clés, permissions disque, backups et
  divulgations dans logs, erreurs, URL, HTML, JSON, caches et données de run.
- Lire aussi les sorties autonomes de l'updater et du serveur de distribution,
  non couvertes par le runner principal.
- Tests de départ : `CryptHelperTest`, `LogsTest` et `Framework/LoggingTest`.

## 14 — Build, distribution et extensions

- Examiner `build`, `build_extensions`, `update_server`, leurs protocoles,
  l'autoload et le chargement des extensions système/installées.
- Vérifier inclusion/exclusion des fichiers sensibles, bibliothèques réellement
  livrées, provenance, signature Ed25519, liaison manifeste/ZIP, versions,
  compatibilités, taille, chemins, liens et collisions des archives.
- Examiner accès publication/lecture, limites d'upload, rotation/révocation des
  clés, anti-rejeu et possibilité de retour à une version ancienne selon contrat.
- Une extension PHP s'exécute avec les droits de l'application : sa signature
  prouve sa provenance, pas l'innocuité de son code.
- Vérifications ciblées proposées : `php update_server/tests/run.php`,
  `php build_extensions/test.php` et lecture des contrôles de `build/test.php`.
  Les exécuter seulement dans l'environnement requis, avec clés/paquets factices.

## 15 — Updater, migrations, sauvegarde et restauration

- Examiner `Core/Helper/SystemUpdate`, `src/updater`, le bootstrap de release,
  les états sous `src/var` et la bibliothèque MariaDBBackup.
- Vérifier autorisation ROOT en préparation, portée/secret/révocation du lien
  autonome, CSRF/cookies, contrôle des étapes, verrous et double exécution.
- Examiner extraction/copie/configuration, maintenance, activation, opérations
  interrompues, backup privé, restauration, SQL privilégié et nettoyage.
- Vérifier le parcours depuis un état livré sur fixtures isolées et le risque
  de corruption/perte de données, en plus de la confidentialité et de l'intégrité.
- Tests de départ : unités `Updater`, `SystemUpdateTest`,
  `SystemUpdatePackageTest`, `SystemUpdateResetTest` et intégrations `Updater`.
  Aucune activation ni restauration réelle sur l'installation hébergée.

## 16 — Configuration et préparation du déploiement

- Examiner les valeurs par défaut/exemples, `docker/dev`, `docker/build`,
  Apache/PHP, Adminer/phpMyAdmin/Mailpit/Vite et la séparation DEV/production.
- Vérifier racine HTTP unique, répertoires privés, outils/debug interdits en
  production, erreurs affichées, cookies, contraintes de versions et permissions.
- Préparer une liste courte à vérifier avec Yann sur l'hébergement : TLS/HSTS,
  modules Apache/AllowOverride, droits disque/SQL, comptes SSH/admin, secrets,
  sauvegardes externes et essai de restauration, supervision et conservation.
- La première partie peut être conclue localement ; la seconde reste en attente
  tant que l'hébergement réel n'a pas été examiné avec Yann.

## 17 — Revue croisée et bilan

- Reprendre matrices des lots 01/04 et toutes les fiches ; confronter la
  révision initiale aux changements intervenus pendant l'audit.
- Réexaminer les parcours combinés : XSS vers ROOT, changement d'e-mail vers
  récupération, permission retirée vers automatisation, lecture de logs vers
  secret d'updater, SSRF vers service interne et paquet vers exécution PHP.
- Vérifier corrections, régressions, reports et risques acceptés, sans rouvrir
  des tests coûteux sans raison précise.
- Livrer couverture réelle, limites, priorités résiduelles et mesures de
  prévention récurrentes. Séparer bilan local et validation de l'hébergement.

## Instructions réutilisables pour lancer le travail

Pour commencer :

> Lance le lot 01 de Docs/IA/Security/PLAN.md. Analyse seulement, sans correction
> applicative. Complète la cartographie et les matrices de couverture, puis
> présente les choix à trancher et mets à jour le suivi.

Pour un autre sujet :

> Lance le lot NN de Docs/IA/Security/PLAN.md. Lis le suivi et les décisions
> existantes, analyse le code et ses appels, vérifie les hypothèses en local
> avec des contrôles proportionnés. Documente les preuves, les limites et les
> options de correction. Ne modifie pas le code applicatif avant ma décision.

Pour une correction décidée :

> Pour SEC-NNN, je retiens l'option X [avec ces précisions]. Réalise la
> correction, les vérifications utiles et les mises à jour documentaires,
> puis indique les risques et essais manuels restants.

Pour poursuivre après l'actualisation Composer du lot 02 :

> Lance le lot 03 de Docs/IA/Security/PLAN.md, en analyse seule. Lis le suivi,
> le rapport LOT-02 et CONTINUATION.md ; relève la révision actuelle. Actualise
> l'inventaire HTTP pour les évolutions du cron. Analyse le routage, Host/proxy,
> les protections CSRF et leurs exceptions, les GET à effet de bord, les routes
> non API et les protections navigateur. Suis les contrôles jusqu'à leurs effets
> avant de qualifier une faille. Utilise des vérifications locales proportionnées,
> sans accès de production ni test navigateur complexe. Complète le rapport,
> les preuves, les fiches et le suivi dans Docs/IA/Security. Présente les constats,
> les limites et les options ; ne modifie pas le code applicatif ni les
> dépendances avant ma décision. Conserve les points ouverts du lot 02.
