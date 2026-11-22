# Mise en production minimale de l’automatisation

Cette liste est le minimum pour exploiter l’automatisation en production. Elle
ne remplace pas l’audit de sécurité formel ni les essais de charge.

## 1. Code, base et migrations

- [ ] Déployer la release avec `plg_automation`, le CRON core, la maintenance
      et les actions Google.
- [ ] Exécuter les migrations de l’extension, dont `Mig_1_0_1` : colonne
      `target_scenario_key` et permission `plg_automation__ADMIN` pour ROOT.
- [ ] Vérifier que le rôle ROOT possède `plg_automation__ADMIN`.
- [ ] Vérifier que l’ancienne route `/automation/cron` n’existe plus ; la route
      canonique est `GET/POST /cron`.
- [ ] Vérifier `composer install --no-dev`, les assets Vite et le ZIP de
      release.
- [ ] Tester une restauration de base, pas seulement une sauvegarde.

## 2. Configuration de l’installation

Dans `configuration.php`, hors dépôt :

- [ ] `dev = false`, `debug = false`.
- [ ] `secret` long, stable et sauvegardé. Le changer rend illisibles les
      secrets chiffrés (Google, notifications).
- [ ] `cron_token` long et aléatoire. Vide signifie route `/cron` en `503`.
- [ ] Vérifier que `core_extensions_enabled['plg_automation']` n’est pas à
      `false` (active par défaut).
- [ ] Base SQL, `log_path`, médias et HTTPS corrects.
- [ ] Crontab et superviseur configurés avec le même `cron_token`.

## 3. Permissions

- [ ] ROOT possède `ADMIN` (migration) et les permissions d’exécution.
- [ ] Attribuer `VIEW`, `EDIT`, `PUBLISH`, `RUN`, `RUN_MANAGE`,
      `RUN_AS_AUTHOR` selon les rôles.
- [ ] `ADMIN` reste réservée à ROOT : elle n’est pas exposée à l’édition
      ordinaire des rôles.
- [ ] Vérifier que les rôles non-ROOT qui éditaient la configuration avec
      `PUBLISH` ont explicitement `ADMIN` si nécessaire.
- [ ] Un scénario `author` exige `RUN_AS_AUTHOR` à la publication.
- [ ] Un scénario qui utilise les actions Google exige `PUBLISH` à la
      publication.

## 4. Secrets et rotation

Aucune rotation automatique n’est prévue. Les opérations ci-dessous sont
manuelles ; le bouton webhook existant reste la seule aide intégrée.

| Secret | Emplacement | Rotation minimale | Limite actuelle |
| --- | --- | --- | --- |
| Jeton webhook bearer/query | hash dans `automation_triggers.secret_hash` | Éditeur → « Régénérer le secret » ; mettre à jour l’appelant. | L’ancien jeton est invalidé immédiatement. Pas de période de grâce. |
| Secret HMAC webhook | chiffré dans `automation_triggers.config` | Éditeur → régénérer ; mettre à jour la signature de l’appelant. | Pas de double secret pendant la bascule. |
| Jeton CRON | `cron_token` dans `configuration.php` | Générer un nouveau jeton, mettre à jour `configuration.php` et la crontab, recharger PHP. | Pas d’UI ; prévoir une courte fenêtre de `401`. |
| Clé Google | JSON du compte de service, chiffré par Space | Créer une nouvelle clé dans Google Cloud, coller le nouveau JSON, vérifier, puis supprimer l’ancienne clé. | Plusieurs clés Google peuvent coexister : rotation sans coupure possible. |
| Jetons de notification | configuration de l’extension | Remplacer dans le formulaire ; mettre à jour le fournisseur. | Pas de période de grâce. |
| `secret` d’installation | `configuration.php` | À ne pas faire en v1. | Une rotation exigerait de rechiffrer tous les secrets ; non implémenté. |

Règles :

- [ ] Aucun secret dans le dépôt, un scénario, un journal ou une URL publique.
- [ ] Le jeton CRON en query apparaît dans les journaux Infomaniak : accès
      restreint, jeton dédié, rotation si exposition.
- [ ] La clé Google est limitée aux classeurs et agendas nécessaires.
- [ ] Sauvegarder les secrets chiffrés et le `secret` ensemble.

## 5. CRON

- [ ] Une ligne de crontab appelle `GET /cron?cron_token=…` ou `POST /cron`
      **chaque minute**.
- [ ] Tester un mauvais jeton (`401`), un jeton absent (`503` si non configuré)
      et un tick réel.
- [ ] Vérifier les hooks exécutés et le bilan dans la réponse.
- [ ] Surveiller `AUTOMATION_CRON_BUDGET` : au-delà de 45 s ou 100 scénarios,
      les créneaux sont différés et ne sont pas rattrapés.
- [ ] Mettre au minimum une alerte Telegram quotidienne pour vérifier qu’un
      CRON quotidien tourne.
- [ ] Savoir qu’il n’y a pas de heartbeat : un CRON arrêté n’est pas visible
      dans l’UI.
- [ ] Vérifier le fuseau du Space et le changement d’heure.

## 6. Webhook

- [ ] URL HTTPS `/automation/webhook/{scenarioId}`.
- [ ] Scénario actif, publié, déclencheur activé.
- [ ] Choisir bearer, query ou HMAC selon le fournisseur.
- [ ] Générer le secret et le conserver une seule fois.
- [ ] Tester `401`, `404`, `409`, `413`, `422`, `429`.
- [ ] Vérifier la limite de 256 Kio et le quota de 120 appels/minute.
- [ ] Préférer `accepted` en production ; `result` expose la sortie et attend
      le run.
- [ ] Garder les scénarios courts : le webhook attend le drain synchrone.
- [ ] Décider du comportement en cas de retry fournisseur : il n’y a pas
      d’idempotence, un retry crée un nouvel événement et un nouveau run.
- [ ] Tester le vrai payload HelloAsso et son éventuel retry.

## 7. Google Sheets et Agenda

- [ ] Activer les API Sheets et Calendar.
- [ ] Partager les classeurs et agendas avec `client_email`, en moindre
      privilège.
- [ ] Coller le JSON par Space dans **Google API (Sheets et Agenda)**.
- [ ] Vérifier `ADMIN` pour la configuration et `PUBLISH` pour les scénarios.
- [ ] Tester `listSheets`, `appendRow`, `insertRow`, `readSheet`.
- [ ] Tester `listEvents`, `createEvent`, `updateEvent`.
- [ ] Vérifier les dates jour entier (fin exclusive), les fuseaux et les
      invitations Calendar.
- [ ] Prévoir la rotation de la clé Google et les quotas API.

## 8. Maintenance et données

- [ ] Définir qui vérifie les événements `pending`/`processing` et les runs
      bloqués, et à quelle fréquence.
- [ ] Définir la rétention : 30 jours événements, 90 jours runs par défaut.
- [ ] Sauvegarder avant tout nettoyage : il est irréversible.
- [ ] Vérifier que les runs conservés peuvent perdre `event_id` après purge des
      événements.
- [ ] Relancer les runs en échec avec la version publiée actuelle, jamais le
      brouillon.
- [ ] Vérifier le volume des tables `automation_events`, `automation_runs` et
      `automation_run_steps`.
- [ ] Vérifier l’accès aux entrées/sorties de runs : elles peuvent contenir des
      données personnelles.

## 9. Notifications

- [ ] Configurer au moins un canal d’échec.
- [ ] Tester un échec de scénario.
- [ ] Configurer l’alerte quotidienne du CRON.
- [ ] Vérifier que les échecs ne sont pas silencieux.

## 10. Sécurité avant go-live

- [ ] Audit sécurité du lot 12 et audit Google Sheets/Agenda.
- [ ] Vérifier les exemptions CSRF/WAF des routes webhook et CRON.
- [ ] Vérifier le jeton CRON en query/Basic et les journaux d’accès.
- [ ] Vérifier la sortie `result`, le rejeu HMAC et l’absence d’idempotence.
- [ ] Vérifier l’ACL cross-Space et l’accès `ADMIN`.
- [ ] Vérifier qu’aucun secret ni message Google brut n’est journalisé.
- [ ] Vérifier les quotas, les timeouts et les effets partiels des actions.

## 11. Validation finale

- [ ] Migrer une copie d’une base existante, puis tester les migrations.
- [ ] Tester webhook, CRON, planification, Google, maintenance et notifications
      sur une base isolée.
- [ ] Tester une panne Google, une panne SQL et un scénario lent.
- [ ] Tester la restauration de sauvegarde.
- [ ] Faire un essai visuel de l’éditeur et de la maintenance.
- [ ] Faire l’essai réel HelloAsso.

## 12. Rollback

- [ ] Désactiver les scénarios ou le déclencheur webhook avant de revenir en
      arrière.
- [ ] Revenir à la release précédente ; conserver les données.
- [ ] Si l’ancienne release n’a pas `/cron`, mettre la crontab en pause ou
      l’adapter.
- [ ] Révoquer les jetons webhook et CRON exposés pendant l’incident.
- [ ] Ne pas supprimer les tables `automation_*` : les runs et l’historique
      restent utiles au diagnostic.

## 13. Routine d’exploitation minimale

- Quotidien : alerte Telegram du CRON, vérifier les runs échoués.
- Hebdomadaire : maintenance (événements bloqués, runs bloqués), logs.
- Mensuel : nettoyage de rétention après sauvegarde, vérification des droits.
- À chaque incident : rotation des jetons exposés, revue des logs.
