# Preuves du lot 03

Révision des preuves initiales : `1aff26d92a78bccc5d66b067f13dd7614f58e6bc`, analyse locale seule.
Voir [le rapport](../../Reports/LOT-03.md). Les identifiants, hôtes, adresses
IP et sessions des sondes sont factices ; aucun secret de configuration chargé.

| Fichier | Portée |
| --- | --- |
| [Fixes/README.md](Fixes/README.md) | Base `50cdf55`, corrections autorisées SEC-008/011/012, tests et empreintes courants ; distincts des sondes initiales. |
| [contexte.json](contexte.json) | Périmètre, autorisation, hypothèse de mutualisé, environnement et limites. |
| [sources.json](sources.json) | Empreintes des fichiers publics examinés ; distingue lecture ciblée et métadonnées de déclarations seulement. |
| [extract-routes.php](extract-routes.php) | Extraction par réflexion des attributs, sans instancier Application, contrôleurs ou attributs ; seul l'autoload est chargé. |
| [routes-before.csv](routes-before.csv) | Instantané du lot 01 avant actualisation : 308 déclarations. |
| [routes-delta.json](routes-delta.json), [inventaire courant](../../ROUTES.csv) | Un retrait et huit ajouts, dont cron et maintenance/relance Automation ; 315 déclarations, 164 candidates. |
| [phpunit.xml](phpunit.xml), [phpunit-result.txt](phpunit-result.txt) | 111 tests existants/327 assertions ; configuration reproductible et résultat de l'exécution isolée. |
| [HttpAuditTest.php](HttpAuditTest.php), [probes-result.txt](probes-result.txt), [probes.json](probes.json) | Six sondes/24 assertions ; attendent certains comportements défaillants de la révision auditée. Ce ne sont pas les tests d'un correctif. |

## Rejouer les preuves initiales

Les commandes de sondes ci-dessous concernent exclusivement la révision
`1aff26d92a78bccc5d66b067f13dd7614f58e6bc` dans un checkout isolé.
SEC-008/011/012 ont été corrigées depuis : leurs anciennes assertions de défaut
ne doivent plus réussir sur le code corrigé. Les fichiers initiaux et leurs
empreintes sont conservés. Pour valider l'état courant, utiliser les
[preuves et commandes des correctifs](Fixes/README.md).


Depuis la racine, avec le vendor déjà installé pour cette révision :

```bash
php src/releases/vdev/vendor/bin/phpunit --configuration Docs/IA/Security/Evidence/LOT-03/phpunit.xml --do-not-record-test-run-history --no-progress
php src/releases/vdev/vendor/bin/phpunit --no-configuration --bootstrap src/tests/bootstrap.php --do-not-record-test-run-history --no-progress Docs/IA/Security/Evidence/LOT-03/HttpAuditTest.php
php Docs/IA/Security/Evidence/LOT-03/extract-routes.php
```

Pour exporter à nouveau le JSON des sondes, définir `TY_SECURITY_OUTPUT` vers
un fichier temporaire. Les sondes utilisent sessions mémoire et doublures SQL,
journaux éventuels de fixture sous /tmp ; elles n'appellent jamais
`Application::initialise()`. La configuration héritée est celle des tests,
pas `configuration.php`. Le résultat de l'extraction va sur stdout ; le script
ne modifie pas lui-même l'inventaire.

Les en-têtes d'attaque ont été simulés dans les objets/superglobales PHP.
Aucun serveur web, navigateur ou hôte de production n'a été interrogé. La
réponse Location observée n'est pas une preuve que le serveur réel laissera
passer le chemin jusqu'à PHP. SameSite, proxy et transport doivent être
confirmés séparément sur une instance isolée validée par Yann.
