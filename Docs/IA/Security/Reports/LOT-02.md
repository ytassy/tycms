# Lot 02 — Dépendances et secrets exposés

## Conclusion et état

**Analyse locale réalisée, couverture partielle.** Les corrections ciblées
autorisées sont présentes et leurs contrôles locaux ont réussi. Aucune
exploitation de TyFramework n'a été démontrée par ces seuls avis de dépendances.
Le lot ne se clôture pas sans réserve : `picomatch`, les images/outils Docker,
les transitives des bibliothèques copiées et le contenu d'un ZIP final restent
à examiner. Aucun de ces points n'est un risque implicitement accepté.
L'actualisation des ajouts Composer de Yann ne relève aucun avis sur les
63 paquets actuels ; elle a identifié un verrou désynchronisé bloquant la
validation du build. Ce point est **corrigé avec autorisation et vérifié**,
sans changement de versions ([SEC-007](../Findings/Resolved/SEC-007.md)).

Révision initiale : `df84bd5a9bf9e56766bb380471d7a101a69907d0`.
Les avis initiaux ont été consultés le `2026-11-18`. L'actualisation Composer
porte sur `b51758914201fd9ffc5cdcf3e7ebfa4602351e80`, avec consultation
le `2026-11-19` ; voir la section suivante. Les huit manifestes/verrous initiaux sont
identifiés [avant](../Evidence/LOT-02/sources.json) et
[après](../Evidence/LOT-02/sources-after.json) correction. Les modifications
de cron/configuration et leur documentation intervenues parallèlement sont
**hors du lot 02** et n'ont pas été validées par lui.
Les mises à jour ne sont pas une preuve de déploiement en production.

## État Composer courant et ajouts de Yann

La comparaison se fait avec le verrou corrigé de la campagne initiale,
retrouvé à la révision `eedd441fa98ac17afc12299053e7bcce57638490`, et non avec
les versions Symfony vulnérables d'avant correction. Le dépôt était propre
au début de l'actualisation. Les ajouts sont déjà présents dans la révision
de Yann ; cette actualisation met à jour le dossier documentaire puis corrige,
avec son autorisation explicite, la seule empreinte du verrou Composer.

| Dépendance directe ajoutée | Contrainte | Version verrouillée |
| --- | --- | --- |
| `league/flysystem` | `^3.36` | 3.36.0 |
| `league/flysystem-aws-s3-v3` | `^3.35` | 3.35.3 |
| `google/apiclient` | `^2.20` | 2.20.1 |

Ces trois dépendances ajoutent **treize paquets au total**, soit dix transitives :
AWS CRT/SDK, Google services/auth, Flysystem local/détection MIME, Monolog,
JMESPath, PSR Cache et PSR Log. Composer passe de 23 à **36 paquets de
production**, avec 27 paquets de développement inchangés, soit **63 paquets**.
Aucun paquet existant ne change de version et aucun n'est supprimé ; Symfony
Filesystem/HtmlSanitizer restent en 8.1.11. Les six fichiers npm sont identiques
aux empreintes de la campagne initiale corrigée : leurs audits ne sont pas relancés.

L'[inventaire Composer courant](../Inventories/COMPOSER-CURRENT-LOT-02.csv) et
les [preuves d'actualisation](../Evidence/LOT-02/ComposerRefresh/README.md)
complètent l'inventaire initial figé, sans le réécrire. Les treize ajouts
déclarent des sources HTTPS GitHub avec références de commits et des archives
GitHub ; aucun n'est un plugin Composer. Il s'agit d'une vérification des
métadonnées du verrou, pas d'une attestation des archives téléchargées.

Contrôles effectués sur copies des deux fichiers Composer, avec répertoire
Composer/cache isolés, sans installation ni exécution de scripts/plugins :

- `audit --locked --format=json` : **aucun avis ni paquet abandonné**, code 0,
  dépendances de développement incluses. Réponse réseau obtenue après relance
  hors du bac dont le DNS échouait. L'absence d'avis ne garantit pas la sûreté
  de l'intégration future des SDK.
- `validate --no-check-publish` : **code 2**, verrou non synchronisé après
  l'ajout de `extra.google/apiclient-services`. Les avertissements de licence
  absente et de double déclaration de `larapack/dd` persistent. Le build appelle
  cette validation et s'arrête sur un échec : **SEC-007 confirmée puis corrigée**
  après autorisation explicite de Yann.
- `check-platform-reqs --lock --format=json` : succès sous PHP 8.5.4 hôte,
  notamment pour les exigences transitives `ext-fileinfo` et `ext-simplexml`.
  Aucune conclusion sur Docker ou l'hébergement réel.
- Recherche des usages de `Google\\`, `Google_Client`, `League\\Flysystem`
  et `Aws\\` dans le code source applicatif hors vendor/artefacts ignorés :
  aucun appel identifié. Les interfaces externes, les permissions S3, les
  scopes OAuth et le stockage des secrets ne sont donc pas validés ici.

La correction autorisée a utilisé `update --lock --no-install` avec scripts et
plugins désactivés dans une copie isolée. La comparaison structurelle confirme
que seul `content-hash` change ; les 63 paquets restent strictement identiques.
Après application, `validate --no-check-publish` réussit avec le code 0 et les
deux avertissements préexistants. Voir les
[preuves de correction](../Evidence/LOT-02/ComposerRefresh/correction.json).

Le manifeste ajoute aussi `pre-autoload-dump` vers
`Google\Task\Composer::cleanup` et une liste de dix services Google. La classe
du hook est chargeable dans le vendor local ; le hook n'a pas été exécuté.
Le [mécanisme documenté par Google](https://github.com/googleapis/google-api-php-client#cleaning-up-unused-services)
supprime les services non sélectionnés. Or `compileRelease()` installe avec
`--no-scripts` : le build **n'appliquait pas ce nettoyage**. La désactivation
globale des scripts est conservée ; `pruneGoogleServices()` applique désormais
la sélection dans la copie isolée, puis l'autoload est régénéré.
La [documentation de l'adaptateur S3](https://flysystem.thephpleague.com/docs/adapter/aws-s3-v3/)
décrit des réglages de préfixe, visibilité et IAM ; leur simple installation
ne prouve aucune politique de sécurité effective.

Les résultats PHP/build et le scan de secrets ci-dessous restent ceux de la
campagne initiale. Ils ne sont pas présentés comme des tests des nouveaux SDK.
Aucun appel Google/AWS, nettoyage de vendor, build complet ou scan de secrets
global/historique n'a été relancé. L'audit applicatif de ces futurs usages devra
être rattaché aux lots 07/10/11/13/14 selon leur intégration.

## Périmètre réellement examiné

- Manifeste/verrou Composer de `src/releases/vdev` et manifestes/verrous npm
  de `src/ria`, `src/tools/mermaid` et `src/extensions/plugins/Lms/frontend`.
- [Inventaire de 503 entrées](../Inventories/DEPENDANCES-LOT-02.csv), conservé
  comme **instantané avant correction**, pas comme inventaire final du déploiement :
  paquets Composer/npm, plateformes optionnelles comprises, et six bibliothèques
  copiées. Les différences finales sont détaillées ci-dessous.
- Avis Composer/npm et avis directs des bibliothèques copiées ; provenance de
  12 fichiers statiques (Video.js, Vue, vue3-sfc-loader, Bootstrap, CodeMirror,
  Font Awesome). Les trois fichiers CodeMirror correspondent aux variantes
  minifiées jsDelivr annoncées par leur README, pas aux fichiers npm non minifiés.
- Applicabilité ciblée dans `framework/Lib/HtmlSanitizer`, les classes
  `WysiwygMediaSourceAttributeSanitizer`, `ScssCompiler`, `SafeScssImporter`,
  `Core/Helper/Mail/EmailTemplateContent` et le build ESM LMS
  (`frontend/vite.config.mjs`, `src/tools/extension-vite.mjs`).
- Recherche heuristique de secrets : 1424 fichiers suivis, 454 commits et
  7179 blobs accessibles aux références Git locales, plus 1833 assets publics
  ignorés/non suivis. Les valeurs et empreintes de secrets ne sont pas publiées.

Les preuves et leur portée sont indexées dans
[Evidence/LOT-02](../Evidence/LOT-02/README.md). L'inventaire n'est ni une SBOM
complète des bundles, ni celle des images, ni celle d'un paquet distribué.

## Avis et applicabilité

| Constat | Avis et version initiale | Applicabilité établie et état final |
| --- | --- | --- |
| [SEC-001](../Findings/Resolved/SEC-001.md) | Filesystem 8.1.6 ; `GHSA-6prc-h956-hxjq` | Calcul de confinement vulnérable reproduit sur le composant ; sortie de racine refusée par l'importeur SCSS applicatif testé. Version 8.1.11 contrôlée localement. |
| [SEC-002](../Findings/Resolved/SEC-002.md) | HtmlSanitizer 8.1.8 ; `GHSA-q8jq-xrf4-v5j8` | `srcset`/`imagesrcset` non autorisés dans les profils examinés ; sondes `srcset` refusées avant/après. Version 8.1.11 contrôlée localement. |
| [SEC-003](../Findings/Resolved/SEC-003.md) | HtmlSanitizer 8.1.8 ; `GHSA-wm2w-4mgj-vw33` | Élément `meta` non autorisé ; sonde retirée des profils WYSIWYG/e-mail avant/après. Version 8.1.11 contrôlée localement. |
| [SEC-004](../Findings/Resolved/SEC-004.md) | HtmlSanitizer 8.1.8 ; `CVE-2026-118517` | Contournement amont des hôtes ; filtre `src` supplémentaire dans le WYSIWYG, URL testée refusée avant/après. Version 8.1.11 contrôlée localement. |
| [SEC-005](../Findings/Resolved/SEC-005.md) | Vue SSR LMS 3.5.38 ; `GHSA-g2v6-rqmx-r4w6` | Pas de parcours SSR identifié ; build client avec runtime partagé. Famille Vue alignée sur 3.5.43, avis retiré de l'audit LMS et build isolé réussi. |
| [SEC-006](../Findings/UnderReview/SEC-006.md) | picomatch 4.0.7 ; `GHSA-fc5q-39xc-vjpf` | Version affectée présente dans RIA/LMS. Contrôle d'un motif par un acteur non fiable jusqu'au matcher non établi ; **examen ouvert, pas de correction ni d'acceptation**. |

Les cinq fiches résolues décrivent la suppression locale des versions affectées,
pas cinq vulnérabilités applicatives exploitées. Les trois causes HtmlSanitizer
sont séparées même si une seule mise à jour les traite. La gravité élevée d'un
avis npm n'est pas automatiquement celle du scénario propre à TyFramework.

| Audit | Avant correction | Après correction |
| --- | --- | --- |
| Composer | 4 avis directs sur 2 paquets | Aucun avis dans la réponse conservée |
| RIA | 9 paquets signalés pour le seul avis picomatch | Verrou inchangé, résultat conservé |
| Mermaid | Aucun avis | Verrou inchangé, résultat conservé |
| LMS | 6 paquets signalés pour Vue SSR et picomatch | 4 paquets signalés pour le seul avis picomatch |

Il y a six avis distincts au départ, pas dix-neuf failles : npm propage un
même avis aux paquets dépendants. L'indication `fixAvailable` ne garantit pas
une correction compatible ; les propositions de rétrogradation majeure de
Vite/vue-router n'ont pas été appliquées.

## Corrections autorisées de la campagne initiale

Yann a autorisé les mises à jour compatibles ciblées de Symfony et l'alignement
Vue LMS, sans correction forcée ni rétrogradation majeure pour picomatch.
Cette campagne a changé trois fichiers de dépendances :

- `src/releases/vdev/composer.lock` : `symfony/filesystem` 8.1.6 → 8.1.11 et
  `symfony/html-sanitizer` 8.1.8 → 8.1.11. Aucun paquet ajouté/supprimé et aucune
  autre version changée. Le champ `platform` reprend aussi `ext-curl`, exigence
  **déjà présente** dans `composer.json` à la révision initiale ; ce n'est pas
  un nouveau prérequis décidé par le lot.
- `src/extensions/plugins/Lms/frontend/package.json` et `package-lock.json` :
  Vue et ses neuf paquets `@vue/*` 3.5.38 → 3.5.43 ; `csstype` 3.2.3 → 3.2.4
  transitivement. Aucun paquet ajouté/supprimé.

L'installation Composer locale s'est faite sans scripts/plugins. L'installation
npm sans scripts et le build LMS ont été réalisés dans une copie isolée ; aucun
asset du dépôt n'a été régénéré. Les verrous RIA et Mermaid restent inchangés.
La reprise documentaire n'a pas refait ces modifications ni étendu leur portée.
Les ajouts ultérieurs de Yann sont décrits dans l'état Composer courant ;
ils ne sont pas attribués à ces trois corrections initiales.

## Secrets et historique

Une ancienne valeur SQL a été identifiée dans une ancienne classe de connexion
DB de l'historique local. Selon Yann, elle **n'a jamais été utilisée en
production**. Il prévoit un nouveau dépôt Git. Aucune rotation ni réécriture
de l'historique n'a été réalisée ; la création de ce dépôt n'est pas vérifiée.
Ce n'est pas la démonstration d'une fuite de secret de production.

Aucun autre secret opérationnel n'a été identifié par la recherche effectuée.
Cette formulation ne constitue pas une garantie : scanner heuristique sans
gitleaks, binaires non analysés, configurations privées ignorées hors périmètre,
seuls objets accessibles aux références locales examinés. Un nouveau dépôt ne
supprime pas les anciennes copies ni ne révoque un identifiant ; toute
réutilisation ou exposition réelle découverte imposerait de revoir la décision.
Seule la [synthèse expurgée](../Evidence/LOT-02/secrets-synthese.json) est conservée.

## Vérifications et traçabilité de la campagne initiale

Les résultats acquis avant l'interruption ont été récupérés et conservés dans
[verification.json](../Evidence/LOT-02/verification.json), sans les présenter
comme de nouvelles exécutions à la reprise :

- PHPUnit 13.3.6 / PHP 8.5.4 : **30 tests, 132 assertions**, ciblant purificateur
  HTML, compilateur SCSS et contenu des modèles d'e-mails.
- Huit sondes locales avant et après : refus `srcset`, `meta`, URL média
  malformée, comportement du primitif Filesystem, import SCSS légitime et
  refus de sortie de racine. Le primitif amont est reproduit avant et refusé
  après ; les protections applicatives sondées restent efficaces dans les deux états.
- LMS : `npm ci --ignore-scripts`, puis `npm run build` dans la copie isolée,
  Node 22.22.1 et Vite 7.3.7, succès.
- Réponses d'audit après correction : Composer vide ; LMS réduit à picomatch.

À la reprise : comparaison structurelle des deux verrous avec Git, concordance
des copies auditées/compilées avec les manifestes et verrous finaux, empreintes
finales et `composer --no-plugins --no-scripts validate --no-check-publish`.
Ce dernier valide le manifeste/verrou avec deux avertissements : licence
absente et `larapack/dd` déclaré dans `require` et `require-dev`.

Rattachement à la matrice ASVS 5.0.0 : `v5.0.0-3.6.1` est affectée principalement
au lot 02 ; les chapitres 13.1 à 13.4 et 15.2 comportent des lignes associées.
L'inventaire, les avis et les recherches de secrets n'en prouvent pas la
conformité complète : **les statuts ASVS restent non vérifiés**. Les lots
principaux 13/14/16 et les compléments de ce lot restent nécessaires.

## Compléments et limites ouverts

1. Étudier l'accessibilité du matcher picomatch depuis les entrées réellement
   non fiables dans les outils et bundles RIA/LMS ; consulter de nouveau l'avis
   avant toute décision. Aucun correctif amont n'était annoncé dans l'avis consulté.
2. Inventorier images, paquets système et outils Docker réels : socket
   inaccessible pendant la campagne. Ne pas assimiler un tag de configuration
   à une image/SBOM attestée ; rattachement aux lots 14/16.
3. Attester les transitives embarquées dans les bundles copiés et le contenu
   d'un ZIP final : aucun ZIP produit présent ; complément au lot 14.
4. Examiner plus tard l'hébergement avec Yann ; aucun scan de production,
   test navigateur, test de charge ou essai de déploiement effectué ici.
5. Décider du nettoyage Google dans le build au lot 14 ; la synchronisation du
   verrou est corrigée et vérifiée (SEC-007). Analyser les usages futurs des
   SDK sans déduire leur sécurité d'un audit Composer vide.

L'inventaire HTTP du lot 01 reste un instantané : les modifications parallèles
du cron changent les entrées à reprendre aux lots 03/12. Le lot 02 n'a ni
réextrait `ROUTES.csv`, ni arbitré les descriptions cron de la cartographie.

Le classement documentaire est finalisé. La prochaine analyse peut reprendre
SEC-006 ou un autre lot sur demande de Yann ; **ne pas lancer automatiquement
le lot 03**, ne pas accepter un risque à sa place et ne pas inclure le cron
dans les conclusions de ce lot.
