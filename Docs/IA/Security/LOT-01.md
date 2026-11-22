# Lot 01 — Modèle de menace et couverture

L’inventaire HTTP courant est [actualisé au lot 03](Reports/LOT-03.md) ;
les nombres ci-dessous décrivent l’instantané du lot 01.

## Conclusion et révision

**Cadrage local réalisé.** L'inventaire des entrées, le modèle de menace et
l'affectation des contrôles aux lots sont disponibles. Il ne s'agit pas d'une
validation des protections ni d'une conclusion sur l'absence de vulnérabilité.

Révision de référence des sources : `fa21acf5c283d0501ade2dcfa6995dd51810eaa1`.
Les empreintes de [PERIMETRE.csv](PERIMETRE.csv) identifient les fichiers
inventoriés et ont été rapprochées des sources courantes avant clôture.
Les travaux préexistants, notamment sur l'automatisation et ses guides, sont
conservés. Les changements réalisés pour ce lot sont exclusivement dans
`Docs/IA/Security` ; aucune correction applicative n'a été effectuée.

## Décisions confirmées par Yann

| Décision | Conséquence pour l'audit |
| --- | --- |
| Les médias et vidéos HLS sont publics par URL ; les droits protègent leur gestion. | Leur lecture sans authentification ne constitue pas à elle seule un défaut. Vérifier malgré tout chemins, fichiers actifs, contenus hors périmètre et protections des secrets internes. |
| Les administrateurs de Space et auteurs de scénarios ont une confiance limitée. | Examiner explicitement le cas d'un acteur malveillant ou compromis : aucune extension de ses droits à un autre Space, à ROOT ou à une action non déléguée. |
| L'automatisation est activée sur l'installation hébergée. | Webhooks, cron, boutons, exécution auteur/service et notifications sont des surfaces prioritaires. La version exacte et la configuration déployées restent inconnues. |
| Analyse locale uniquement, puis décisions avant correction. | Aucun correctif applicatif et aucun accès actif à la production dans ce lot. |

## Livrables et couverture réelle

| Livrable | Contenu et limites |
| --- | --- |
| [Entrées et contrôles](ENTREES.md) | Familles de routes, frontières, entrées autonomes, contrôles attendus et exceptions. |
| [ROUTES.csv initial](Evidence/LOT-03/routes-before.csv) | 308 déclarations méthode/verbe, extraites statiquement de 36 classes portant `Controller`, avec emplacement, cible, attributs et lots. Comprend du code non enregistré et du DEV explicitement identifiés. |
| [Modèle de menace](MENACES.md) | Actifs, acteurs, frontières et scénarios d'abus à éprouver. |
| [Couverture](COUVERTURE.md) et [ASVS.csv](ASVS.csv) | 345 identifiants ASVS 5.0.0 affectés aux lots, applicabilité et preuves à établir. Aucun contrôle marqué conforme. |
| [PERIMETRE.csv](PERIMETRE.csv) | 1412 fichiers suivis ou non ignorés présents hors de ce dossier, avec empreinte et lots responsables. « Inventorié » ne signifie pas « audité ». Les configurations ignorées, runtimes et dépendances installées ne sont pas ajoutés à cet export. |

Pour Core, MainTemplate et Automation chargés, l'inventaire relève **157 routes
candidates : 55 GET et 102 POST**. Il inclut 96 POST soumis au CSRF du runner,
trois déclarations webhook/cron exemptées et quatre POST non API à examiner
séparément. Les 55 GET sont également hors vérification CSRF automatique.

Les 148 déclarations Matrix sont **non enregistrées** par son plugin actuel.
Trois déclarations Test sont réservées au DEV. Les routes de menus restent
des familles dynamiques : sans lire la base du site, leurs URL, nombre et
visibilités exacts ne peuvent pas être énumérés. Les entrées autonomes et
fichiers statiques ne sont pas compris dans le nombre 157.

## Points à examiner en priorité

Ces observations complètent la [cartographie](CARTOGRAPHIE.md). Elles ne sont
pas des fiches de vulnérabilité qualifiées et n'autorisent aucune correction.

| ID | Fait repéré | Analyse suivante |
| --- | --- | --- |
| OBS-008 | `SpaceController::choosePost()` traite `POST /space/choose`, route non API : il charge le Space reçu et modifie la session, sans WF ni vérification CSRF explicite dans cette méthode. | Lots 03/04/06 : protections cookies, provenance, conditions d'accès, conséquences du changement et comparaison avec `WFSpaceSwitch`. |
| OBS-009 | Le type de menu `logout` raccorde un GET à `UserController::logout()`, qui détruit la session sans jeton dans cette méthode. | Lots 03/06 : déclenchement intersite, rejeu et conséquence fonctionnelle ; ne pas assimiler une déconnexion forcée à une prise de compte. |
| OBS-010 | `WebRequest::isBehindProxy()` se fonde sur la présence de l'un des en-têtes Forwarded ; `X-Forwarded-Host` peut ensuite servir d'autorité. Le contrat repose sur le filtrage par le serveur web. | Lots 03/16, puis 07 : vérifier hypothèse proxy, hôtes autorisés, URL de retour et protections des cookies. Configuration réelle inconnue. |
| OBS-011 | `WebRequest` lit entièrement `php://input` avant le routage, le WAF et les limites propres au webhook. Les bornes métier ne prouvent donc pas une limite d'allocation en entrée. | Lots 03/08/12/16 : limites Apache/PHP, formats acceptés, coût des parseurs et refus précoce. Aucun test de charge effectué. |

La plupart des API délèguent l'autorisation au WF sans annotation ACL de route.
Ce choix correspond à l'architecture ; il impose de vérifier **chaque méthode
appelée** et les points d'entrée internes au lot 04. L'absence d'annotation
seule n'est pas un constat de défaut d'autorisation.

Les webhooks ont une authentification propre et ne doivent pas être évalués
comme un formulaire utilisateur exempté sans compensation. Le mode de réponse
`result` traite son événement avant commit : inclure ses actions externes,
erreurs, retours et événements enfants dans l'analyse transactionnelle du lot 12.

## Écarts documentaires

- **DOC-001 résolu dans l'arbre courant** : l'architecture, l'état du projet et
  les travaux restants décrivent désormais les webhooks/planification/worker.
  Cette correction était déjà présente au début du lot ; elle n'est pas un
  changement réalisé par cet audit. Les limites d'intégration/reprise restent.
- **DOC-002** : la ligne Formulaires de la carte du projet cite encore
  `src/framework/Lib/Forms/`. Le code se trouve sous
  `src/releases/vdev/framework/Lib/Forms/`. Ce chemin est à corriger lors de la
  prochaine mise à jour de cette ligne ; l'audit suit le code réel.

## Contrôles effectués et limites

- Lecture du bootstrap, du routeur, du runner, des enregistrements de
  contrôleurs et de menus, des attributs et des exceptions sensibles.
- Extraction lexicale sans exécuter PHP, ses attributs ou l'application ;
  rapprochement des 308 attributs `ControllerMethod` et des lignes exportées.
- Comparaison des déclarations avec `getControllers()` : Matrix écarté de
  l'exposition actuelle, Test identifié DEV, Automation retenu selon Yann.
- Affectation des fichiers et des exigences ASVS ; contrôles d'intégrité,
  d'unicité et de cohérence des exports et des liens documentaires.

Aucun test PHP/Node, accès SQL, démarrage de l'application, appel à un webhook,
scan du site ni essai navigateur n'a été effectué. Ce lot documente les contrôles
à prouver ; il ne mesure pas leur efficacité. L'inventaire statique ne remplace
pas un inventaire runtime sur une fixture locale préparée, si nécessaire plus tard.

Restent inconnus : gestionnaire de sessions effectivement déployé, release et
extensions exactes, politiques des Spaces, filtrage proxy, droits serveur/SQL
et sauvegardes réelles. Ces éléments sont rattachés aux lots concernés ; ils
n'empêchent pas de poursuivre l'audit du dépôt.

## Suite recommandée

Lancer le lot 02 pour les versions livrées et les secrets exposés, puis le lot 03
en priorité sur les exceptions et le contexte proxy. Le lot 04 et le lot 12
devront traiter ensemble les frontières entre auteur, service, Space et ROOT.
Ne pas attendre les essais d'hébergement pour analyser ces chemins locaux.
