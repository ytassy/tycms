# Lot 03 — HTTP, routage, CSRF et protections navigateur

## Conclusion et périmètre

**Analyse locale terminée ; SEC-008, SEC-011 et SEC-012 corrigées avec
autorisation puis vérifiées localement. Aucune dépendance modifiée.**
Restent ouverts la déconnexion par GET (SEC-009, faible), le contexte proxy
falsifiable dans PHP (SEC-010, exposition réelle non attestée) et le renforcement
d'émission des en-têtes FileResponse (DOC-003). Aucune compromission, prise de compte ou
lecture inter-Space indue n'est démontrée par ces essais.

Révision de l'analyse initiale : `1aff26d92a78bccc5d66b067f13dd7614f58e6bc`, arbre propre au départ.
L'hébergement mutualisé est la cible indiquée par Yann. Il considère les
en-têtes proxy normalement protégés ; aucune configuration ne l'atteste dans
ce lot. C'est une hypothèse de déploiement, pas une preuve ni un risque accepté.

Les [preuves](../Evidence/LOT-03/README.md) décrivent les fichiers et niveaux
examinés : métadonnées de tous les contrôleurs annotés, lecture des mécanismes
transverses et suivi ciblé des exceptions. Les ACL de chaque action métier,
les flux OAuth complets, les moteurs autonomes et l'hébergement effectif ne
sont pas déclarés audités en profondeur ici. Ils restent aux lots responsables.
Les points ouverts du lot 02, notamment SEC-006 et le packaging Google,
sont conservés sans nouvelle décision ni nouvel audit de dépendances.

## Correctifs autorisés et état courant

Base : `50cdf55d5ead8891835996b9824f5f649e7b5cf3`, arbre propre au départ.
Changements non commités, empreintes et commandes dans les
[preuves de correction](../Evidence/LOT-03/Fixes/README.md).

- SEC-011 : redirections internes validées par défaut dans le framework ;
  références réseau/antislashs/contrôles refusés. Le renvoi après erreur revient
  à `/` si l'URI n'est pas interne. Le départ SSO utilise `external()` avec
  contrôle de format, son WF restant responsable du fournisseur autorisé.
- SEC-012 : forme contrôlée avant regex/JSON, refus client 400 avant contrôleur
  et transaction. Le CSRF API garde sa priorité. JSON sérialisés, valeurs
  scalaires légitimes et paramètres facultatifs restent couverts.
- SEC-008 : jeton de session ajouté au formulaire historique et vérifié avant
  le helper de Space. Aucun changement de choix/destination/ACL métier.
- **167 tests, 515 assertions**, réussis : Routing, Request, Response, Security,
  SpaceSelection, Sso et MyAccount. Refus et réussites exercés sur sessions
  mémoire/doublures SQL, sans démarrage réel d'Application ni accès réseau.
- Guides Architecture/Contrôleurs/Journaux et carte du projet actualisés ;
  fiches déplacées dans Resolved. L'inventaire HTTP garde 315 déclarations,
  avec les lignes courantes et le contrôle CSRF de Space mis à jour.

Les sondes initiales sont figées sur leur ancienne révision et attendent parfois
un défaut : ne pas les rejouer comme tests des correctifs. Ni Apache/proxy,
ni navigateur, ni SSO réel vérifiés. Les ACL de Space restent au lot 04.
L'intégration Google Sheets ajoutée depuis l'analyse initiale n'est pas auditée
par ces corrections ; elle reste à examiner aux lots 11/12/13.

## Inventaire HTTP actualisé

[ROUTES.csv](../ROUTES.csv) contient **315 déclarations méthode/verbe** dans
36 classes annotées. L'instantané de 308 lignes du lot 01 est conservé dans
[routes-before.csv](../Evidence/LOT-03/routes-before.csv) ; les changements
sont détaillés dans [routes-delta.json](../Evidence/LOT-03/routes-delta.json).
L'extraction utilise la réflexion sur les métadonnées et arguments
sans démarrer Application ni instancier contrôleurs ou attributs.

| Ensemble | Déclarations courantes | Portée |
| --- | --- | --- |
| Core | 141 | Socle obligatoire, dont GET/POST `/cron`. |
| MainTemplate | 2 | CSS public et POST d'essai d'e-mail. |
| Automation | 21 | Extension facultative, activée selon Yann. |
| Matrix | 148 | Déclarations dormantes, contrôleur non enregistré. |
| Test | 3 | Développement uniquement. |
| Total | 315 | Ne comprend pas les routes de menus issues de SQL. |

Hors Matrix/Test : **164 candidates, 150 API, 14 non API, 57 GET et 107 POST**.
Les noms candidats sont uniques. **101 POST** relèvent du CSRF automatique,
**22 routes** portent NoTransaction et **53** sont exemptées du WAF.
Ces compteurs ne prouvent ni l'exposition réelle ni l'autorisation métier.

Huit ajouts et un retrait expliquent la différence : suppression de
POST `/automation/cron`, ajout de GET/POST `/cron`, plus six routes de
maintenance/relance Automation (un GET et cinq POST). L'actualisation ne
s'est donc pas limitée au déplacement du cron. Les paramètres, verbes,
attributs et emplacements de toutes les déclarations sont recoupés ; la liste
réelle des menus SQL n'est pas instanciée.

## Contrôles transverses et résultats

| Sujet | Contrôle réellement examiné | Résultat et limites |
| --- | --- | --- |
| Méthodes et paramètres | Request, WebRequest, Router, attributs, déclarations | Comparaison exacte des verbes ; paramètres de route distincts, priorité route puis corps puis query. Formes vérifiées avant conversion ; erreurs de paramètres 400 après correction SEC-012. |
| Corps et doublons | Décodage JSON/formulaire et superglobales PHP | JSON invalide ramené à un corps vide ; paramètres requis contrôlés ensuite. Les doublons réseau déjà fusionnés par PHP ne sont pas reconstitués ; pas d'attaque HTTP de désynchronisation démontrée. |
| Host/proxy | WebRequest, sélection de Space, origines et consommateurs OAuth/e-mail/cookies | En-têtes transférés utilisés sans contrôle du pair dans PHP : SEC-010. Les liens d'e-mail utilisent un hôte configuré, les URI OAuth une liste ; aucune fuite arbitraire démontrée. |
| CSRF API | Runner et Session ; client axios | Jeton aléatoire lié à la session, comparaison constante, invalidation au changement d'utilisateur ; refus 403 avant contrôleur pour API non exemptée. Axios ajoute le jeton aux POST de même origine seulement. |
| Non API | Choix de Space, récupération, départ SSO | Récupération et départ SSO vérifient un jeton de formulaire. Le choix historique de Space vérifie maintenant le jeton avant helper/mutation : SEC-008 résolue localement. |
| GET à effet | Logout dynamique, callbacks, cron, webhook ; classification des getters | Logout sans intention : SEC-009. Les callbacks ont state stocké/consommé et contrôles de session lus ; leur protocole complet reste au lot 07. Les getters métier ne sont pas certifiés sans effet interne par leur seul nom. |
| Cron et webhook exemptés | Contrôleurs et authentification spécifique avant hooks/émission | Cron vérifie Bearer/query/corps/Basic et refuse les sources applicatives ambiguës ; tests négatifs et positifs existants réussis. Webhook relit trigger/scénario, contrôle méthode/taille puis secret/HMAC avant émission ; rejeu, concurrence et principal restent au lot 12. |
| Redirections | RedirectResponse, runner, callbacks et chemins de langue | Renvoi borné à un chemin interne ; URI ambiguë remplacée par `/` : SEC-011 résolue localement. Les retours OAuth sont bornés indépendamment ; pas de contournement déduit de la seule classe RedirectResponse. |
| CSP et framing | Security/ResponseHeaders, Plugin et tests de politique | Scripts production sans unsafe-inline/unsafe-eval, nonce par réponse, object-src none, base/form-action/frame-ancestors bornés ; X-Frame-Options SAMEORIGIN et nosniff. Styles inline conservés par contrat, distincts d'une preuve XSS. |
| CORS et cache | Réponses HTML/JSON, hooks et entrées autonomes | HTML/JSON normaux private/no-store ; pas d'en-tête CORS permissif repéré dans ce périmètre. Les ajouts Apache/proxy ne sont pas attestés. |
| WAF | Déclenchement après résolution, exemptions, scanner borné et tests | Défense complémentaire configurable, pas un remplacement des ACL/CSRF. Le corps est déjà lu et les paramètres déjà résolus avant le WAF ; il ne couvre pas SEC-012 ni une borne globale d'allocation. |
| Erreurs et statiques | ErrorPages, bootstrap, FileResponse, .htaccess | Erreurs génériques avec détails conditionnés à debug, contenu échappé. Les erreurs directes quittent le circuit Response ; les headers FileResponse sont ignorés à l'émission (DOC-003). Statiques hors plugin : nosniff et CSP SVG conditionnels à Apache. |

Les méthodes HTTP inconnues deviennent GET dans WebRequest ; aucune route
supplémentaire ni élévation de droits n'a été reproduite par ce comportement.
Rejeter explicitement les verbes non reconnus est un renforcement à examiner
avec la normalisation des requêtes. La limite des corps lus et les différences
entre Content-Length/Transfer-Encoding doivent être contrôlées au serveur,
sans test de charge ni trafic ambigu vers l'hébergement dans ce lot.

## Constats, décisions et corrections

| Fiche | Qualification initiale | État courant |
| --- | --- | --- |
| [SEC-010](../Findings/UnderReview/SEC-010.md) | Modérée potentielle ; protection du proxy inconnue | Expliciter les pairs/proxies de confiance et le défaut sans proxy ; préserver le mutualisé et le multi-hôtes. |
| [SEC-011](../Findings/Resolved/SEC-011.md) | Modérée potentielle ; Response PHP hors site reproduite | Corrigée et vérifiée localement : contrat interne par défaut, départ SSO explicite, repli `/`. Transport serveur non éprouvé. |
| [SEC-008](../Findings/Resolved/SEC-008.md) | Faible ; mutation de contexte démontrée | Corrigée et vérifiée localement : jeton avant helper. Autorisation métier des Spaces au lot 04. |
| [SEC-009](../Findings/Confirmed/SEC-009.md) | Faible ; déconnexion forcée sur route GET de menu | Choisir entre confirmation GET suivie d'un POST protégé et POST depuis les menus. |
| [SEC-012](../Findings/Resolved/SEC-012.md) | Faible ; exception sur tableau en entrée scalaire | Corrigée et vérifiée localement : formes refusées avant conversion ; erreur 400, JSON sérialisés préservés. |

Les trois corrections simples ont été explicitement autorisées par Yann après
l'analyse. Les liens logout, la source d'autorité du Host et DOC-003 restent
à décider séparément. La normalisation globale des requêtes n'est pas modifiée.
Aucun risque n'est accepté implicitement par le choix du mutualisé.

## Écarts documentaires et surfaces autonomes

**DOC-003 — Headers des téléchargements.** L'architecture décrit les en-têtes
sur chaque Response. Le plugin les ajoute à l'objet FileResponse, mais
`ApplicationRouteRunner::sendResponse()` utilise une branche qui émet seulement
ses propres Content-Type/Disposition/Length. Les autres `httpHeaders`, dont
ceux de Security, ne sont pas envoyés par cette branche. L'usage actuel repéré
est la référence Markdown d'Automation, téléchargée en pièce jointe et protégée
par le WF ; aucun contenu secret, XSS ou fuite de cache n'est prouvé ici.
Harmoniser ce contrat d'émission constitue un renforcement à décider ; il ne
justifie pas de modifier le guide pour présenter la protection comme acquise.

Le bootstrap public peut émettre un 500 texte sans le plugin ; ErrorPages
écrit directement son HTML échappé et termine le script. Le contrat de
protection des erreurs doit donc être distingué de celui des Response normales.
`.htaccess` désactive l'indexation, filtre les accès PHP hors index/updater,
préserve Authorization et configure nosniff/SVG si les modules sont actifs.
DocumentRoot, AllowOverride, modules, symlinks et MIME réels restent au lot 16.

Updater et serveur de distribution ont leur propre politique d'en-têtes,
cache et authentification ; leur frontière HTTP a été repérée, sans dérouler
leurs opérations. Les essais complets de l'updater restent au lot 15 et ceux
de publication au lot 14. L'exception unsafe-eval de l'updater n'est pas
confondue avec la CSP de l'application.

## Vérifications initiales et couverture restante

- **111 tests existants, 327 assertions**, réussis : Routing, Request, Security,
  CronDispatcher et CronTick. Bootstrap de test, aucune initialisation réelle
  d'Application ni connexion SQL ; PHP 8.5.4, PHPUnit 13.3.6.
- **6 sondes, 24 assertions**, réussies pour caractériser les comportements
  de la révision initiale : proxy/Space, redirection normale et ambiguë, Space non API, logout
  dynamique, tableau en entrée INT, JSON malformé. Certaines assertions
  reproduisent volontairement un défaut ; elles ne valident pas sa correction.
- Relecture des déclarations par extraction indépendante, comparaison au lot
  01, unicité des noms candidats et mise à jour des emplacements.
- Contrôles documentaires : JSON/CSV, liens locaux, empreintes des sources
  examinées, intégrité des manifestes/verrous et diff initial limité à Docs/IA/Security. Les contrôles des correctifs sont
  conservés séparément dans Evidence/LOT-03/Fixes.

Les preuves partielles sont rattachées aux lignes du lot 03 dans ASVS.csv,
sans déclarer la conformité intégrale d'une exigence. Les tests unitaires ne
prouvent pas les politiques du navigateur ou du serveur. La correspondance
avec les comptes et Spaces réels demande les lots 04/06/07/12.

Avec Yann, une vérification simple pourra confirmer sur une instance locale
isolée le traitement des doubles slashs et les cookies/logout ; pas de banc
navigateur complexe engagé. Au lot 16, contrôler les en-têtes réellement reçus,
le nettoyage par le proxy, TLS/HSTS, cookies, cache et limites serveur.
Aucune requête de production ni aucun accès SQL n'a été effectué.
