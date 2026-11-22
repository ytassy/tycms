# Scripts d'intégration continue

Ce dossier contient la logique des workflows de [`.github/workflows`](../.github/workflows) :
les workflows restent courts et lisibles, la logique testable vit ici.

## Règles

- **Aucun secret en argument de commande.** Les valeurs viennent de
  l'environnement du job ; la ligne de commande est visible dans la liste des
  processus.
- **Écriture hors du dépôt uniquement.** Tout fichier dérivé d'un secret est
  produit dans le dossier désigné par `CONFIG_DIR`, jamais sous le dépôt.
  `build/publish.php` refuse de son côté une configuration située dans le dépôt.
- **Aucune logique métier.** Ces scripts orchestrent : ils vérifient, déplacent
  et configurent. La fabrication reste dans `build/`, la publication dans
  `build/publish.php`.
- Sortie d'erreur concise, sans trace interne ni valeur secrète ; code de
  sortie non nul en cas de refus.

## Scripts

| Script | Rôle | Entrées | Sortie |
| --- | --- | --- | --- |
| `prepare-publication.php` | Écrire la configuration privée et la clé de signature pour `build/publish.php`. | Variables `CONFIG_DIR`, `TYCMS_PUBLISH_SERVER_URL`, `TYCMS_PUBLISH_TOKEN`, `TYCMS_PUBLISH_KEY_ID`, `TYCMS_RELEASE_SECRET`. | `$CONFIG_DIR/publication.php` et `$CONFIG_DIR/release.secret` en `0600`. |
| `verify-artifacts.php` | Vérifier la présence des trois artefacts d'une version après le build. | `version.json` ; dossier facultatif en argument (défaut `build/dist`). `GITHUB_OUTPUT` facultatif. | Sorties `version` et `string` ; aucun fichier modifié. |
| `notify.php` | Prévenir de la fin d'un workflow (échec, et succès sur `prod_*`). | `TELEGRAM_NOTIFY`, `SLACK_WEBHOOK`, `DISCORD_WEBHOOK`, `NOTIFY_STATUS`, `NOTIFY_VERSION`, `NOTIFY_RUN_URL`, événements `GITHUB_*`. | Envois optionnels ; code de sortie toujours 0. |

## Notifications

Trois canaux optionnels, activés par la seule présence de leur variable :
`TELEGRAM_NOTIFY` (`token:chat_id`), `SLACK_WEBHOOK`, `DISCORD_WEBHOOK`. Aucun
secret dans un fichier du dépôt ; ils viennent des secrets GitHub du workflow.

La politique évite le bruit :

| Déclencheur | Envoi |
| --- | --- |
| `push` sur `dev_*` | jamais (GitHub prévient déjà par e-mail) |
| `push` sur `prod_*` | échec **et** succès (la release est alors en attente) |
| autre événement (ticket, sécurité) | échec et succès |

`NOTIFY_STATUS` porte `${{ job.status }}`. Un canal mal configuré est signalé
sur la sortie d'erreur, sans recopier l'URL ni le jeton, et **ne fait jamais
échouer le workflow**. `NOTIFY=off` désactive tout envoi sans retirer les
secrets.

Les adresses de webhook contiennent un secret : elles ne doivent jamais
apparaître dans un message de commit, un journal ou une capture d'écran.

`prepare-publication.php` refuse tout argument, un dossier de configuration
situé dans le dépôt (sans créer de dossier au passage), une variable absente.
`verify-artifacts.php` recalcule le suffixe depuis `version` comme le build le
fait et signale seulement un `version_string` désaccordé.

## Utilisation locale

Les scripts se lancent comme en CI, depuis la racine du dépôt :

```bash
GITHUB_OUTPUT=/dev/stdout php cicd/verify-artifacts.php
```

```bash
export CONFIG_DIR="$(mktemp -d)"
export TYCMS_PUBLISH_SERVER_URL="https://update.example.org/index.php"
export TYCMS_PUBLISH_TOKEN="…"
export TYCMS_PUBLISH_KEY_ID="release-1"
export TYCMS_RELEASE_SECRET="$(cat ~/.config/tycms/release-1.secret)"
php cicd/prepare-publication.php
```

Ne jamais écrire une clé réelle dans un fichier du dépôt. Pour un essai de bout
en bout, utiliser une clé et un token provisoires selon
[la validation du déploiement](../update_server/README.md#validation-du-déploiement-avant-les-clés-définitives).
