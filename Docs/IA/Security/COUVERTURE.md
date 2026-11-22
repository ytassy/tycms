# Matrice de couverture de l'audit

## Référentiel et états

La matrice [ASVS.csv](ASVS.csv) référence les **345 exigences** du
[CSV officiel OWASP ASVS 5.0.0](https://raw.githubusercontent.com/OWASP/ASVS/v5.0.0/5.0/docs_en/OWASP_Application_Security_Verification_Standard_5.0.0_en.csv),
publié par le projet [OWASP ASVS](https://asvs.owasp.org/).
Le texte normatif reste dans cette source ; l'export local contient les
identifiants versionnés, l'affectation aux lots et nos preuves attendues.

Empreinte SHA-256 du CSV source utilisé :
`98c8fe911b9edb403af8ee05d3ce8201ecac2659e313b053890a62847cdcf680`.

Le socle de travail comprend les 253 exigences L1/L2. Parmi les 92 L3,
13 renforcements sont retenus pour les frontières de privilèges, les secrets
et l'exécution. Les autres restent identifiés comme complémentaires ou
conditionnels. **Toutes les lignes sont « non vérifiée »** : leur affectation
ne vaut ni conformité, ni acceptation d'un risque, ni décision de correction.
Les lignes rattachées au lot 03 pointent désormais vers ses preuves locales
partielles et ses constats, y compris les corrections ciblées SEC-008/011/012
vérifiées localement ; aucune conformité complète n’en est déduite.

L'applicabilité est provisoire lorsque l'exigence dépend d'une technologie,
d'un rôle OAuth ou d'une politique d'authentification. Aucun contrôle n'a été
retiré pour la seule raison que son mécanisme n'est pas implémenté.

| État de périmètre de l'exigence | Nombre | Traitement attendu |
| --- | --- | --- |
| Socle L1/L2 à examiner | 205 | Prouver le contrôle ou documenter l'écart dans le lot responsable. |
| Technologie à confirmer absente | 7 | Vérifier aussi les dépendances avant une éventuelle exclusion motivée. |
| Rôle/protocole actif non identifié | 44 | Confirmer le rôle exercé et les extensions avant toute exclusion. |
| Politique MFA/IdP à préciser | 16 | Distinguer mécanisme local et contrôle confié à l'IdP ; décision aux lots 05/07. |
| Renforcement L3 retenu | 13 | Examiner explicitement dans les lots affectés. |
| L3 complémentaire à arbitrer | 60 | Décider selon les menaces, sans transformer un report en conformité. |

Les lignes conditionnelles peuvent appartenir à L1/L2 ou à L3 ; les catégories
ci-dessus sont disjointes mais ne constituent pas une ventilation par niveau.
Le niveau original est conservé dans chaque ligne CSV.

## Renforcements retenus

| Identifiant versionné | Point de contrôle propre au projet | Lots principaux |
| --- | --- | --- |
| `v5.0.0-1.3.12` | Regex de routage/WAF et entrées coûteuses. | 03, 08, 12 |
| `v5.0.0-1.5.3` | Accord des parseurs URL/JSON/XML et normalisation entre couches. | 08, 11 |
| `v5.0.0-7.5.3` | Preuve récente d'identité pour actions particulièrement sensibles. | 06, 05, 07 |
| `v5.0.0-8.3.2` | Droits retirés, caches ACL et exécutions différées. | 04, 12 |
| `v5.0.0-8.3.3` | Identité d'origine et intermédiaires auteur/service/action. | 04, 12 |
| `v5.0.0-11.2.4` | Comparaisons et opérations cryptographiques adaptées aux secrets. | 13, 07, 12 |
| `v5.0.0-11.2.5` | Échecs cryptographiques sans divulgation exploitable. | 13, 15 |
| `v5.0.0-11.3.4` | Nonces et données d'initialisation du chiffrement. | 13 |
| `v5.0.0-11.5.2` | Génération de secrets/tokens, y compris appels répétés. | 13, 05, 12 |
| `v5.0.0-13.3.4` | Politique de rotation et récupération des secrets. | 13, 15, 16 |
| `v5.0.0-15.4.2` | Atomicité entre vérification et usage des fichiers/permissions/états. | 08, 04, 12, 15 |
| `v5.0.0-15.4.3` | Cohérence des verrous et récupération d'opérations. | 08, 12, 15 |
| `v5.0.0-16.5.4` | Dernier gestionnaire d'erreurs, entrées autonomes comprises. | 13, 15 |

Cette sélection renforce le cadrage local ; elle ne revendique pas un audit L3.
La décision de réaliser une évolution découverte ici appartient toujours à Yann.

## Apport du lot 02

Le [rapport](Reports/LOT-02.md) et ses [preuves](Evidence/LOT-02/README.md)
rattachent les versions/avis, les sondes et la recherche heuristique de secrets
à la révision du lot 02. L'inventaire des dépendances est figé avant correction ;
les empreintes finales et le rapport identifient précisément les mises à jour.

La ligne principale `v5.0.0-3.6.1` et les lignes associées au lot 02 des
chapitres 13.1–13.4 et 15.2 ne sont pas déclarées conformes : images/outils,
artefacts distribués, transitives et contrôles complets des autres lots manquent.
**Les 345 statuts ASVS restent non vérifiés.** Une correction locale vérifiée
dans une fiche SEC ne vérifie pas à elle seule une exigence ASVS entière.

Les matrices de routes et de fichiers du lot 01 restent des instantanés de ce
cadrage, pas des preuves de sécurité du cron modifié en parallèle. Leur remise
en concordance avec les nouvelles entrées appartient aux lots 03/12 puis 17.

## Surfaces à examiner et responsabilité

Chaque fichier présent suivi ou non ignoré hors `Docs/IA/Security` est affecté
dans [PERIMETRE.csv](PERIMETRE.csv). Les regroupements ci-dessous précisent les
responsabilités transverses. Les tests sont inventoriés comme support de preuve ;
leur adéquation au domaine devra être établie, pas déduite de leur nom.

| Surface / artefacts | Lots responsables | Preuve à produire |
| --- | --- | --- |
| Bootstrap, Request, Router, runner, réponses et `.htaccess` | 03, 08, 15, 16 | Entrées/refus et comportement avant/après contrôles transverses. |
| ACL, permissions, rôles/groupes, attributions, ORM sérialisé | 04, 08, 13 | Matrice négative acteur/objet/Space ; interdiction de délégation indue. |
| Comptes, inscription, récupération et Mon compte | 05, 06, 07 | Scénarios de prise de compte, anti-abus et opérations sensibles. |
| Sessions PHP/SQL et RunSession | 06, 05, 12 | Fixation/renouvellement, expiration, révocation et identité interne. |
| SSO/OAuth/JWT et clients OIDC | 07, 11, 13 | Flux, validation d'identité, secrets et rejeu. |
| SQL/ORM, Forms, ObjectValidator et helpers | 08, 04 | Paramétrage, champs autorisés, invariants, rollback et concurrence. |
| Pages, composants, ancien HTML, SCSS, shortcodes et SVG | 09, 04, 08 | Entrées/rendus sûrs, brouillons et appels IA compris. |
| Vue, stores, `v-html`, previews, Mermaid et bibliothèques publiées | 09, 03, 02 | Isolation navigateur, messages, DOM et versions effectivement livrées. |
| Médias, HLS, polices et répertoires | 10, 04, 11, 16 | Chemins, types actifs, écriture et exposition web ; lecture publique assumée. |
| IA, SMTP, polices distantes, notifications et HTTP | 11, 07, 10, 12, 14 | Destinations, TLS, réponses, coûts et confidentialité. |
| Automatisation entière, actions Core et bouton de page | 12, 04, 08, 13 | Principaux, permissions actuelles, authentification, budgets et transactions. |
| Security : politique, en-têtes, WAF et exemptions | 03, 04, 09 | Défauts, modes effectifs et absence de confiance excessive dans le WAF. |
| CryptHelper, secrets fournisseurs, logs et erreurs | 13, 02, 07, 12, 15 | Confidentialité de bout en bout, crypto, rotation et diagnostics. |
| Composer, npm, Mermaid, LMS et bibliothèques copiées | 02, 09, 14 | Inventaire des versions embarquées et avis applicables. |
| Build système/extension, publication, distribution et signatures | 14, 02, 15 | Chaîne de confiance, paquets altérés, exclusions et clés. |
| Chargement des extensions, Tools, TyCatalog, LMS, Matrix dormant, Test | 14, 16, 04, 02 | Conditions d'activation et réaudit obligatoire de toute nouvelle exposition. |
| Updater, états, backup MariaDB et migrations | 15, 13, 08 | États/verrous, actions autonomes, interruption et récupération isolée. |
| Docker, outils SQL, exemples de configuration et commandes | 16, 02, 14 | Séparation DEV/production et conditions requises par le déploiement. |
| Hébergement, proxy, droits SQL/disque, TLS et sauvegardes externes | 16 avec Yann | Preuves de configuration réelle ; non observées dans cet audit local. |
| Tests/fixtures, guides et métadonnées | Lot métier concerné puis 17 | Fiabilité des preuves, cohérence des contrats et absence de trou de couverture. |

## Conditions de clôture de la couverture

Chaque lot doit renseigner ses preuves et écarts pour les routes, fichiers et
exigences dont il est responsable. Remplacer le statut courant au lieu d'ajouter
une chronologie de résultats. Distinguer : vérifiée, écart constaté, non applicable
justifiée, reportée avec motif, et non vérifiée faute de preuve.

Le lot 17 vérifiera que rien n'est resté « inventorié » sans examen ou report
motivé, que les corrections n'ont pas ajouté d'entrée oubliée, et que chaque
risque accepté l'a été explicitement. Il rapprochera les empreintes de départ
de l'état final pour demander une nouvelle analyse des changements pertinents.
