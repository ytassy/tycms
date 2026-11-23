# Audit de sécurité du projet

## Périmètre et état courant

Périmètre retenu avec Yann : **dépôt complet et essais locaux isolés**,
comprenant le framework, les extensions présentes, le frontend, les dépendances,
les outils, les builds, la distribution, l'updater et les configurations
versionnées. L'hébergement réel sera contrôlé ensuite avec lui.

La préparation et le lot 01 ont identifié les surfaces, posé le modèle de menace
et organisé la couverture. Le [lot 02](Reports/LOT-02.md) a produit l'inventaire
des dépendances, les recherches heuristiques de secrets et les corrections
ciblées autorisées ; sa couverture reste **partielle**, notamment pour
picomatch, les images Docker et les artefacts finaux. Les ajouts Composer ont
été réexaminés : 63 paquets sans avis signalé ; verrou resynchronisé et validé
sans changement de versions (SEC-007). Le [lot 03](Reports/LOT-03.md) est
analysé localement ; SEC-008, SEC-011 et SEC-012 sont corrigées et vérifiées
sur la base `50cdf55`. SEC-009, SEC-010 et DOC-003 restent ouverts. Les autres lots détaillés
restent à lancer ; **aucune conclusion de sécurité globale n'est acquise.**
La base de cette cartographie est le commit
`870b5fda125e29583c5640edf71b7b4c96363497`, avec un arbre de travail initialement
propre. Chaque lot devra préciser sa propre révision, car le projet peut évoluer.
Le lot 01 prend pour référence les sources de
`fa21acf5c283d0501ade2dcfa6995dd51810eaa1`, identifiées également par les
empreintes de `PERIMETRE.csv`. Ses changements sont exclusivement documentaires.

## Audit automatique différentiel

Un workflow analyse les changements de `security_X` depuis un **SHA de
référence** consigné dans [`DERNIER_AUDIT`](DERNIER_AUDIT) : il publie un rapport
daté sous `Reports/` puis avance cette référence. Il ne corrige rien ; les
constats remontent dans `dev_X_Y` par un merge décidé par Yann.

L'objet de l'analyse est le **dépôt dans son état actuel**, pas le patch : les
changements indiquent où regarder, et l'agent lit le code complet autour d'eux,
puis trace les **impacts hors des zones modifiées** — appelants d'un helper
partagé, nouveaux points d'entrée, droits élargis, données relues ailleurs. Le
rapport consigne l'état analysé et le périmètre d'impact réellement examiné.

L'agent rédige une **fiche par constat** dans
[`Findings/Auto/`](Findings/README.md) (`AUTO-NNN.md`), au fil de la découverte,
et sa réponse finale n'est qu'un index. Ces fiches sont conservées et poussées
pour être traitées en développement ; tout le reste de ce qu'il écrit est
abandonné (retour au commit de départ).

`Auto/` reste séparé de `UnderReview`, `Confirmed` et `Resolved` : un constat
automatique n'est pas qualifié. Les identifiants `AUTO-NNN` ne recouvrent jamais
les `SEC-NNN` du registre. Les identifiants Git sont retirés pendant l'analyse,
et la publication reprend la main sur une branche désignée explicitement. Un
audit raté se jette : supprimer la branche et la recréer suffit.

Sans SHA valide, **aucune analyse n'est lancée** : comparer à `dev_X_Y` ne
montrerait rien, les deux branches ayant le même contenu après un merge. Le
workflow l'annonce explicitement au lieu de laisser croire à un audit réussi.

Les remarques qui ne sont pas des vulnérabilités — cohérence d'un guide avec
le code, code mort, test manquant, dette — sont rangées dans un fichier
compagnon `Reports/AUTO-<date>-<sha>-observations.md`. Elles ne reçoivent ni
identifiant ni gravité et ne figurent pas au registre des constats.

Cet audit **ne remplace pas** les lots ci-dessous : il examine un diff, alors que
les lots suivent les données à travers les routes, les ACL et les Spaces. Il
signale les régressions nouvelles ; il ne produit pas de conclusion globale.

| Document | Rôle |
| --- | --- |
| [Plan des lots](PLAN.md) | Ordre conseillé, points d'entrée, vérifications et commandes de lancement en conversation. |
| [Cartographie initiale](CARTOGRAPHIE.md) | Actifs, frontières de confiance, limites connues et écarts entre guides et code. |
| [Suivi](SUIVI.md) | État actuel des lots, registre des constats et modèle de fiche. |
| [Modèles et effort](MODELES.md) | Réglages proposés selon la difficulté et le budget. |
| [Rapport du lot 01](LOT-01.md) | Décisions de périmètre, observations et limites du cadrage réalisé. |
| [Rapport du lot 02](Reports/LOT-02.md), [point de reprise](Reports/CONTINUATION.md) | Résultats des dépendances, corrections vérifiées et compléments ouverts ; point de reprise courant partagé avec le lot 03. |
| [Rapport du lot 03](Reports/LOT-03.md), [preuves](Evidence/LOT-03/README.md) | Analyse HTTP locale, inventaire actualisé, trois corrections vérifiées et points ouverts. |
| [Constats](Findings/README.md) | Fiches par cause, classées selon leur état ; un avis amont n'est pas une exploitation applicative démontrée. |
| [Preuves du lot 02](Evidence/LOT-02/README.md), [inventaire initial](Inventories/DEPENDANCES-LOT-02.csv) | Audits, sondes et empreintes avant/après ; inventaire figé avant correction, pas une SBOM du déploiement. |
| [Actualisation Composer](Evidence/LOT-02/ComposerRefresh/README.md), [inventaire courant](Inventories/COMPOSER-CURRENT-LOT-02.csv) | Ajouts de Yann, avis et validation ; empreintes avant/après resynchronisation autorisée du verrou. |
| [Entrées et contrôles](ENTREES.md), [routes](ROUTES.csv) | Entrées HTTP, dynamiques et autonomes, cibles et protections à éprouver. |
| [Modèle de menace](MENACES.md) | Actifs, acteurs, frontières de confiance et scénarios d'abus. |
| [Couverture](COUVERTURE.md), [ASVS](ASVS.csv), [périmètre](PERIMETRE.csv) | Exigences et fichiers affectés aux lots, avec preuves restant à établir. |

Les [guides techniques](../../README.md) restent la source des contrats.
Ce dossier conserve l'état de l'audit, ses preuves et les décisions en attente ;
les décisions techniques durables vont ensuite dans les guides concernés.
Ne pas accumuler de journal daté : remplacer les états devenus obsolètes.

Le classement retenu est `Findings/{UnderReview,Confirmed,Resolved,AcceptedRisks,Dismissed}`,
`Reports`, `Evidence` et `Inventories`. Les catégories vides sont décrites dans
l'index des constats et seront créées à leur première fiche. Les matrices du
lot 01 restent à leur emplacement actuel pour préserver leurs références.

## Méthode par sujet

1. Lire le lot, les guides et le suivi ; relever la révision et les fichiers
   effectivement couverts, y compris les appels depuis d'autres points d'entrée.
2. Suivre les données depuis l'entrée non fiable jusqu'à la lecture protégée,
   l'écriture, la sortie HTML/JSON, l'accès fichier ou l'appel externe.
3. Chercher aussi les contournements : invité, compte bloqué, autre utilisateur,
   autre Space, parent/enfant, permission retirée, objet déplacé, appel direct,
   concurrence, rejeu et dépassement des limites.
4. Pour chaque problème, séparer preuve statique, reproduction isolée et
   hypothèse. Examiner les protections en aval avant de conclure.
5. Présenter les constats à Yann avec les options, leurs conséquences et une
   recommandation. **L'analyse n'autorise pas la correction applicative.**
6. Après sa décision, corriger le périmètre retenu, tester le refus de l'abus
   et le parcours légitime, puis mettre à jour la fiche et le contrat concerné.

Une faiblesse urgente peut être signalée immédiatement sans attendre la fin
du lot. Une mesure de confinement éventuelle reste soumise à la décision de Yann.

## Ce que doit livrer chaque analyse

- Une conclusion courte, les fichiers examinés et les éléments non couverts.
- Des constats ordonnés par risque, avec identifiant stable, emplacement précis,
  acteur, préconditions, impact, preuve et niveau de confiance.
- Les protections déjà présentes et les raisons qui limitent l'exploitation.
- Les choix à trancher, les conséquences fonctionnelles et la recommandation.
- Les vérifications réellement réalisées et celles proposées à Yann.
- La mise à jour du suivi, sans déclarer une correction vérifiée sur la seule
  base d'une modification de code.

La gravité et la certitude sont distinctes. Une conséquence grave supposée
ne devient pas une vulnérabilité démontrée. Une limite volontaire peut demander
une décision produit sans constituer un défaut d'implémentation.

## Vérifications proportionnées

Suivre le [guide de vérification](../VERIFICATION.md). Privilégier lecture du
code, tests ciblés existants et petits scénarios reproductibles sur fixtures
isolées. Réserver les nouveaux tests aux propriétés de sécurité utiles.

La production, y compris `tests.yanntassy.fr`, n'est jamais une base de test.
Ne pas lancer de reset SQL, d'updater, de charge ou de scan sur l'hébergement.
Ne pas installer de dépendances, mettre à jour les lockfiles ou exécuter un
script de paquet simplement pour inventorier les versions.

Les recherches de secrets devront éviter d'en afficher les valeurs. Les fiches
ne contiendront ni jeton, clé privée, cookie, lien secret d'updater, contenu
utilisateur ni configuration locale brute. Les exemples utilisent des données
factices. Si le dépôt est partagé, contrôler aussi qui peut lire ce dossier.

Demander l'aide de Yann avant un banc navigateur complexe ou une vérification
externe. Pour les essais visuels simples, lui fournir les étapes, le profil
requis et le résultat attendu. Une vérification nécessitant l'environnement
réel reste « non vérifiée » tant que son résultat n'est pas connu.

## Référentiel et clôture

Utiliser [OWASP ASVS 5.0.0](https://asvs.owasp.org/) comme grille de couverture,
avec le niveau 2 comme point de départ proposé pour cette application à comptes
et Spaces. Le lot 01 précisera les exigences applicables et les renforcements
nécessaires sur ROOT, les secrets et l'exécution de code. Cela ne constitue
ni une certification ni une conformité ASVS déjà vérifiée.

Le lot 01 construira une matrice des exigences applicables, avec leurs
identifiants versionnés, les lots responsables et les preuves attendues. Chaque
exigence devra finir couverte, reportée ou non applicable avec justification.

La clôture exige de rapprocher cette matrice, l'inventaire des routes et
composants, les corrections et les risques résiduels. Un lot terminé peut
conserver des risques acceptés : « analysé » ne signifie pas « sans risque ».
L'audit local ne clôture pas les contrôles de l'hébergement réel.
