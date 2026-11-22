# Branches, publication et automatisation

## Statut

Cette note fige l'organisation des branches et des publications retenue avec
Yann. La publication du système passe par une **approbation humaine sur le
serveur de distribution** ; l'automatisation des tests et de l'envoi est un
chantier par lots. Les contrats techniques détaillés restent dans
[la fabrication](../../../build/README.md), [le serveur de distribution](../../../update_server/README.md)
et [les mises à jour](../../SYSTEM_UPDATES.md) ; cette note conserve les
décisions, l'ordre des lots et les limites ouvertes.

## Branches

| Branche | Rôle |
| --- | --- |
| `dev_X_Y` | Développement. C'est ici qu'on incrémente `version.json` et qu'on rédige `version.md` avant une publication. |
| `prod_X_Y` | Production. Ne reçoit que des merges de release ; le suivi chaîne `dev_1_1` → `prod_1_1` puis `dev_1_2`. |
| `security_X` | Analyse de sécurité d'une version majeure. Ses commits ne reviennent **pas** dans `dev_X_Y` : elle porte des constats, pas du code. |
| `dev_ext_<id>_A_B` | Extension : **source de vérité** de l'extension. |
| `prod_ext_<id>_A_B` | Extension : instantané de publication, sans développement propre. |

**Chaque merge de `dev_X_Y` vers `prod_X_Y` incrémente `version.json`** d'un
correctif (`X_Y_Z+1`) et reprend `version.md`. Un merge qui ne change pas la
version est refusé par la lecture de la version dans le workflow.

Quand un cœur sort, `prod_X_Y` → `dev_ext_*` relève aussi `cms_min` /
`cms_max_exclusive` : la compatibilité descendante attendue d'une extension
se déclare dans son `version.json`, pas dans le cœur.

## Règles de sécurité de la publication

La question posée n'est pas « qui a poussé ? » mais « **le contenu publié est-il
celui que j'ai approuvé ?** ». Trois couches, de la moins coûteuse à la plus
forte :

1. **L'approbation sur le serveur** (en place). Un envoi reste *en attente* ;
   le catalogue par défaut ne le sert pas. Un push inattendu au pire crée une
   proposition visible dans le mini-UI, jamais une version distribuée.
2. **L'environnement GitHub `production`** : seul le compte de Yann est
   reviewer ; le job de release ne démarre pas sans son approbation.
3. **Les secrets de publication** (`release-1`, token) restent dans cet
   environnement, jamais dans le dépôt, jamais dans un artefact.

Le sceau Ed25519 sur l'artefact, un tag signé et une clé locale ont été
écartés : ils dupliquent l'approbation 1 sans ajouter de vérification que le
serveur appliquerait.

## Cycles d'état

**Système** (`update_server`) : `en attente → bêta | prod`, `bêta → prod`.
Retour en arrière refusé. « Refuser » supprime physiquement une proposition.

**Extension** (TyCatalog) : `draft` (affiché « en attente ») → `published`
avec `channel ∈ {beta, release}` → `withdrawn`. Bêta → release seulement.

**Choix du canal côté installation** : `update_channel` vaut `prod` par défaut ;
`beta` fait demander le canal bêta au serveur et accepter ses releases. Le
client écarte en plus toute bêta reçue par erreur sur le canal prod (défense en
profondeur). Le réglage est local, jamais activable par une URL.

## Lots

| Lot | Contenu | État |
| --- | --- | --- |
| A | Serveur : états, approbation, mini-UI, compte admin | Livré |
| B | `publish.php` annonce une proposition en attente | Livré |
| C | CI cœur : contrôles et release sur `push prod_*` | Livré |
| A2 | Protocole : `schema_version: 2` avec `channel`, lecture v1 | Livré |
| A3 | Client : réglage `update_channel`, filtre bêta | Livré |
| F | Notifications Telegram / Slack / Discord (webhooks) | Livré |
| G | Tickets GitHub : analyse read-only, correction après étiquette | À faire |
| H | `security_X` : analyse différentielle avec SHA analysé | À faire |
| D | CI extensions : build puis dépôt catalogue | À faire |
| E | TyCatalog : validation par canal | À faire |
| I | Guides et index à jour | En cours |
| J | Scripts partagés dans `cicd/` | Livré |

## Workflows livrés

- [`.github/workflows/checks.yml`](../../../.github/workflows/checks.yml) :
  push sur **`dev_*` seulement**, hors commits purement documentaires
  (`paths-ignore`). **Un seul job** sans base SQL : PHP `unit`, tests Node et
  suite du serveur de distribution. Aucun secret. Pas de `pull_request` :
  le développement se fait sur `dev_*` et le push suffit. Une branche `prod_*`
  ne relance pas ces contrôles : elle ne reçoit que des merges déjà vérifiés,
  et son push déclenche la release.
- [`.github/workflows/release.yml`](../../../.github/workflows/release.yml) :
  `push` sur `prod_*`. Construit dans l'image isolée, puis envoie la
  proposition avec les secrets de l'environnement `production`.

Les actions sont épinglées par empreinte de commit ; leur mise à jour est
explicite. Le choix d'un job unique et l'absence de `pull_request` visent
principalement à ménager les minutes de runner : à qualité de contrôle égale,
un provisionnement vaut mieux que trois.

## Scripts du dossier `cicd/`

La logique des workflows vit dans [`cicd/`](../../../cicd/README.md), pas dans
le YAML : `prepare-publication.php` écrit la configuration privée et la clé
hors du dépôt, `verify-artifacts.php` contrôle les trois compagnons du build.
Deux règles s'y imposent : aucun secret en argument de commande, aucune
écriture sous le dépôt. Les workflows n'ont plus de code PHP en ligne. Les workflows ne s'arrêtent sur aucun test d'intégration MySQL :
la suite `integration` exige une base dédiée et un compte de test.

## Protocole de distribution, schéma 2

Le manifeste signé porte `schema_version: 2` et un `channel` (`prod` ou
`beta`). Le build écrit **toujours `prod`** : le canal est décidé à
l'approbation sur le serveur, jamais par le poste qui publie. Le serveur sert
la prod par défaut et n'ajoute la bêta que si le client le demande
(`?action=releases&channel=beta`).

Un manifeste `schema_version: 1` reste **lu et traité comme prod**. C'est
indispensable : les installations déjà en service (dont la production de
`tests.yanntassy.fr`, en 1.1.5) refusent toute autre version de schéma. Elles
ne pourront lire la v2 qu'après avoir installé une release dont le client
accepte les deux — c'est l'objet du lot A3. Tant que ce lot n'est pas publié,
une release v2 ne doit pas être la seule proposée.

## Notifications des workflows

`cicd/notify.php`, appelé en fin de workflow, envoie vers Telegram, Slack ou
Discord selon les secrets présents. Politique retenue : silence sur un `push`
en `dev_*` (GitHub prévient déjà par e-mail), échec **et** succès sur `prod_*`,
échec et succès pour les autres événements (tickets, sécurité). Aucun canal
configuré : le script ne fait rien. Une notification manquée ne fait jamais
échouer le workflow.

Le serveur de distribution ne notifie pas : son propriétaire le gère seul.

## Limites ouvertes

- Le test `src/ria/tests/pageMediaComponents.test.js` échoue quand
  `security_sources.media` est renseigné : le CSP produit duplique l'origine
  (`media-src https://site.test https://site.test`). Le test passe sans source
  configurée ; le cas réel reste à trancher (dédoublonner ou assouplir le test).
- Le canal bêta n'est pas encore demandable par le client (A2/A3).
- Les extensions ne disposent pas encore de CI ni de validation de canal.
- Les notifications n'envoient rien tant qu'aucun webhook n'est configuré.
