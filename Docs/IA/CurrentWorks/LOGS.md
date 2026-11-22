# Journaux applicatifs : conception et réalisation

## État du chantier

Le moteur de journalisation est dans `framework/Logging` et la consultation
ROOT dans Core. Le contrat livré, les exemples d’utilisation et les limites
sont décrits dans [Journaux applicatifs](../../LOGGING.md), source de vérité
technique de cette fonctionnalité.

## Choix retenus

- Trois niveaux : INFO, WARNING et ERROR, sans duplication des entrées entre
  niveaux, avec des fichiers d’archives roulants.
- Réglages indépendants de durée et de volume dans `configuration.php` :
  90 jours et 50 Mo par niveau par défaut, actif et archives compris.
- Réutilisation de `Configuration.log_path`, dossier privé hors racine HTTP.
- Consultation, filtres, détail et copie d’une entrée réservés à ROOT sur le
  Space racine 1 ; aucun export ni suppression manuelle.
- Service du Framework indépendant de SQL et des extensions ; accès typé et
  méthodes simples dans `BaseApplicationItem`. Interface et autorisation dans Core.
- Événement INFO à chaque connexion réussie locale ou SSO, après commit de
  la route, sans nouvelle entrée pour une session simplement rechargée.
- Capture des exceptions internes, dont `getPrevious()` et la cause propre
  aux `ExceptionResponse`, sans arguments ni message externe brut. Codes
  contrôlés pour les raisons métier SSO/OAuth et contexte minimal pour le WAF.
- Rotation et purge sous verrou borné. Une panne de journalisation ne bloque
  pas le métier ; un signal minimal de secours reste dans les logs PHP.

## Organisation du code

| Élément | Responsabilité |
| --- | --- |
| `framework/Logging/Logger` | Écriture, capture des causes, dédoublonnage et secours. |
| `framework/Logging/LogRecord` | Format, projection des données autorisées et assainissement des traces. |
| `framework/Logging/LogStore` | Configuration, stockage privé, verrou, quotas, rotation et purge. |
| `framework/Logging/LogReader` | Filtres, lecture inverse bornée, fusion des niveaux et curseur signé. |
| `Core/WF/WFLogs` et `Core/Helper/LogReaderHelper` | Autorisation ROOT, validation et délégation au lecteur. |
| `Core/Controllers/Admin/AdminLogsController` | Frontière HTTP ; lecture POST avec CSRF pour garder le curseur hors URL. |
| `ria/src/stores/admin_logs.js` et `components/administrator/logs/` | Une page de résultats en mémoire, état du stockage et copie avec le composant commun. |

## Limites à conserver visibles

Le plafond de volume ou de segments peut réduire les 90 jours d’historique.
Une installation inactive ne purge rien sans nouvelle requête. Le disque
peut être indisponible et des événements peuvent être perdus ; le signal de
secours ne constitue pas un second journal consultable dans l’écran.
Une panne SQL peut empêcher l’accès ROOT tout en laissant l’écriture possible.
Les archives ne sont pas un audit infalsifiable. Les logs serveur, les
sauvegardes et journaux de migrations restent distincts.

Les parcours ROOT visuels et les droits du dossier sur l’hébergement cible
restent à exercer avec Yann. L’audit des actions administratives et les
destinations externes demandent un périmètre distinct avant réalisation.
