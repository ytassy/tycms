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

Le workflow `Contrôles` ne s'exécute que sur `dev_*` : une branche de production
ne reçoit que des merges déjà vérifiés, et son push lance la release.

`NOTIFY_STATUS` porte `${{ job.status }}`. Un canal mal configuré est signalé
sur la sortie d'erreur, sans recopier l'URL ni le jeton, et **ne fait jamais
échouer le workflow**. `NOTIFY=off` désactive tout envoi sans retirer les
secrets.

Les actions de GitHub sont épinglées par empreinte de commit, avec leur version
en commentaire. Une mise à jour change l'empreinte **et** le commentaire ; un
changement de version majeure se vérifie en lisant les notes de l'action.

## Analyse de tickets (DSH)

`dsh/prepare-profile.php` écrit une surcouche de profil hors du dépôt : le
fournisseur et le modèle par défaut viennent de `DSH_PROVIDER` et `DSH_MODEL`
(variables de dépôt GitHub `vars.DSH_PROVIDER` / `vars.DSH_MODEL`, valeurs par
défaut `openrouter` et `deepseek/deepseek-v4.1-flash-1017`).

`dsh/prompt.php` compose la tâche : une invite versionnée
(`dsh/prompts/triage.md`, `dsh/prompts/fix.md`), puis le texte du ticket encadré
par `<<<TICKET>>>`. **Les balises sont neutralisées dans le texte reçu** : le
motif ignore la casse, les espaces **et le nombre de chevrons**, de sorte que
`<FIN_TICKET>`, `<<FIN_TICKET>>`, `<<<FIN_TICKET>>>`, `<<<<FIN_TICKET>>>>`,
`<<<fin_ticket>>>` ou `<<< FIN_TICKET >>` sont tous encodés. Les chevrons
deviennent des entités HTML : le texte reste lisible, aucune balise ne subsiste,
et le ticket ne peut pas refermer son bloc ni en ouvrir un faux.

L'analyse tourne en `--permission-mode read-only` avec `--approval-policy never` :
elle ne peut rien modifier, même si le ticket le demande. La correction tourne
en `workspace-write`, sans accès aux secrets de publication.

### Authentification OpenRouter

La clé vient du secret `OPENROUTER_API_KEY` et se résout par la référence
`apiKeyEnv` du profil généré. Elle doit **avoir le format attendu par
OpenRouter** (`sk-or-v1-…`) : une valeur arbitraire est ignorée sans erreur de
configuration, et OpenRouter répond alors `401 Missing Authentication header`.
Ce message ne signale donc pas un défaut de transmission ; une clé bien formée
mais inconnue reçoit `401 User not found`.

Vérifié en local sur DSH 0.2.2-rc.5, avec une vraie clé :

| Contrôle | Résultat |
| --- | --- |
| Analyse d'un ticket factice | code 0, **28 s**, 81 événements, réponse structurée conforme |
| Exactitude des références | fichiers et numéros de ligne cités exacts (bouton, getter `isDirty`) |
| Écriture en mode `read-only` | refusée par le bac à sable, aucune approbation possible, aucun fichier créé |

Le coût d'une analyse est donc de l'ordre de la demi-minute, sans appel d'outil
d'écriture : le délai de 20 minutes du workflow de tickets est largement suffisant.
Le second workflow, qui modifie et vérifie le code, demande davantage.

L'installation de DSH est mise en cache (`actions/cache`) sous la clé
`dsh-0.2.2-rc.5` : les archives npm et les modules globaux sont restaurés avant
`npm install --global`, qui reste exécuté pour valider l'installation. Changer
de version dans le workflow **change la clé** et provoque une installation
complète ; c'est voulu, pour ne jamais réutiliser un binaire périmé.

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
