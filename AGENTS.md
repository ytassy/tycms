# Consignes de développement pour les agents

## Avant de modifier le code

- Utiliser [l'index](Docs/README.md) pour choisir les guides utiles à la tâche,
  et [l'état du projet](Docs/IA/ETAT_DU_PROJET.md) pour situer une fonctionnalité.
- Lire [l'architecture](Docs/ARCHITECTURE.md) pour les responsabilités, le
  parcours d'une requête, les services partagés, les transactions et la sécurité.
- Pour une interface Vue, consulter [le guide frontend](Docs/FRONTEND.md) :
  mixin global, modales, confirmations, classes `ctrl-s` / `esc`, stores et
  composants communs. Réutiliser ces mécanismes avant d'en créer.
- Lire le guide métier concerné ; vérifier les méthodes dans le code avant
  d'en déduire un comportement. Les [travaux restants](Docs/IA/TRAVAUX_RESTANTS.md)
  distinguent les limites actuelles des pistes non décidées.

## Conception de travail

- Le projet est un projet de CMS, pas une grosse application professionnelle d'envergure.
  C'est un CMS plus petit que Joomla ou Wordpress. Il est fait avant tout pour tourner
  sur des hébergements mutualisés. Donc avec des contraintes techniques.
  Ne complexifie pas inutilement mais soit attentif à la sécurité.
  En particulier la sécurité concernant l'accès aux données et la prise de contrôle
  à distance.

## Périmètre et arrêt

- Annoncer le plan en trois à cinq points concrets avant d'implémenter, puis s'y
  tenir. Un besoin découvert en route se signale et attend l'accord de
  l'utilisateur au lieu d'être intégré.
- Si un test ou une vérification demande plus de deux tentatives pour démarrer
  un environnement, suspendre cette partie : proposer un test plus simple ou
  signaler la limite.

## Lisibilité du code

- Aérer le code avec des lignes vides entre les étapes d'un traitement.
- Commenter régulièrement ces étapes en français, en expliquant leur rôle ou
  la raison d'un choix. Voir `Docs/ARCHITECTURE.md`.

## Convention d'accès aux services partagés

- Pour un helper, workflow ou service partagé utilisé par une classe métier,
  définir un accès typé dans le trait adapté et utiliser cette propriété dans
  le code appelant. Réserver `getSharedExtensionsClass()` aux traits et aux
  points d'infrastructure qui exposent cet accès.
- Réutiliser les accès déjà fournis par `BaseApplicationItem` et les traits
  existants avant d'en créer un nouveau. Conserver les annotations propres aux
  modèles ORM dans leur trait dédié. Voir `Docs/ARCHITECTURE.md`.
- Un trait peut en composer un autre. Éviter toutefois d'importer le même trait
  de propriétés à la fois dans une classe parente et dans une classe enfant :
  les propriétés à hooks provoquent alors un conflit sous PHP 8.4.
- Une classe qui hérite déjà de `TraitCoreSharedHelpers` n'importe que
  `TraitCoreAdditionalHelpers` si elle a besoin des helpers supplémentaires ;
  elle ne réimporte pas `TraitAllHelpers`.

## Collaboration
- Répondre en français.
- Au fur et à mesure que tu travailles, explique où tu en est pour que je sache ce que tu fais
- Poser une question à l'utilisateur lorsqu'un choix de comportement, de
  périmètre ou d'architecture doit être fait, ou lorsqu'une incertitude empêche
  de bien cibler la demande. Formuler les options et leur conséquence.
- Une fois le périmètre défini, mène le travail autorisé jusqu’aux vérifications utiles.
- Expliquer brièvement les constats, les modifications et les vérifications.
- Signaler les écarts entre documentation et code rencontrés pendant une tâche.
- Choisir des vérifications proportionnées ; laisser à l'utilisateur les
  essais visuels simples et éviter les tests navigateur complexes sans besoin.
- Rester particulièrement vigilant sur la sécurité du code de production :
  ACL dans le WF, Space réel, entrées non fiables, CSRF, SQL paramétré,
  échappement, purification et secrets selon les contrats des guides.
- Avant de faire des Vérifications/Tests complexes (test navigateurs par exemple) 
  que je pourrais faire pour toi, propose moi de t'aider.
- Pour valider une action simple, vérifier le contrat par test unitaire
  (entrée, sortie, cas de refus) plutôt que de faire tourner un run, une route
  ou une requête complète.
- Un test qui échoue d'une façon inattendue peut signaler une hypothèse fausse,
  pas seulement un bug. Chercher d'abord l'explication la plus simple, en deux
  commandes au plus, avant de lancer un diagnostic plus lourd.

## Journaux utiles pendant le développement

- Pour chaque évolution, penser aux événements et échecs qui aideront à la
  diagnostiquer en production ; suivre [le guide des journaux](Docs/LOGGING.md).
- Utiliser les méthodes de `BaseApplicationItem`, des codes stables et le Space
  réel. Conserver les causes internes des erreurs interceptées, sans dupliquer
  celles qui remontent déjà au runner.
- Ne journaliser ni secrets, ni contenus utilisateurs, ni réponses distantes
  brutes. Éviter les lectures et validations ordinaires répétitives ; ne pas
  annoncer une réussite avant le commit.

## Après une évolution

- Consigner dans le fichier racine `version.md` uniquement les nouveautés,
  changements, correctifs et limites utiles aux utilisateurs finaux. Toutes
  les évolutions techniques n'y figurent pas ; leurs contrats vont dans les
  guides concernés. Les compatibilités de la release sont maintenues dans
  `version.json` ; le build fige ces deux fichiers.
- Mettre à jour le guide technique concerné si le contrat change, avec les
  détails réutilisables et la raison d'un choix durable près du contrat.
- Actualiser la ligne concernée de `Docs/IA/ETAT_DU_PROJET.md` si l'état d'une
  fonctionnalité évolue, et les travaux restants si une limite est levée.
- Remplacer les descriptions dépassées ; ne pas ajouter de journal daté de
  décisions, de livraisons ou de résultats de tests. Git conserve l'historique.
- Vérifier selon [le guide de vérification](Docs/IA/VERIFICATION.md). À la fin,
  résumer les changements, les contrôles réellement effectués et leurs limites.

Les guides de `Docs` sont la source de vérité des contrats techniques.
`Docs/IA` sert de carte du projet, de repère de vérification et de liste des
travaux ouverts ; il ne remplace pas les guides ni la lecture du code.
