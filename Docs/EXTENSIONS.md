# Développer une extension

Les extensions installées sont partagées entre les releases du CMS. Leurs
classes PHP restent sous `src/extensions/plugins/<Nom>`, `modules/<Nom>` ou
`templates/<Nom>`, avec les namespaces et points d’entrée décrits dans
[l’architecture](ARCHITECTURE.md#extensions-système-et-extensions-installées).
Le nom technique du dossier est en PascalCase (`Lms`, identifiant `plg_lms`).

## Dépôt et organisation

Chaque développeur peut créer son propre dépôt à partir du dépôt TyCMS, avec
un historique commun. `origin` désigne son dépôt et `upstream` le dépôt
officiel. Il fusionne un tag publié ou la branche de production depuis
`upstream` pour actualiser le CMS. Garder les changements métier dans les
extensions réduit les conflits. Aucun accès d’écriture au dépôt officiel
n’est nécessaire.

```text
src/extensions/plugins/Lms/
  Plugin.php
  version.json
  version.md
  frontend/
    package.json
    package-lock.json
    vite.config.mjs
    src/main.js
    src/LmsHome.vue

src/public/assets/plg_lms/        # médias statiques de l’extension
  .vite/manifest.json
  build/                        # bundles générés, hors Git
    index.js
    main-<hash>.css
```

Une extension PHP seule conserve `getFrontendEntry(): null`. Une extension
Vue redéfinit cette méthode avec la clé de son entrée dans le manifeste Vite,
actuellement `src/main.js`. Cette entrée doit exporter
`registerFrontend(api)`, appelée et attendue par le Core avant tout montage.
Le squelette LMS fournit « Liste des cours » dans la catégorie LMS de
l’éditeur : un canevas Vue vide avec un repère visuel, sans paramètres ni
rendu public. Il ne crée encore ni table, ni permission métier, ni route.

## Socle Core TyCatalog

Le serveur de catalogue d’extensions repose sur TyCMS avec le plug-in système
`src/releases/vdev/core_extensions/TyCatalog/Plugin.php`, sous le namespace
`YTassy\Tycms\CoreExts\TyCatalog` et l’identifiant `plg_ty_catalog`.
Sa version suit `APP_VERSION`, comme les autres extensions Core.

Sans surcharge, il est chargé en DEV et désactivé en production. La clé
`Configuration.core_extensions_enabled['plg_ty_catalog']` accepte un booléen
explicite pour remplacer ce défaut dans les deux environnements.
Le socle déclare ses métadonnées, le schéma serveur (quatre tables), les
modèles et les permissions de catalogue. L’administration fournit la rubrique
**Catalogues TyCMS** lorsque l’extension est active : création et modification
des catalogues, références amies et clés de confiance ROOT. ROOT voit les
catalogues de tous les Spaces, peut éditer n’importe lequel et changer son Space
propriétaire depuis le formulaire, avec confirmation et journal. Les autres
comptes restent limités au Space courant. Les permissions sont globales au Space
propriétaire, sans délégation par catalogue. Les routes publiques, la publication
des versions signées et la distribution des archives sont fournies ; voir les
sections suivantes. La racine privée `ty_catalog_storage_root` est héritée de
`ConfigurationBase`, hors racine HTTP. Les décisions figées, le protocole et
les lots à réaliser figurent dans le [chantier TyCatalog](IA/CurrentWorks/TYCATALOG.md).
La licence optionnelle sera transmise par Core aux serveurs tiers ; sa gestion
côté TyCatalog est hors du périmètre initial.

## Publication dans TyCatalog

L’onglet Versions d’un catalogue accepte un dépôt local (ZIP, notes et
enveloppe de signature) ou une référence externe (URL du ZIP, URL des notes et
enveloppe). Le serveur vérifie l’enveloppe avec la clé publique du catalogue,
puis l’identité, la version, les notes et le ZIP par `ExtensionPackage`, placé
dans `framework/Extensions` et partagé avec le build. Aucun PHP déposé n’est
exécuté ; l’inspection du ZIP refuse liens, fichiers spéciaux, chemins
sortants, doublons et exécutables dans les assets.

Un dépôt crée ou corrige un brouillon. `EDIT` est nécessaire pour déposer et
`PUBLISH` pour publier, retirer ou réactiver. Une version publiée est immuable :
un nouveau contenu exige une nouvelle version. Le retrait ne libère pas le
numéro ; la réactivation re-vérifie les octets et la signature. Les paquets
locaux sont écrits sous `ty_catalog_storage_root` dans un dossier dérivé
`catalogs/<catalogue>/<extension>/<version>/<jeton>` ; une référence externe
conserve ses URL et ses empreintes signées, et ses octets sont contrôlés à la
réactivation. Les URL externes passent par un téléchargement HTTPS borné, sans
redirection et sans adresse interne.

Le dépôt et les transitions sont journalisés dans le canal `extensions` avec
les codes `TY_CATALOG_VERSION_DEPOSITED`, `TY_CATALOG_VERSION_PUBLISHED`,
`TY_CATALOG_VERSION_WITHDRAWN`, `TY_CATALOG_VERSION_REACTIVATED`, ainsi que
`TY_CATALOG_SIGNATURE_INVALID`, `TY_CATALOG_ARCHIVE_INVALID` et
`TY_CATALOG_REACTIVATION_REFUSED`.

### Rétention des versions et des ZIP

Chaque catalogue règle `keep_versions` et `keep_archives` (défaut 4, de 1 à
100). Après chaque publication et réactivation, et via **Nettoyer maintenant**,
chaque extension garde ses X versions les plus récentes, brouillons compris.
Au-delà, la ligne et ses fichiers locaux sont supprimés ; le numéro de version
est libéré. Parmi les versions conservées, les Y ZIP locaux les plus récents
sont gardés. Les autres archives locales sont supprimées et
`archive_removed_at` est renseigné ; métadonnées, notes et enveloppe restent en
base. Les références externes ne sont pas touchées. La dernière version publiée
et son ZIP local sont toujours conservés, même si un brouillon plus récent
occupe la fenêtre. Un échec de nettoyage est journalisé sans annuler la
publication.

### Publication distante par jeton

La publication peut aussi passer par un jeton Bearer, sans session
administrateur ni CSRF. ROOT crée et révoque le jeton depuis l’écran
**Catalogues TyCMS → Jetons** ; le secret est affiché une seule fois et stocké
sous forme de SHA-256. Il est limité à un catalogue, porte les droits `deposit`
et/ou `publish`, peut expirer et se révoque. Aucun accès CLI au serveur n’est
nécessaire, ce qui convient à un hébergement mutualisé.

Les routes `POST /tycms-catalog-api/v1/deposit`, `/reference`, `/publish`,
`/withdraw` et `/reactivate` appliquent les mêmes contrôles que
l’administration. En production, HTTPS est requis et le jeton n’est jamais
journalisé. Le client distant :

```bash
php build_extensions/tycatalog-publish.php
```

Copier `build_extensions/tycatalog-publish.sample.php` vers
`build_extensions/tycatalog-publish.local.php` (ignoré par Git) pour fixer
l’URL, le catalogue, le dossier `dist/` et le jeton. Appliquer `chmod 600` à ce
fichier. Sans argument, le client propose les actions et la liste des paquets.
Les options directes restent disponibles pour les scripts. Le jeton se lit dans
`TYCMS_TYCATALOG_TOKEN`, la clé `token` de la configuration locale ou l’option
`--token`. Le client refuse une URL non HTTPS hors localhost et ne suit pas les
redirections.

## Bibliothèque ESM et Import Map

Le build de chaque extension utilise Vite en mode bibliothèque ESM, avec son
propre lockfile. Réutiliser `src/tools/extension-vite.mjs` comme le fait LMS :
ce contrat externalise `vue`, `pinia`, `vue-i18n`, `vue-router` et
`@tycms/api`. Les bibliothèques privées de l’extension restent dans son build.
Les versions du compilateur Vue et de Vite sont verrouillées dans son projet ;
elles doivent rester compatibles avec le runtime fourni par le CMS.

MainTemplate demande les balises à `$this->coreFrontendAssets->renderTags()`.
Le helper construit une seule Import Map avant les modules : les runtimes
partagés proviennent des assets locaux du Core, `@tycms/api` de son entrée
publique et `@extensions/<identifiant>` de chaque extension déclarée.
L’installation d’une extension compilée ne nécessite aucun build du Core.
Le build conserve les médias statiques du dossier public et écrit uniquement
ses bundles dans `build/`, avec son manifeste sous `.vite/`. Les manifestes
sont obligatoires pour les frontends compilés ; leur absence
est signalée comme une erreur de déploiement, sans suppression silencieuse de
l’interface. Les CSS des dépendances statiques sont chargés avant ceux de
l’entrée. Les CSS des composants différés d’une bibliothèque sont aussi chargés au
rendu initial : le mode bibliothèque ne fournit pas le préchargement CSS
d’une application Vite. Leurs chunks JavaScript restent différés.

En DEV, Vite 7 transforme normalement les imports nus. Le plugin commun
`shared-esm.mjs` les résout donc vers des identifiants URL réservés
`https://esm.tycms.invalid/<module>`, que la même Import Map remappe vers les
fichiers locaux. Aucun appel réseau à ce domaine n’est prévu. L’optimiseur
des dépendances utilise le même contrat pour éviter d’embarquer une seconde
copie de Vue. En production, les bundles conservent les imports nus.
Les runtimes sont compilés en variantes `runtime/dev` et `runtime/prod` ;
ils sont indépendants des serveurs Vite et identiques pour tous les bundles
d’une page. Ne pas importer directement les paquets internes `@vue/*` ni les
fichiers source privés du Core depuis une extension.

Les extensions ne fournissent pas de fragments d’Import Map libres : le Core
conserve les noms réservés. Les chemins des manifestes, les fichiers absents
et les liens sortants sont contrôlés. La map est encodée en JSON protégé et
autorisée par un nonce CSP propre à la réponse, sans `unsafe-inline` pour les
scripts. Une template personnalisée doit rendre ce même contrat ; une réponse
qui définit sa propre CSP doit aussi y autoriser ce nonce.

## Accès aux services frontend du Core

`@tycms/api` expose :

- `registerRiaPage('lms/home', composant)` : page utilisable par `RiaView` ;
- `registerPageForm`, `registerPageCanvas`, `registerFormFieldComponent` et
  `registerPublicPageComponent` : registres décrits dans
  [Composants de page](PAGE_COMPONENTS.md#registres-vue-et-compilation) ;
- `registerExtensionConfigView('lms/config', composant)` : vue de
  configuration propre à l’extension, décrite ci-dessous ;
- `registerExtensionStore('useLmsStore', factory)` : factory suivant le contrat
  `get(vueApp, application)` des stores Core, sans remplacement d’un nom Core ;
- `useApplication()` : contexte de la racine courante, notamment API et CSRF,
  configuration, ACL, bus et factories de stores.

Le Core monte ces pages et composants avec ses mixins, directives, composants
globaux et factories de stores. En Options API, utiliser `this.stores`,
`this.api`, `this.conf` et les méthodes des mixins. En Composition API,
utiliser `useApplication()`. Voir [Frontend](FRONTEND.md). Chaque racine garde
son propre contexte et sa propre instance Pinia ; partager le runtime ne
fusionne pas leurs états. Les pages Core existantes restent prioritaires.
Une extension qui crée elle-même une application avec `createApp()` ne reçoit
pas automatiquement ces installations : privilégier les montages du Core.

```js
import { registerRiaPage } from '@tycms/api'
import LmsHome from './LmsHome.vue'

export function registerFrontend() {
    registerRiaPage('lms/home', LmsHome)
}
```

L’ancien `assets/page-components.js` découvert par `import.meta.glob` n’est
plus incorporé au build Core : déplacer le frontend dans son projet, déclarer
`getFrontendEntry()` et exporter `registerFrontend`.

## Vue de configuration d’une extension

L’écran **Extensions → Paramètres** est un shell générique : il choisit le
Space, charge les valeurs et laisse à l’extension le rendu de son formulaire.
Deux déclarations sont possibles.

### Formulaire déclaratif

`coreRegisterConfigForm()` retourne un `Form` PHP. Le Core affiche alors son
interface générique et valide la soumission contre cette même définition
serveur. C’est le mode par défaut, suffisant pour des champs courants.

### Vue propre à l’extension

L’extension peut déclarer un identifiant logique :

```php
public function getConfigViewComponentName(): ?string
{
    return 'lms/config';
}
```

Le Core transmet uniquement cet identifiant dans le champ `config_view` de
l’API. Il ne transmet jamais de chemin d’import, de classe, de template ni de
HTML. Le bundle Vue de l’extension enregistre le composant correspondant :

```js
import { registerExtensionConfigView } from '@tycms/api'
import LmsConfig from './LmsConfig.vue'

export function registerFrontend() {
    registerExtensionConfigView('lms/config', LmsConfig)
}
```

Le registre refuse un identifiant invalide, le préfixe `core/` et les
doublons. Une extension installée n’a besoin d’aucun rebuild du Core : son
propre manifeste Vite et l’Import Map fournissent le composant.

La vue reçoit les props suivantes :

| Prop | Contrat |
| --- | --- |
| `extension` | Métadonnées et configurations des Spaces autorisés pour cette extension. |
| `space` | Space sélectionné, avec son `template`, son `mail_from` et ses `params`. |
| `spaces` | Spaces que le compte peut configurer pour cette extension. |
| `definition` | Formulaire de confiance préparé pour Vue, sections masquées retirées, ou `null` si aucun formulaire n’est déclaré. |
| `params` | Brouillon courant, en `v-model:params` ; la vue émet `update:params`. |
| `errors`, `saving` | Erreurs de champs et état d’enregistrement. |
| `save(values?)` | Enregistre la configuration dans la table `config` via le WF commun, en ne retenant que les champs déclarés dans `definition`. |

Une vue qui intègre le formulaire générique utilise le composant global
`form_renderer`, y compris ses slots `section-header` et `section-footer`. Elle
porte elle-même son bouton d’enregistrement et lui donne la classe `ctrl-s`
pour conserver le raccourci commun. Si `definition` vaut `null`, `save()`
n’écrit rien : une vue sans formulaire reste responsable de ses propres
endpoints.

```vue
<template>
    <form @submit.prevent="submit">
        <form_renderer v-if="definition" v-model="draft" :definition="definition" />
        <button type="submit" class="btn btn-primary ctrl-s" :disabled="saving">Enregistrer</button>
    </form>
</template>
<script>
export default {
    props: ['extension', 'spaces', 'space', 'definition', 'params', 'errors', 'saving', 'save'],
    computed: {
        draft: {
            get() { return this.params },
            set(value) { this.$emit('update:params', value) },
        },
    },
    methods: {
        async submit() { await this.save(this.draft) },
    },
}
</script>
```

La vue peut appeler ses propres API avec `this.api` en Vue Options API ou
`useApplication().api` en Composition API. `save()` ne couvre que la table
`config` : aucun mécanisme ne rend atomiques la configuration et un stockage
externe. Ordonner les appels, traiter un échec partiel et rendre les endpoints
propres à l’extension idempotents restent de sa responsabilité. Ses routes
conservent leurs ACL, leur CSRF et leurs validations.

Si `config_view` est déclaré mais que le bundle chargé ne l’enregistre pas,
l’écran affiche un avertissement et revient au formulaire déclaratif lorsqu’il
existe. Une extension dont le frontend est incomplet ne bloque donc pas sa
propre administration.

Le contrat reste lié au Space sélectionné, comme le formulaire générique. Un
réglage réellement global demanderait un mode séparé. Les extensions Core
Sécurité et Template principale utilisent ce même registre pour leurs vues
propres ; elles sont enregistrées par le bundle Core, puisque seules les
extensions installées fournissent un frontend compilé.

## Une extension en développement, les autres compilées

Dans `docker/dev/.env` :

```dotenv
APP_DEV=true
DEV_CORE_VITE=true
DEV_EXTENSION=plugins/Lms
DEV_EXTENSION_VITE_PORT=3001
```

`./docker/dev/start.sh` lance le Core sur 3000 et l’extension sélectionnée sur
3001. Ouvrir le site PHP, pas le serveur Vite. Le port d’extension publié peut
être changé ; le port interne reste 3001. Les origines locales et WebSockets
autorisés par la CSP correspondent aux seuls serveurs déclarés.

Les autres extensions chargées utilisent leurs manifestes compilés. Pour
construire l’une d’elles sans changer la sélection du `.env` :

```bash
./docker/dev/extension-npm.sh plugins/AutreExtension ci
./docker/dev/extension-npm.sh plugins/AutreExtension run build
```

Pour LMS, remplacer l’argument par `plugins/Lms`. Le script réutilise son
conteneur Vite si c’est l’extension active ; il refuse d’y réinstaller les
dépendances pendant son fonctionnement. Chaque extension possède son propre
dossier `node_modules` persistant dans `containers_datas/extensions/`.

`DEV_EXTENSION=` désactive le Vite d’extension et charge tous les builds.
`DEV_CORE_VITE=false` conserve PHP en DEV mais utilise le Core compilé ; le
démarrage le compile et prépare aussi le runtime de développement.
`APP_DEV=false` ignore toutes les sélections Vite côté PHP et démarre le site
avec les assets compilés. Les extensions doivent avoir été construites
séparément ; le build système ne les embarque pas.

Les changements internes d’un composant Vue passent par le HMR de son serveur.
Un changement d’entrée, de registres, d’API commune, de sélection ou de
dépendances peut demander un rechargement complet ou un redémarrage.

## ZIP de production et signature du Store

`./build_extensions/build.sh` liste les extensions installées dotées d’un
`version.json` valide, puis compile et signe la sélection. Un chemin explicite
comme `plugins/Lms` évite le menu. Le build utilise l’image Docker de TyCMS,
avec une copie isolée des sources courantes et sans toucher aux Vite locaux.
Le ZIP ne contient pas les sources frontend, tests, dépendances npm ou fichiers
locaux. Les anciennes sorties générées ne sont pas reprises.

Chaque extension porte `version.json` et `version.md` à côté de son point
d’entrée. `ExtensionMetadata` valide le schéma, le type, le dossier,
l’identifiant, les versions sources et les bornes CMS/PHP. Par défaut,
`BaseExtension::getVersion()` lit ce fichier ; les extensions système gardent
leur surcharge utilisant `APP_VERSION`. Les bornes sont déclarées par l’auteur,
sans résolution des dépendances entre extensions dans cette première version.
Pour `source_versions`, la première installation reste toujours permise ; le
champ ne restreint que les mises à jour. `"*"` est la valeur recommandée par
défaut : elle couvre la première installation et toute mise à jour depuis une
version antérieure, les migrations suivant naturellement le versionnement. `[]`
réserve l’extension à une première installation uniquement ; une liste explicite
autorise la première installation et les versions listées. Une incompatibilité
réelle se règle par une nouvelle extension, pas par une matrice de versions
sources.

La compilation ne reçoit aucune clé privée. La signature Ed25519 se fait sur
l’hôte après l’arrêt du conteneur et vérifie le plan figé avant npm. Une paire
de clés `extensions-…` sert à toutes les extensions d’un Store, indépendamment
de la clé de mise à jour TyCMS. Le format de signature et son domaine
cryptographique sont propres aux paquets d’extension. La clé de confiance
reste extérieure au paquet ; les notes et le ZIP sont liés au manifeste signé.

Le [README de build](../build_extensions/README.md) décrit la génération des
clés hors du dépôt, la configuration ignorée `signing.local.php`, les commandes
et les quatre artefacts livrés sous `build_extensions/dist/` : ZIP, notes,
`.extension-build.json` et `.extension-signature.json`.

## Migrations depuis l’administration

L’onglet **Gestion** de l’écran Extensions est réservé à ROOT. Il liste les
extensions chargées avec leur version, leur origine Core ou installée et
l’état de leurs migrations. Une version est en attente lorsqu’elle est déclarée
par `getMigrations()` mais absente de la table `migrations` pour cette
extension. La comparaison ignore la casse et conserve l’ordre déclaré.

Le bouton **Lancer les migrations** n’apparaît que pour une extension Core
active ayant des versions en attente. Les extensions installées sont déjà
listées mais leur gestion sera branchée plus tard. Une extension désactivée
n’est pas chargée : ni ses migrations ni ses routes n’existent dans la requête ;
il faut l’activer avant de pouvoir les exécuter.

Le POST passe par le CSRF commun des API et par l’annotation `ROOT`. Le WF
relit l’identifiant dans les extensions chargées et refuse toute autre valeur :
le navigateur ne fournit ni classe, ni chemin, ni liste de versions. Le moteur
`Migration` reprend `CriticalSection('migration_running')` et revérifie chaque
version avant exécution. Les migrations ne sont pas transactionnelles avec le
DDL et aucun retour arrière automatique n’est promis. Les échecs sont journalisés
dans le canal `extensions` avec le code `EXTENSION_MIGRATION_FAILED` ; une
exécution complète porte `EXTENSION_MIGRATIONS_RUN` et une version restante
`EXTENSION_MIGRATIONS_INCOMPLETE`.

## Distribution publique

Quand TyCatalog est actif, l’API publique est servie sous
`/tycms-catalog/<slug>` et ne dépend ni de la session, ni du Space courant, ni
de la langue. Elle n’expose que les catalogues activés et les versions
**publiées** dont l’archive existe encore.

| Route | Contenu |
| --- | --- |
| `GET /` | Descripteur : `schema_version`, `name`, `description`. |
| `GET /catalogs` | Amis déclarés : `name`, `url`, `description`, paginés. |
| `GET /extensions` | Fiches ayant au moins une version distribuable. |
| `GET /extensions/<id>` | Fiche d’une extension distribuable. |
| `GET /extensions/<id>/versions` | Versions publiées, ordre numérique décroissant. |
| `GET /extensions/<id>/versions/<version>` | Enveloppe signée et transport du ZIP et des notes. |
| `GET .../download` | Archive locale ou redirection contrôlée vers l’URL externe. |
| `GET .../notes` | Notes Markdown servies depuis la base. |

Les listes portent `schema_version`, `items`, `page`, `limit` et `total`.
`limit` est plafonné à 100 ; une pagination invalide produit un 400 au format
`schema_version`/`error.code`/`error.message`. Les brouillons, versions
retirées et versions sans archive sont omis. Les chemins privés ne sont jamais
exposés. Aucun PHP déposé n’est exécuté.

Le nom affiché et la description d’une extension proviennent de sa fiche de
catalogue, modifiable depuis **Versions → Fiche**. La description est du
**Markdown basique** (gras, italique, listes, liens) ; le client la rend après
purification, sans HTML brut ni images Markdown. L’image de présentation est un
champ `image_url` séparé, URL absolue HTTPS. Ces métadonnées de présentation ne
sont pas signées ; l’identité technique et les compatibilités restent celles du
manifeste signé.

## Client Core des catalogues

L’onglet **Installation** de la page Extensions est réservé à ROOT. Il enregistre
les sources externes dans `extension_catalog_sources` (migration Core `1.1.4`) :
nom, URL de base, `key_id`, clé publique Ed25519, licence optionnelle chiffrée
et activation. La clé publique est adoptée manuellement ; elle n’est jamais
déduite du catalogue. Une modification d’URL ou de clé est un changement de
confiance explicite.

La migration Core `1.1.5` insère d’office la source officielle
(`Catalogue officiel`, `https://www.tycms.fr/tycms-catalog/official`,
`extensions-store-official` et sa clé publique). Elle ne réécrit jamais une
source déjà présente : une licence, une désactivation ou une autre clé adoptée
par ROOT restent intactes.

L’URL doit être joignable **depuis le serveur PHP**, pas seulement depuis le
navigateur. En Docker DEV, utiliser l’URL interne du site
(`http://localhost/tycms-catalog/<slug>`) plutôt que le port publié du
navigateur. En production, HTTPS est requis.

La consultation récupère le descripteur, les amis déclarés, les extensions et
les versions publiées. Pour chaque extension, le client télécharge la dernière
version, vérifie son enveloppe avec la clé de la source, compare la version
locale et contrôle les bornes CMS/PHP du manifeste signé. `source_versions`
détermine si une mise à jour est autorisée (`"*"`, liste explicite ou vide). En
release `dev`, la compatibilité n’est pas évaluée et reste affichée comme telle.

La licence est chiffrée par `CryptHelper` et n’est jamais renvoyée à
l’interface. Elle est envoyée uniquement aux endpoints de base du catalogue
configuré, jamais à un CDN ou une redirection.

Le catalogue officiel proposé est
`https://www.tycms.fr/tycms-catalog/official` ; le bouton **Utiliser le
catalogue officiel** préremplit son URL, son `key_id` et sa clé publique. ROOT
valide explicitement l’enregistrement de cette racine de confiance.

## Installation neuve depuis un catalogue

L’installation d’une extension absente est réservée à ROOT. Les mises à jour
d’une extension déjà installée sont traitées dans la section suivante.

La provenance est conservée dans `extension_installations` (migration Core
`1.1.5`) : identifiant unique, type et dossier, source d’origine avec nom et URL
instantanés, version, `key_id`, SHA-256 de l’archive, statut
`installing`/`installed`/`failed`, auteur et dates. Une ligne est réservée avant
le téléchargement ; un échec la laisse en `failed` et autorise une nouvelle
tentative. Un identifiant déjà `installed` ou une extension déjà chargée est
refusé.

Le client vérifie d’abord que la version demandée est bien la dernière publiée,
puis récupère son détail signé. L’URL d’archive du transport est utilisée
directement, sans suivre de redirection. La licence n’est jointe au
téléchargement que si l’URL d’archive a la même origine que la source ; un CDN
externe ne la reçoit jamais.

Le ZIP est téléchargé dans `var/extensions` (privé), contrôlé en taille et
SHA-256, puis revérifié par `ExtensionPackage` avec la clé de la source. Il est
extrait dans ce dossier privé avant toute pose. Les cibles sont déduites des
métadonnées signées, jamais du navigateur :

- `extensions/plugins|modules|templates/<Folder>` pour le code ;
- `public/assets/<extension_id>` pour le frontend compilé.

Un dossier cible existant, un lien symbolique ou une extension déjà connue
bloquent l’installation. Les fichiers sont ensuite déplacés par `rename`, puis
une nouvelle instance `Application` exécute les migrations en attente. Le site
n’est pas mis en mode maintenance : une requête concurrente peut charger
l’extension pendant cette courte fenêtre. Un verrou global empêche deux
installations simultanées.

En cas d’échec après la pose, les dossiers créés par cette tentative sont
supprimés et le statut passe à `failed`. Les migrations SQL déjà exécutées ne
sont pas annulées. En release `dev`, la compatibilité CMS/PHP n’est pas évaluée :
l’installation reste possible avec un avertissement explicite dans l’interface.

## Mise à jour d’une extension installée

La mise à jour est réservée à ROOT et ne concerne qu’une extension suivie dans
`extension_installations` avec le statut `installed`, dont la version locale
correspond à la provenance enregistrée. Seule la **dernière version publiée** et
**strictement supérieure** peut être installée : aucun retour arrière de version
n’est proposé par ce chemin.

Le client vérifie la signature de la version cible, son identité (même
identifiant, même type et même dossier que l’extension installée) et sa
compatibilité CMS/PHP. Le champ `source_versions` de la **version cible**
détermine si la mise à jour est autorisée depuis la version locale : `"*"`
autorise toute version antérieure, une liste explicite n’autorise que les
versions listées, et `[]` bloque la mise à jour. En release `dev`, la
compatibilité n’est pas évaluée et l’interface affiche un avertissement.

L’interface dédiée est l’onglet **Mises à jour**. Il interroge les catalogues
à son ouverture et via le bouton **Vérifier les mises à jour**, puis après
chaque mise à jour, reprise ou restauration. L’onglet **Gestion** reste consacré
aux migrations et à la future désinstallation : il ne consulte pas les
catalogues et ne dépend donc pas de la disponibilité de leurs URLs. Ses
extensions peuvent être filtrées par provenance (Core, catalogue ou hors
catalogue) à partir des seules données locales. Le tableau de bord ROOT
présente une synthèse des extensions à mettre à jour ; une source indisponible
y est signalée sans bloquer les autres vérifications.

La mise à jour se déroule en deux requêtes, pour que la nouvelle classe PHP soit
chargée dans un processus neuf :

1. **Préparation** : téléchargement, vérification SHA-256/signature/structure,
   contrôle de syntaxe des fichiers PHP, extraction privée, sauvegarde de
   l’ancienne extension et de ses assets dans `var/extensions/.update-<op>`,
   puis pose des nouveaux fichiers. Un état `var/extensions/update-<id>.json`
   est écrit et la provenance passe en `updating`.
2. **Finalisation** : nouvelle requête, chargement de la nouvelle version,
   contrôle de `version.json`, exécution des migrations de l’extension avec
   `Migration`, puis passage de la provenance en `installed` et suppression de
   la sauvegarde.

L’interface enchaine automatiquement les deux requêtes. Si la finalisation
échoue, les anciens fichiers sont restaurés et les migrations SQL déjà exécutées
ne sont pas annulées ; si la restauration elle-même échoue ou si la requête est
interrompue, l’état reste visible dans la consultation avec les actions
**Reprendre** et **Restaurer l’ancienne version**. Le site n’est pas mis en mode
maintenance : une courte fenêtre subsiste entre les deux requêtes. Un cache
OPcache qui ne revalide pas les fichiers peut empêcher le chargement de la
nouvelle version ; dans ce cas, redémarrer PHP-FPM ou vider OPcache.

## Désinstallation d’une extension installée

La désinstallation est réservée à ROOT et aux extensions non-Core suivies par
un catalogue (`extension_installations` en `installed`, version locale
identique). Une extension installée manuellement (« hors catalogue ») n’est pas
désinstallable depuis l’interface.

Avant de retirer le code, le Core appelle `BaseExtension::uninstallExtension()`.
Cette méthode est vide par défaut et doit être surchargée par l’extension pour
supprimer ses tables, données, configuration et ACL. Elle s’exécute avant la
suppression des fichiers, car elle peut utiliser les classes et helpers de
l’extension ; elle doit rester idempotente pour permettre une nouvelle tentative
après échec.

Le Core déplace ensuite le code et les assets dans
`var/extensions/.uninstall-<op>`, supprime les versions de `migrations` de
l’extension et sa ligne `extension_installations`, puis efface la sauvegarde.
La suppression de l’historique de migration permet une réinstallation propre
qui rejoue les migrations : `uninstallExtension()` doit donc réellement
supprimer les objets de l’extension.

En cas d’échec avant la suppression de la provenance, les fichiers sont
restaurés. Les changements SQL déjà exécutés par le hook ne sont pas annulés.
La confirmation de l’interface rappelle que tables, données, configuration et
ACL peuvent être supprimés définitivement.

## Périmètre restant

TyCatalog fournit le catalogue fonctionnel : administration, publication,
rétention, jetons, API publique et client Core pour les sources, la confiance,
la licence, la consultation, l’installation neuve et la mise à jour. Restent la
résolution des dépendances entre extensions et la rotation des clés. Les
compatibilités sont déclarées dans les métadonnées et contrôlées à
l’installation comme à la mise à jour. Installer du PHP revient à lui donner les
droits de l’application ; l’installateur contrôle donc signature, structure,
syntaxe et chemins avant la découverte du paquet par le chargeur. Le métier LMS
(cours, leçons, inscriptions, progression et ACL) reste à concevoir.
