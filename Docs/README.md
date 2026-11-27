# Documentation de développement

Les guides décrivent le code actuel et ses contrats. Lire les documents utiles
à la tâche, puis vérifier les méthodes concernées dans le code. Une limite
documentée ou une piste de travail ne constitue pas une fonctionnalité livrée.

## Trouver rapidement le bon guide

| Besoin | Document |
| --- | --- |
| Situer une fonctionnalité et ses limites | [État du projet](IA/ETAT_DU_PROJET.md) |
| Comprendre les dossiers, le démarrage, les couches PHP, les transactions et la sécurité | [Architecture](ARCHITECTURE.md) |
| Réutiliser les mixins, modales, raccourcis `ctrl-s` / `esc`, stores et composants Vue | [Frontend et conventions d'interface](FRONTEND.md) |
| Développer une extension PHP/Vue, son build ESM et son Vite Docker | [Extensions](EXTENSIONS.md) |
| Ajouter une route, des paramètres, une vue ou une réponse | [Contrôleurs](CONTROLLERS.md) |
| Planifier un traitement récurrent avec la route CRON et ses hooks | [CRON](CRON.md) |
| Enfiler, déclarer et exécuter des traitements différés | [File de jobs](JOBS.md) |
| Configurer les sources externes, les domaines d’iframe et le WAF par Space (ROOT) | [Sécurité HTTP](PLUGIN_SECURITE.md) |
| Mener l'audit de sécurité par sujets et suivre les constats et décisions | [Audit de sécurité](IA/Security/README.md) |
| Définir et valider un formulaire PHP, choisir un type de champ | [Formulaires](FORMS.md) |
| Modifier l'atelier, les styles, les liens ou les composants Core | [Éditeur de pages](PAGE_EDITOR.md) |
| Fournir un composant de page depuis Core ou une extension | [Bibliothèque de composants](PAGE_COMPONENTS.md) |
| Choisir, téléverser ou servir des fichiers ; utiliser le lecteur HLS | [Médias](MEDIAS.md) |
| Utiliser Mermaid, les shortcodes utilisateur ou Bunny | [Shortcodes](SHORTCODES.md) |
| Installer ou sélectionner des polices partagées | [Polices](FONTS.md) |
| Traduire, résoudre une clé, projeter un modèle ou générer une URL localisée | [Langues](LANGUAGES.md) |
| Éditer une chaîne ou des variantes par langue dans Vue | [LocalizedTextField](LOCALIZED_TEXT_FIELD.md) |
| Gérer les connexions IA et intégrer le chat et ses Tools | [Assistance IA](AI.md) |
| Envoyer un message et déclarer un modèle d'e-mail | [E-mails](MAIL.md) |
| Chiffrer un secret et comprendre la conservation de la clé | [Chiffrement](CRYPTO.md) |
| Connecter un fournisseur d'identité ou une intégration OAuth | [SSO et OAuth](SSO.md) |
| Inscrire un compte ou rejoindre un Space | [Inscription](REGISTRATION.md) |
| Composer une page Mon compte : profil personnel, mot de passe, SSO et intégrations | [Mon compte](MY_ACCOUNT.md) |
| Gérer les comptes et leurs attributions | [Utilisateurs](USERS.md) |
| Gérer les groupes et leurs rôles | [Groupes](USER_GROUPS.md) |
| Déléguer et administrer les permissions | [Rôles](ROLES.md) |
| Suivre la cohérence des permissions, les prérequis des rôles et l’unification des accès | [Chantier des permissions](IA/CurrentWorks/ACL_PERMISSIONS.md) |
| Modifier la récupération d'un compte | [Mot de passe oublié](PASSWORD_RESET.md) |
| Choisir les vérifications et lancer les commandes | [Vérification](IA/VERIFICATION.md) |
| Installer et utiliser l'environnement local | [Docker](../docker/dev/README.md) |
| Préparer une base de test isolée | [Tests d'intégration](../src/tests/Integration/README.md) |
| Retrouver les limites transverses et pistes encore ouvertes | [Travaux restants](IA/TRAVAUX_RESTANTS.md) |
| Construire et signer le ZIP de production d’une extension | [Build des extensions](../build_extensions/README.md) |
| Conception et lots du serveur TyCatalog et du client Core de catalogues | [Chantier TyCatalog](IA/CurrentWorks/TYCATALOG.md) |
| Concevoir le moteur d'automatisation, le langage de scénarios et son registre d'actions | [Chantier Automatisation](IA/CurrentWorks/AUTOMATION.md) |
| Concevoir la file de jobs différés, son worker et ses déclencheurs | [Chantier Job Queue](IA/CurrentWorks/JOB_QUEUE.md) |
| Concevoir le journal d’événements et les notifications utilisateur | [Chantier Journal et notifications](IA/CurrentWorks/EVENT_LOG_NOTIFICATIONS.md) |
| Organiser les branches, la publication et les workflows GitHub Actions | [Chantier branches et CI](IA/CurrentWorks/BRANCHES_ET_CI.md) |
| Préparer une mise en production minimale et la rotation des secrets | [Production Automatisation](AUTOMATION_PROD.md) |
| Connecter un compte de service Google Sheets et utiliser les actions de classeur | [Google Sheets](GOOGLE_SHEETS.md) |
| Gérer des événements Google Agenda avec le même compte de service | [Google Agenda](GOOGLE_CALENDAR.md) |
| Écrire des logs, régler leur rotation et les consulter avec ROOT | [Journaux applicatifs](LOGGING.md), [Chantier Journaux](IA/CurrentWorks/LOGS.md) |
| Fabriquer le ZIP autonome de production | [Fabrication](../build/README.md) |
| Publier un ZIP signé et déployer le serveur PHP de distribution | [Serveur de distribution](../update_server/README.md), [Publication locale](../build/README.md#publication-séparée) |
| Consulter les mises à jour depuis Core, télécharger et préparer un ZIP, obtenir le lien secret vers updater et simuler une source en DEV | [Consultation et téléchargement des mises à jour](SYSTEM_UPDATES.md) |
| Bibliothèque autonome de sauvegarde et restauration MariaDB | [Sauvegarde MariaDB](MARIADB_BACKUP.md) |
| Conception et travaux restants du moteur de mise à jour sur mutualisé | [Chantier de mise à jour](IA/CurrentWorks/MISES_A_JOUR_SYSTEME.md) |

## Parcours d'une évolution

1. Identifier la ressource, son Space réel et les droits nécessaires.
2. Suivre le parcours contrôleur → WF → helper décrit dans l'architecture.
3. Chercher les services, types de champs et composants partagés avant d'en créer.
4. Pour Vue, réutiliser le store métier, les modales et les raccourcis communs.
5. Prévoir les [logs utiles au diagnostic](LOGGING.md#prévoir-les-logs-pendant-le-développement),
   avec des codes stables, les causes internes et aucun contenu sensible.
6. Effectuer les contrôles adaptés, puis mettre à jour le contrat concerné.

## Entretenir la documentation

[`version.md`](../version.md) est destiné aux utilisateurs finaux : y noter
seulement les nouveautés, changements, correctifs, limites et actions qui ont
une conséquence concrète pour eux. Ne pas y détailler l'implémentation ni y
consigner systématiquement chaque évolution technique ; placer ces contrats
dans les guides concernés. Le fichier [`version.json`](../version.json) porte
le numéro et les compatibilités. Le build conserve les métadonnées et les
notes avec le ZIP ; l’historique reste dans Git et les releases publiées.

Conserver dans chaque guide le contrat actuel, les exemples réutilisables, les
raisons techniques encore utiles et les limites réelles. Remplacer les passages
dépassés au lieu d'ajouter une nouvelle étape datée à leur suite. Les noms de
migration et les restrictions de compatibilité restent lorsqu'ils expliquent
le fonctionnement d'une installation existante.

`IA/ETAT_DU_PROJET.md` sert de carte courte ; `IA/VERIFICATION.md` décrit les
commandes et les garde-fous. Ils ne recopient pas les guides et n'accumulent
pas les résultats de chaque intervention. Les résultats du travail courant
sont rapportés dans la réponse ou la PR ; Git conserve l'historique.

Une décision durable se documente près du contrat qu'elle explique. Une idée
encore ouverte reste identifiée comme telle dans les travaux restants.
[Les notes de Yann](TODO_NOTES_YANN.md) conservent leur rôle de notes personnelles.
