# Suivi courant de l'audit

## Couverture

La préparation et le [lot 01](LOT-01.md) sont réalisés sur le périmètre local.
Le [lot 02](Reports/LOT-02.md) est documenté avec ses corrections ciblées
vérifiées localement ; sa couverture reste partielle. Les ajouts Composer de Yann
ont été examinés à `b51758914201fd9ffc5cdcf3e7ebfa4602351e80`, puis le verrou
a été resynchronisé avec son autorisation, sans changement de versions.
Picomatch et les preuves d’artefacts/images restent ouverts.
Le [lot 03](Reports/LOT-03.md) est analysé localement ; SEC-008, SEC-011 et
SEC-012 ont ensuite été corrigées avec autorisation et vérifiées. Les autres
corrections et compléments d’hébergement restent à décider avec Yann ;
aucun lot suivant n’est lancé automatiquement.

| Lot | État de l'analyse | Correction / validation |
| --- | --- | --- |
| 01 — Modèle de menace et couverture | Analysée — cadrage local | Décisions confirmées ; contrôles détaillés et hébergement non vérifiés |
| 02 — Dépendances et secrets exposés | Partielle — inventaire et analyse locale réalisés ; compléments picomatch/images/artefacts | Symfony/Vue LMS corrigés ; ajouts Composer réexaminés (63 paquets, aucun avis), SEC-007 corrigée et validée ; SEC-006 ouverte, production non vérifiée |
| 03 — HTTP, routage, CSRF | Analysée localement ; correctifs ciblés vérifiés | SEC-008/011/012 résolues localement ; SEC-009 confirmée, SEC-010 en étude ; DOC-003 ouvert, hébergement non vérifié |
| 04 — ACL et Spaces | À lancer | Non évaluée |
| 05 — Comptes et authentification | À lancer | Non évaluée |
| 06 — Sessions | À lancer | Non évaluée |
| 07 — SSO et OAuth | À lancer | Non évaluée |
| 08 — Entrées serveur et persistance | À lancer | Non évaluée |
| 09 — Contenus et frontend | À lancer | Non évaluée |
| 10 — Médias et fichiers | À lancer | Non évaluée |
| 11 — Réseau, IA et e-mails | À lancer | Non évaluée |
| 12 — Automatisation | À lancer | Non évaluée |
| 13 — Chiffrement et confidentialité | À lancer | Non évaluée |
| 14 — Build et distribution | À lancer | Non évaluée |
| 15 — Updater et récupération | À lancer | Non évaluée |
| 16 — Configuration et déploiement | À lancer | Hébergement réel à examiner ensuite avec Yann |
| 17 — Revue croisée | À lancer | Dépend des autres lots |

États d'analyse : à lancer, en cours, partielle, analysée. Pour une analyse
partielle, préciser ce qui manque. Pour chaque lot lancé, ajouter la révision,
les fichiers/périmètres couverts, le rapport et les exigences ASVS associées.

## Registre des constats

Les avis de dépendances du lot 02 sont qualifiés séparément de leur
exploitabilité applicative : aucune exploitation de TyFramework n'est démontrée
par ces seuls avis. Les observations OBS-001 à OBS-011 et les écarts
DOC-001/DOC-002 restent dans la [cartographie](CARTOGRAPHIE.md), complétée
par DOC-003 sur les en-têtes FileResponse au lot 03.

Créer les fiches dans les catégories de [Findings](Findings/README.md), avec
un identifiant unique par cause principale et les liens vers les lots concernés.
`Resolved` signifie périmètre de correction décidé et vérifié localement,
pas production mise à jour ni audit complet du domaine. Les comportements
PHP et leur exposition au navigateur/serveur sont qualifiés séparément au lot 03.

| ID / fiche | Sujet et lots | Gravité | Confiance / preuve | Décision de Yann | État |
| --- | --- | --- | --- | --- | --- |
| [SEC-001](Findings/Resolved/SEC-001.md) | Confinement Symfony Filesystem — 02/10/14 | Renforcement projet | Version/primitif confirmés ; barrière SCSS présente | Mise à jour compatible autorisée | Vérifiée localement, déploiement inconnu |
| [SEC-002](Findings/Resolved/SEC-002.md) | URL srcset Symfony — 02/09 | Renforcement projet | Attributs exclus des profils lus ; sondes de refus | Mise à jour compatible autorisée | Vérifiée localement, déploiement inconnu |
| [SEC-003](Findings/Resolved/SEC-003.md) | Meta refresh Symfony — 02/09 | Renforcement projet | Élément exclu des profils lus ; sondes de refus | Mise à jour compatible autorisée | Vérifiée localement, déploiement inconnu |
| [SEC-004](Findings/Resolved/SEC-004.md) | Autorités URL malformées Symfony — 02/09 | Renforcement projet | Filtre média supplémentaire ; sonde de refus | Mise à jour compatible autorisée | Vérifiée localement, déploiement inconnu |
| [SEC-005](Findings/Resolved/SEC-005.md) | Vue SSR dans le verrou LMS — 02/09/14 | Renforcement projet ; élevée amont | Version confirmée, SSR non identifié, build client réussi | Alignement Vue LMS autorisé | Vérifiée localement, déploiement inconnu |
| [SEC-006](Findings/UnderReview/SEC-006.md) | ReDoS picomatch — 02/09/14/16 | À qualifier ; élevée amont | Version affectée confirmée ; entrée non fiable non établie | Traitement séparé sans fix forcé/rétrogradation majeure | À confirmer pour l'application, aucun risque accepté |
| [SEC-007](Findings/Resolved/SEC-007.md) | Synchronisation du verrou Composer et validation du build — 02/14 | Renforcement, aucun exploit démontré | Validation code 2 avant, code 0 après ; seul content-hash modifié | Resynchronisation explicitement autorisée | Corrigée et vérifiée localement ; nettoyage Google borné appliqué par le build |
| [SEC-008](Findings/Resolved/SEC-008.md) | Choix historique de Space sans CSRF — 03/04/06 | Faible | Refus sans/faux jeton avant helper et réussite légitime vérifiés | Jeton au formulaire et vérification avant helper autorisés | Corrigée et vérifiée localement ; ACL au lot 04 |
| [SEC-009](Findings/Confirmed/SEC-009.md) | Déconnexion par GET de menu — 03/06 | Faible | Session détruite sur route dynamique factice | Confirmation ou POST de menu à choisir | Confirmée localement |
| [SEC-010](Findings/UnderReview/SEC-010.md) | Confiance des en-têtes Host/proxy — 03/07/16 | Modérée potentielle | Contexte/Space influencés dans PHP ; serveur non attesté | Mutualisé cible, protection normalement attendue, non vérifiée | UnderReview, aucun risque accepté |
| [SEC-011](Findings/Resolved/SEC-011.md) | Redirection hors site sur URI ambiguë — 03/16 | Modérée potentielle | Contrat de réponse et repli testés ; chemin/query légitime préservés | Redirection interne par défaut, départ SSO explicite autorisés | Corrigée et vérifiée localement ; serveur non éprouvé |
| [SEC-012](Findings/Resolved/SEC-012.md) | Entrée tableau dans un paramètre scalaire — 03/08 | Faible | Refus tableaux/objets et API 400 sans contrôleur ni SQL | Validation de forme et erreur client 400 autorisées | Corrigée et vérifiée localement |

États d'une fiche : à confirmer, en attente de décision, correction autorisée,
corrigée à vérifier, vérifiée, risque accepté, reportée ou écartée avec motif.
Une observation écartée conserve sa justification pour éviter de la rouvrir
sans élément nouveau. Une régression réouvre la fiche concernée.

## Priorisation

- **Critique** : compromission étendue, exécution de code ou contrôle global
  avec peu de préconditions ; traiter immédiatement après confirmation.
- **Élevée** : prise de compte, accès inter-Space significatif, fuite de
  secrets ou écriture sensible non autorisée selon un scénario crédible.
- **Modérée** : impact ou préconditions plus limités, abus plausible à contenir.
- **Faible** : portée faible démontrée ; planifier selon le coût et l'exposition.
- **Renforcement** : amélioration préventive sans violation démontrée.

Justifier chaque classement par acteur, exposition, données/actions touchées
et contrôles existants. Documenter la confiance séparément : lecture statique
étayée, reproduction isolée ou hypothèse restant à vérifier. Un score CVSS
éventuel exige son vecteur et ses hypothèses ; ne pas fabriquer de précision.

## Modèle de fiche

```markdown
# SEC-NNN — Titre décrivant le risque

État : à confirmer / en attente de décision / ...
Lots : NN, NN
Révision analysée : SHA
Gravité proposée et justification : ...
Confiance et nature des preuves : ...
Références : CWE et exigence ASVS versionnée, si établies
Observation préparatoire liée : OBS-NNN, si applicable

## Comportement et impact

Attendu, observé, actif touché, acteur, préconditions, exposition et limites.
Préciser si le comportement est volontaire, documenté ou contraire au contrat.

## Preuves

Chemins et lignes à la révision analysée, chaîne entrée → contrôle → effet.
Reproduction locale minimale avec données factices, résultat attendu/observé.
Protections en aval examinées et raisons de leur efficacité/insuffisance.
Contre-exemple ou hypothèse qui pourrait invalider le constat.
Aucune valeur sensible ni procédure visant la production.

## Options proposées

Option A : correction, bénéfices, conséquences fonctionnelles et migrations.
Option B : alternative ou limitation, compromis et risque résiduel.
Recommandation et question précise à Yann si un choix est nécessaire.

## Décision courante

Choix explicite de Yann, périmètre autorisé, report ou risque accepté et motif.
Ne pas déduire une autorisation de correction de la seule demande d'analyse.

## Correction et vérification

Révision corrigée, comportement final, tests négatifs et parcours légitimes.
Commandes réellement exécutées, résultats et essais manuels restant à faire.
Validation de concurrence/persistance si nécessaire ; guides mis à jour.
Risques résiduels et conditions de réouverture.
```

## Décisions de périmètre en vigueur

- Lot 03 initialement en analyse seule, puis corrections simples SEC-008/011/012
  explicitement autorisées. Mutualisé cible selon Yann ; nettoyage des en-têtes
  proxy normalement attendu mais non attesté. SEC-009/010 et DOC-003 ouverts,
  aucune acceptation implicite des risques.
- Audit du dépôt complet avec essais locaux isolés ; hébergement réel ensuite
  avec Yann. Aucun test actif de production autorisé par cette préparation.
- Analyse sujet par sujet, puis choix de correction par Yann avant réalisation.
- Contrôles proportionnés ; aide de Yann pour essais visuels et vérifications
  externes, avant d'engager des tests navigateur complexes.
- Réglages modèle/effort proposés par tâche, sans changement de réglage imposé.
- Lecture des médias/HLS publique par URL ; les droits protègent leur gestion.
- Administrateurs de Space et auteurs de scénarios de confiance limitée,
  potentiellement malveillants ou compromis ; isolation et délégation à éprouver.
- Automatisation activée en production selon Yann ; version et configuration
  exactes non inspectées dans le cadre local.
- Lot 02 : mises à jour compatibles Symfony et alignement Vue LMS autorisés ;
  picomatch à traiter séparément sans correction forcée ni rétrogradation majeure.
- Actualisation Composer : analyse des ajouts demandée, puis resynchronisation du
  verrou explicitement autorisée, sans changement de versions ni du packaging.
- Ancienne valeur SQL de l'historique jamais utilisée en production selon Yann ;
  nouveau dépôt Git envisagé, sans rotation ni réécriture réalisées par l'audit.
- Classement des constats par état dans `Findings`, rapports dans `Reports`,
  preuves dans `Evidence` et nouveaux inventaires dans `Inventories`.

## État du lot 01

- Révision des sources : `fa21acf5c283d0501ade2dcfa6995dd51810eaa1` ; empreintes
  vérifiées dans [PERIMETRE.csv](PERIMETRE.csv). Changements limités au dossier
  documentaire de sécurité.
- Rapport : [LOT-01.md](LOT-01.md). Entrées : [ENTREES.md](ENTREES.md) et
  [routes initiales](Evidence/LOT-03/routes-before.csv). Menaces : [MENACES.md](MENACES.md).
- Couverture : [COUVERTURE.md](COUVERTURE.md) et [ASVS.csv](ASVS.csv).
- Instantané initial : 308 déclarations dont 157 candidates hors Matrix/Test ; routes
  dynamiques de menus et moteurs autonomes traités séparément.
- 1412 fichiers inventoriés ; 345 exigences ASVS référencées, toutes non vérifiées.
- Contrôles : cohérence de l'extraction et des enregistrements, unicité des
  noms actifs, intégrité des matrices, chemins/liens et diff documentaire.
  Aucun test applicatif ni accès de production.
- DOC-001 : résolu par les modifications déjà présentes au début du lot.
  DOC-002 : ancien chemin Formulaires dans la carte du projet, signalé.
- Limites : paramètres d'hébergement, sessions déployées, release/extensions
  exactes et menus SQL inconnus ; preuves attendues dans les lots responsables.

## État du lot 02

- Révision initiale : `df84bd5a9bf9e56766bb380471d7a101a69907d0` ;
  [rapport](Reports/LOT-02.md), [preuves](Evidence/LOT-02/README.md) et
  [inventaire initial de 503 entrées](Inventories/DEPENDANCES-LOT-02.csv).
- Campagne initiale : trois fichiers de dépendances changés, verrou Composer (deux Symfony vers
  8.1.11), manifeste/verrou LMS (famille Vue 3.5.43, csstype 3.2.4).
  Aucun autre paquet ajouté/supprimé/versionné différemment ; RIA/Mermaid inchangés.
- Résultats acquis : Composer sans avis après correction ; LMS limité à l'avis
  picomatch ; PHPUnit 30 tests/132 assertions, huit sondes par état et build LMS
  isolé réussis. Résultats conservés, pas de nouvelle campagne à la reprise.
- Reprise documentaire : preuves avant/après classées, correspondance des
  copies auditées/compilées vérifiée et verrou Composer final validé.
- ASVS : rattachement au rapport ; statuts non vérifiés, aucune conformité déduite.
- Compléments : SEC-006, images/outils Docker (socket inaccessible), transitives
  des bundles copiés, ZIP final absent et hébergement non inspecté.
- Évolutions du cron/configuration hors lot, sans validation de leur sécurité
  ni réextraction au lot 02 ; inventaire HTTP actualisé au lot 03, métier au lot 12.
- État Composer courant : [preuves](Evidence/LOT-02/ComposerRefresh/README.md) et
  [inventaire](Inventories/COMPOSER-CURRENT-LOT-02.csv). Trois dépendances directes
  ajoutées par Yann (Flysystem, adaptateur S3, client Google), treize paquets
  supplémentaires ; 36 production/27 développement. Aucun paquet existant mis à
  jour ou supprimé depuis les corrections initiales ; six fichiers npm identiques.
- Audit réseau Composer actualisé : aucun avis ni paquet abandonné ; plateforme
  hôte vérifiée. Verrou initialement désynchronisé à cause du nouveau champ extra,
  puis corrigé après autorisation : seul content-hash change, validation code 0
  avec les avertissements préexistants licence et double larapack/dd.
- Pas de nouveaux tests applicatifs/build ni d'appel Google/AWS au moment de
  l'audit. Le build applique désormais lui-même la sélection Google sans
  activer les scripts Composer (voir SEC-007). Auditer les usages des SDK à leur
  ajout.

## État du lot 03

- Révision : `1aff26d92a78bccc5d66b067f13dd7614f58e6bc` ; arbre propre au départ.
- [Rapport](Reports/LOT-03.md), [preuves](Evidence/LOT-03/README.md),
  [inventaire courant](ROUTES.csv) et [delta](Evidence/LOT-03/routes-delta.json).
- 315 déclarations dans 36 classes, 164 candidates hors Matrix/Test : 150 API,
  14 non API, 57 GET/107 POST ; 101 POST couverts par CSRF automatique,
  22 NoTransaction, 53 exemptions WAF. Huit ajouts/un retrait, cron et maintenance.
- 111 tests existants/327 assertions et six sondes/24 assertions réussis ;
  les sondes décrivent parfois un défaut et ne prouvent pas une correction.
- Constats initiaux : trois faibles confirmés (SEC-008/009/012) et deux chaînes
  PHP à exposition conditionnelle (SEC-010/011). SEC-008/011/012 corrigées
  depuis la base `50cdf55d5ead8891835996b9824f5f649e7b5cf3`, sans commit créé ;
  [preuves de correction](Evidence/LOT-03/Fixes/README.md), **167 tests/515 assertions**
  réussis. SEC-009/010 et DOC-003 restent ouverts. Aucun accès inter-Space,
  détournement de compte, fuite de code OAuth ou déni de service global démontré.
- DOC-003 : les en-têtes ajoutés aux FileResponse ne sont pas émis par leur
  branche de sendResponse ; usage Markdown en pièce jointe, pas d’exploitation prouvée.
- ASVS : preuves partielles référencées, aucun statut de conformité validé.
- Limites : transport Apache/proxy, cookies/navigateur, menus SQL et paramètres
  d’hébergement non éprouvés. Protocole fédéré, ACL métier et automatisation
  profonde restent aux lots 04/06/07/12/16. Points du lot 02 conservés.
