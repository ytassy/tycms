# Architecture de Tycms

Ce document est le point de référence pour l'organisation du projet et les
règles à respecter lors d'une évolution. Toute nouvelle fonctionnalité doit
conserver la séparation des responsabilités décrite ci-dessous.

## Organisation du projet

| Emplacement | Rôle |
| --- | --- |
| `src/releases/vdev/framework` | Noyau HTTP, routage, exécution des routes, base de données, ORM et réponses. |
| `src/public` | Unique racine HTTP du serveur web : point d'entrée `index.php`, règles `.htaccess` et fichiers statiques publiés sous `assets_core_vdev/` et `assets/`. |
| `src/public/assets_core_vdev` | Assets livrés avec les extensions Core : bundles Vue/CSS, polices embarquées et fichiers de MainTemplate. |
| `src/public/assets` | Assets hors extensions Core, notamment les polices téléchargées dans `fonts/`. |
| `src/releases/vdev/core_extensions/<Extension>` | Extensions livrées avec le système, sous le namespace `YTassy\Tycms\CoreExts`. `Core` contient notamment l'administration, les ACL et les menus ; `MainTemplate` fournit la template principale. |
| `src/releases/vdev/core_extensions/Core/Controllers` | Points d'entrée HTTP et API de Core. |
| `src/releases/vdev/core_extensions/Core/WF` | Workflows métier : règles, validation métier et autorisation. |
| `src/releases/vdev/core_extensions/Core/Helper` | Helpers génériques réutilisés par plusieurs domaines : SQL, ORM, transformations et opérations de bas niveau. |
| `src/releases/vdev/core_extensions/Core/<Module>` | Module fonctionnel : classes techniques propres au domaine, par exemple `Cron`, `JobQueue` ou `Security`. |
| `src/releases/vdev/core_extensions/Core/Models` | Modèles ORM correspondant aux tables. |
| `src/releases/vdev/core_extensions/Core/ExtensionApi` | Classes de base, interfaces, traits, attributs et définitions exposés aux extensions. |
| `src/extensions/plugins/<Plugin>` | Plugins installés, sous le namespace `YTassy\Tycms\Plugins`. |
| `src/extensions/modules/<Module>` | Modules installés, sous le namespace `YTassy\Tycms\Modules`. |
| `src/extensions/templates/<Template>` | Templates installées, sous le namespace `YTassy\Tycms\Templates`. |
| `src/ria/src` | Frontend Vue 3, stores Pinia, composants et styles. |
| `src/updater` | Moteur autonome de mise à jour, hors racine HTTP, préparé depuis le ZIP authentifié et accessible par un script public secret. Son interface permet la préparation, la maintenance, la sauvegarde facultative, les migrations, l'activation et la récupération. Voir [le contrat de mise à jour](SYSTEM_UPDATES.md#exécution-depuis-le-lien-secret). |
| `src/public/assets_updater` | Interface Vue autonome et dépendances locales du moteur, installées lors de la préparation. |
| `Docs` | Documentation de référence du projet. |

Le développement utilise la release `src/releases/vdev`. Le point d'entrée
`src/public/index.php` lit une seule fois `src/var/releases-states.json` et
charge le `index.php` de la release sélectionnée. Le schéma attendu vaut `1` ;
`active_release` est un identifiant composé de lettres ASCII, chiffres,
tirets ou underscores. Le chemin réel du point d'entrée doit rester sous
`releases/`, y compris après résolution des liens symboliques. Un état absent,
invalide ou une release inaccessible produit une réponse HTTP 500 générique ;
le motif technique est journalisé côté serveur.

Le point d'entrée de la release définit `APP_ROOT` sur `src`, charge
`version.php`, son autoload et sa configuration, puis définit l'URL
`ASSETS_CORE_PATH` avant toute page de maintenance ou création d'Application.
Le framework et toutes les extensions Core partagent `APP_VERSION` ; leurs
identifiants de migration restent indépendants. Les tests reproduisent ces
constantes sans exécuter le point d'entrée HTTP ni charger les secrets locaux.

Les compilations Vite et Mermaid utilisent le lecteur Node commun
`src/tools/core-assets.mjs`. Il extrait par regex l'unique définition littérale
de `APP_VERSION_STRING` dans `src/releases/vdev/version.php`, sans exécuter PHP.
Cette définition reste sur une ligne, entre apostrophes, avec uniquement des
lettres ASCII, chiffres, tirets ou underscores. Une définition absente, ambiguë
ou invalide interrompt la compilation ; aucune version de secours n'est utilisée.

Le lecteur calcule `/assets_core_v<version>/plg_core/` et le dossier de sortie
correspondant sous `src/public`. Vite fournit cette base au JavaScript via
`import.meta.env.BASE_URL` et au SCSS via `$core-assets-base`. Les aperçus et
Mermaid utilisent cette même base ; les tests Node la passent explicitement.
Après modification de `version.php`, redémarrer Vite. Les montages et le contrôle
de santé Docker de développement restent configurés pour `vdev`. La fabrication
du ZIP autonome est décrite dans le [guide de build](../build/README.md).
Le traitement des URL déjà en base reste à réaliser.

Les contrats métier sont décrits dans les [guides spécialisés](README.md).
Les mécanismes d'interface réutilisables sont dans [Frontend](FRONTEND.md).
Cette architecture fixe les responsabilités et les protections transverses.

## Configuration locale et valeurs par défaut

Chaque release livre `configuration.base.php`, qui déclare `ConfigurationBase`
et les valeurs par défaut du framework, ainsi que `configuration.sample.php`,
un exemple commenté à copier vers `configuration.php`. Le fichier local `configuration.php`
charge cette base avec `require_once __DIR__ . '/configuration.base.php';` et
déclare `class Configuration extends ConfigurationBase`. Il ne conserve que
les réglages propres au site. La base ne doit pas être modifiée localement :
elle est remplacée par celle de la release cible lors d'une mise à jour,
tandis que l'updater recopie le fichier local à côté d'elle.

Le [fichier d'exemple](../src/releases/vdev/configuration.sample.php) présente
les réglages importants à définir avant le premier démarrage, puis toutes les
autres options en commentaire. Décommenter uniquement les surcharges utiles.
Seules `configVersion` et `offline` sont déclarées activement pour conserver
le contrat de version et la bascule de maintenance. Le constructeur parent
reste hérité ; un exemple commenté indique comment l'appeler si nécessaire.
Ne jamais ajouter de secrets réels dans ce fichier d'exemple versionné.

Structure minimale de la configuration locale :

```php
<?php

require_once __DIR__ . '/configuration.base.php';

class Configuration extends ConfigurationBase
{
    public int $configVersion = 1;

    // L'updater doit pouvoir modifier ce booléen littéral dans le fichier local.
    public $offline = false;

    // Déclarer ici les surcharges : base SQL, secret, premier administrateur, etc.
}
```

Les installations existantes adoptent cet héritage manuellement en conservant
exactement leur secret et leurs valeurs spécifiques. Pour une première
installation, renseigner la connexion SQL, un secret long et aléatoire et les
identifiants du premier administrateur, sans conserver les valeurs d'exemple
de la base. Une propriété locale conserve le même type que la propriété
héritée, y compris l'absence de type. Ne jamais modifier le type d'une option
déjà livrée sans traiter cette contrainte d'héritage PHP.
Une surcharge de tableau remplace le tableau entier ; ses nouvelles clés
nécessitent une conversion dédiée ou une adaptation locale, sans fusion implicite.
`core_extensions_enabled` fait exception : sa normalisation complète les trois
interrupteurs Tools/Matrix/Automation manquants avec `true`, selon le contrat
ci-dessous. La clé TyCatalog omise reste absente pour suivre le mode DEV au
chargement.

`ConfigurationBase::__construct()` appelle `normalize()`. Si la classe locale
possède un constructeur, elle appelle `parent::__construct()` **après** ses
affectations, notamment les variables d'environnement Docker. Sans constructeur
local, PHP appelle automatiquement celui de la base.

`configVersion` décrit le contrat des options locales, indépendamment de la
version du logiciel ; `CURRENT_CONFIG_VERSION` vaut actuellement `1`. La valeur
par défaut de `configVersion` reste `1` dans la base pour reconnaître les anciens
fichiers qui ne la redéclarent pas. Une version inférieure à `1` ou supérieure
au contrat courant est refusée. L'ajout d'une option avec une valeur par défaut
ne nécessite aucune conversion ni changement de version. Lors d'un changement
de structure, ajouter les conversions successives selon la version lue avant
de porter la version en mémoire au contrat courant. Préserver les choix locaux
et rendre les appels répétés sans effet supplémentaire.

Le bootstrap web applique trois réglages PHP d'erreurs après avoir construit la
configuration et avant toute réponse : `display_errors` (`false` par défaut),
`log_errors` (`true`) et `error_reporting_level` (`E_ALL & ~E_DEPRECATED`).
`display_errors = true` est réservé au développement. Ces valeurs sont
appliquées par `ConfigurationBase::applyPhpErrorSettings()`, appelée par le
point d'entrée de la release ; le constructeur ne modifie pas le processus,
afin que les tests et les outils CLI ne soient pas affectés. Les erreurs
survenues avant ce point d'entrée, notamment un `configuration.php` illisible
ou invalide, restent régies par le `php.ini` de l'hébergement : `log_errors`
doit y rester actif. Une surcharge locale conserve le type booléen de
`display_errors` et `log_errors`, et le type entier de `error_reporting_level`.
Le [bilan de sécurité](PLUGIN_SECURITE.md#erreurs-php) vérifie ces trois valeurs
effectives et signale un hébergement qui refuse de les appliquer.

La normalisation s'effectue uniquement en mémoire : elle ne réécrit ni le PHP
local, ni les secrets, ni la version déclarée dans le fichier. Aucun changement
de structure n'est nécessaire dans le contrat initial. La propriété `offline`
reste explicitement déclarée dans `configuration.php` avec une valeur littérale
`true` ou `false` : le moteur de maintenance l'analyse sans exécuter le fichier.

Les tests chargent `src/tests/Support/Configuration.php`, une classe
`Configuration` héritant de cette même base, sans importer la configuration
locale ni les secrets du site. Le test de migration de l'updater dépose une
base et une configuration isolées dans chaque release de sa fixture.
Le build distribue la base et l'exemple commenté, et exclut toujours
`configuration.php` ; les deux
validateurs de ZIP, dans Core et sur le serveur de distribution, exigent
`configuration.base.php`. L'ancien serveur accepte également les nouveaux ZIP,
qui conservent `configuration.sample.php` ; son actualisation ajoute le contrôle
de présence de la base. Le nouveau validateur refuse la publication d'un ancien
ZIP dépourvu de base, sans modifier les paquets déjà publiés ni leurs signatures.

## Extensions système et extensions installées

`src/releases/vdev/composer.json` associe les namespaces aux dossiers par PSR-4.
Le plugin applicatif réside dans `Core`, la template principale dans
`MainTemplate`. Par exemple :
`YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseController` et
`YTassy\Tycms\CoreExts\MainTemplate\Template`.
Après un changement de namespace, régénérer l'autoload Composer dans
l'environnement PHP. Utiliser les namespaces actuels ; aucun alias des anciens
`CoreExts\Main` ou `Provide` n'est fourni.

`Core/ExtensionApi` regroupe les classes de base, interfaces, traits, attributs
et définitions exposés aux autres extensions. C'est le point d'entrée pour
étendre Core. Certaines signatures publiques utilisent aussi des types situés
dans `Core/Helper`, comme `PageComponentRegistry` ; ce dossier ne constitue donc
pas une frontière technique étanche.

Les helpers génériques, réutilisés par plusieurs domaines, restent dans
`Core/Helper`. Un module fonctionnel regroupe en revanche ses propres classes
techniques à sa racine — `Core/Cron`, `Core/JobQueue`, `Core/Security`,
`Automation` — quand elles forment un ensemble cohérent. Le module se lit d'un
bloc, et son contrat public reste dans `Core/ExtensionApi`. Appartenir à un
module n'expose rien : seuls les types cités par un contrat public sont
utilisables par les extensions.

L'API exposée est organisée ainsi (namespaces relatifs à
`YTassy\Tycms\CoreExts\Core\ExtensionApi`) :

| Élément | Emplacement |
| --- | --- |
| Contrôleur applicatif et vues HTML / RIA | `BaseController`, `BaseView`, `RiaView` |
| Classe de base des templates | `BaseTemplate` |
| Événements généraux implémentés par les extensions | `IExtensionEvents` |
| Fournisseurs et classes de composants de page | `Page\IPageComponentProvider`, `Page\PageComponent` |
| Réponse HTML avec template et contenu à rendre | `Template\TemplateResponse`, `Template\ContentTemplate` |
| Définition d'un type d'entrée de menu | `Menu\MenuItemDef` |
| Accès aux langues et aux helpers partagés | `Traits\TraitCoreLang`, `Traits\TraitCoreSharedHelpers` |

`BaseView` est définie uniquement dans `Core/ExtensionApi/BaseView.php` et
hérite de `BaseApplicationItem` avec `TraitCoreSharedHelpers`. `RiaView`
hérite de cette vue et `BaseController::getViewResponse()` attend ce type.
Le contrat d'utilisation est décrit dans
[Contrôleurs — vue HTML classique](CONTROLLERS.md#vue-html-classique--baseview).

`IExtensionEvents` porte les hooks `core...` pour les extensions qui y
participent, y compris les templates. Les composants de page utilisent leur
contrat distinct `Page\IPageComponentProvider`.

Toute classe de template hérite de
`YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseTemplate`. Cette classe hérite
de la base du framework et fournit les helpers Core partagés, un catalogue de
couleurs par défaut et `getRiaGlobalVariable()` pour préparer la configuration
Vue du Space courant. Elle conserve les contrats `render()` et `renderEmail()`
de la base du framework. Une template qui utilise cette configuration doit la
transmettre à son document HTML, en protégeant sa sérialisation JSON ; voir
[Langues](LANGUAGES.md#dictionnaire-dans-vue). Le chargement et certains types
internes acceptent encore la base du framework : cet héritage attendu n'est
donc pas imposé par une validation à l'enregistrement.

Le nom affiché du plugin reste « Core ». Les traits `TraitCoreSharedHelpers`,
`TraitCoreLang` et `TraitCoreAdditionalHelpers`, les propriétés `core...`, les
hooks `core...` et les types de formulaire `core.*` conservent leurs noms.
`TraitCoreAdditionalHelpers` reste interne dans `Core/Helper`.

`Application::initialiseOrThrow()` et `runMigrationsOrThrow()` utilisent le même
chargement des extensions système, dans cet ordre :

| Identifiant | Classe dans `YTassy\Tycms\CoreExts` | Condition |
| --- | --- | --- |
| `plg_core` | `Core\Plugin` | Toujours. |
| `plg_security` | `Security\Plugin` | Toujours. |
| `plg_tools` | `Tools\Plugin` | `Configuration.core_extensions_enabled['plg_tools']` vaut `true` (défaut). |
| `plg_matrix` | `Matrix\Plugin` | `Configuration.core_extensions_enabled['plg_matrix']` vaut `true` (défaut). |
| `plg_ty_catalog` | `TyCatalog\Plugin` | Valeur explicite de `core_extensions_enabled['plg_ty_catalog']`, sinon `Configuration.dev` : actif en DEV, inactif en production. |
| `plg_automation` | `Automation\Plugin` | `Configuration.core_extensions_enabled['plg_automation']` vaut `true` (défaut) ; un `false` explicite la désactive. |
| `tpl_main` | `MainTemplate\Template` | Toujours. |
| `plg_test` | `Test\Plugin` | Seulement si `Configuration.dev` vaut `true`. |

Le fichier local peut activer ou désactiver Tools, Matrix, TyCatalog et
Automation :

```php
public array $core_extensions_enabled = [
    'plg_tools' => false,
    'plg_matrix' => false,
    'plg_ty_catalog' => true, // Activation explicite, y compris en production.
    'plg_automation' => true, // Actif par défaut ; false pour le désactiver.
];
```

La base conserve Tools, Matrix et Automation à `true` pour préserver les
installations existantes. Leurs clés omises restent actives, y compris avec un
tableau vide. TyCatalog omis suit `Configuration.dev` au chargement ; sa clé
n'est pas ajoutée par la normalisation pour ne pas figer ce défaut lors d'un
appel antérieur. Un booléen explicite prime dans les deux environnements.
La normalisation accepte seulement `plg_tools`, `plg_matrix`, `plg_ty_catalog`
et `plg_automation` avec des booléens : Core, Security et MainTemplate restent
indispensables ; Test conserve sa condition `Configuration.dev`. Le chargeur
revalide ces options avant toute instanciation d'extension système, même si
elles ont changé après le constructeur de configuration.

Le socle `plg_automation` fournit le langage, l'interpréteur, les tables de
persistance, les permissions, les registres d'actions et de déclencheurs, une
session de run isolée, l'exécuteur synchrone, les actions Core, les
notifications d'échec et de scénario avec profils chiffrés, l'outbox
d'événements drainée après commit via
`onAfterRouteExecuted`, les déclencheurs `core.user.created` et
`core.page.button.clicked`, l'API productrice `emit`/`triggerNow`, les
endpoints de run manuel et de choix de scénarios, et l'écran d'administration
avec éditeur, vérification, historique et référence Markdown téléchargeable.
Les déclencheurs webhook (bearer/query/HMAC, réponse générique ou résultat) et
planifié, la génération/rotation des secrets et le worker de planification
sont livrés. Core fournit la route CRON globale `GET/POST /cron`, protégée par
le jeton `cron_token`, et appelle les hooks `onCronMinute`, `onCron15Min` et
`onCronHour` des extensions qui implémentent `ICronProvider`
(`TraitCronProvider` fournit les méthodes vides) ; l'automatisation utilise
`onCronMinute`. Voir [CRON](CRON.md). Les tests d'intégration automatisés et le lot 7 de
reprise/nettoyage restent à livrer. La conception et les lots de
l'automatisation sont dans
[le chantier Automatisation](IA/CurrentWorks/AUTOMATION.md).

Une extension désactivée n'est pas instanciée, n'est pas enregistrée et ne
participe ni aux événements, ni au routage, ni aux migrations. La désactivation
ne supprime ni ses fichiers, ni ses données, ni ses migrations déjà enregistrées.
Le fichier DEV peut surcharger ces interrupteurs ; l'exemple commenté conserve
les défauts actifs et explique les surcharges.

Les extensions distribuées séparément portent leur propre `version.json` et
`version.md`. Le parseur partagé `framework/Extensions/ExtensionMetadata.php`
valide ce contrat sans charger de classe métier. `BaseExtension::getVersion()`
peut lire directement le fichier de sa classe concrète ; les extensions système
conservent leur version commune par leurs surcharges existantes. Le menu,
la compilation isolée et la signature de production se trouvent dans
`build_extensions/`, en réutilisant l’image de fabrication TyCMS. Leurs clés,
formats et noms de signature sont distincts ; voir
[Extensions](EXTENSIONS.md#zip-de-production-et-signature-du-store).

Les plugins installés sont ensuite découverts dans `src/extensions/plugins`,
puis l'événement `onApplicationPrepare` est appelé sur les extensions déjà
chargées, y compris MainTemplate. Les modules installés, puis les templates installées
sont chargés après cet événement. Chaque chargement instancie l'extension et
l'inscrit dans le registre. Sur une base neuve, ses migrations sont aussi
exécutées ; sur une base existante, elles relèvent de l'appel explicite
`Application::runMigrationsOrThrow()`. Après initialisation de
la session, les événements `onAfterLoadExtensions` et `onRegisterFormTypes`
précèdent la création des routes et les événements de fin de démarrage.

Pour une extension installée, chaque sous-dossier représente une extension et
doit contenir son point d'entrée PHP. Le nom du dossier détermine le namespace
et l'identifiant :

| Type | Point d'entrée | Exemple de classe | Exemple d'identifiant |
| --- | --- | --- | --- |
| Plugin | `plugins/Catalog/Plugin.php` | `YTassy\Tycms\Plugins\Catalog\Plugin` | `plg_catalog` |
| Module | `modules/Catalog/Module.php` | `YTassy\Tycms\Modules\Catalog\Module` | `mod_catalog` |
| Template | `templates/Catalog/Template.php` | `YTassy\Tycms\Templates\Catalog\Template` | `tpl_catalog` |

Ces chemins sont relatifs à `src/extensions`. Le suffixe de l'identifiant est
le nom du dossier converti par `StringTools::toSnakeCase()`. Les identifiants
`plg_core`, `plg_security`, `plg_matrix` et `tpl_main` sont conservés pour les
configurations et le suivi des migrations existants.

Les extensions installables sont les plugins, modules et templates. Les
composants de l'éditeur de pages sont des classes `PageComponent` fournies par
ces extensions, via `IPageComponentProvider`, et non un type d'extension séparé.
Voir [Composants de page](PAGE_COMPONENTS.md).

### Assets publics

Les assets des extensions Core sont publiés dans
`src/public/assets_core_v<APP_VERSION_STRING>`, soit `assets_core_vdev` en DEV,
avec des URL sous `/assets_core_v<APP_VERSION_STRING>/`.
Le sous-dossier `plg_core` contient notamment
les bundles JavaScript/CSS, les polices embarquées et les images ; `tpl_main`
contient les ressources de MainTemplate (Font Awesome, images et JavaScript).
`src/public/assets` reste distinct, notamment pour les polices téléchargées par
la bibliothèque commune dans `assets/fonts` ; voir `Docs/FONTS.md`.

`src/ria/vite.config.ts` utilise la base versionnée calculée par le lecteur commun.
Le build écrit directement les CSS et chunks JavaScript dans `plg_core/css`
et `plg_core/js` du dossier public de cette version, avec un manifeste Vite.
Il prépare aussi le runtime ESM commun et l’entrée publique `@tycms/api`.
MainTemplate charge les entrées et les CSS statiques depuis le manifeste,
après l’Import Map protégée par nonce CSP. Les aperçus conservent l’alias CSS
`plg_core/css/index.css`. Les frontends des extensions ont leur propre build :
voir [Extensions](EXTENSIONS.md). Apache sert les fichiers existants des
deux répertoires comme fichiers statiques via `src/public/.htaccess`.
MainTemplate charge Video.js 8.23.4 et sa feuille CSS depuis
`ASSETS_CORE_PATH . "/plg_core/videojs/"` avant les modules Vue. Les composants vidéo
utilisent cette instance globale ; Video.js n'est pas une dépendance npm du
frontend.

Le bootstrap Vue (`src/ria/src/main.js`) transmet le HTML initial de chaque
racine `[data-vue-app]` au slot par défaut de `App.vue`, en plus du composant de
page. Ce HTML serveur reste statique : les expressions et directives Vue qu'il
contient ne sont pas compilées. Après montage des racines, seul `sec_video` est
activé par `components/common/secureVideoMount.js`, avec les attributs littéraux
`src` et `space-id` ; les autres composants globaux restent inertes dans ce HTML.
Le montage fonctionne aussi sans racine applicative Vue et libère les lecteurs
avant le démontage d'une racine. Aucun autre attribut n'est transmis au composant.
Le serveur reste responsable de l'échappement et de la purification du HTML ;
ce montage ciblé ne constitue pas un sanitizer. Contrat : [Médias](MEDIAS.md).

### Persistance et préparation de la production

Une installation et une base de production existent ; leur repère est décrit
dans l'[état du projet](IA/ETAT_DU_PROJET.md#installation-de-production).
Les évolutions doivent préserver les données, les identifiants persistants,
la configuration locale et `Configuration.secret`. Une évolution de schéma
ou de données doit prévoir la migration depuis une base existante, en plus
d'une première installation sur base vierge. Ne pas renommer ni réécrire une
migration déjà livrée pour apporter une nouvelle évolution : ajouter une
migration avec un nouvel identifiant, car le journal des migrations conserve
les identifiants déjà exécutés. Les conversions de données doivent également
préserver les contenus existants ou définir explicitement leur transition.

Les changements d’options persistées font partie de ces évolutions de données,
y compris dans les objets JSON de configuration. À l’ajout, au renommage, au
changement de type ou au retrait d’une option, prévoir une migration qui met les
valeurs enregistrées au contrat attendu, en préservant les choix existants.
Initialiser également les options des nouvelles ressources. Le code métier peut
alors lire le contrat courant directement : éviter les tests d’existence, les
valeurs de repli et les anciennes variantes conservés uniquement pour compenser
une base non mise à jour. Les valeurs par défaut des formulaires et les
validations des entrées non fiables gardent leur rôle ; elles ne remplacent pas
la migration des données persistées.

Les tests, réinitialisations et essais de migration utilisent une base et des
fichiers isolés ; la base de production n'est jamais une fixture de test.

La séparation permet de livrer `src/releases/vdev/framework` et `src/releases/vdev/core_extensions` dans
une image, et de monter un volume persistant uniquement sur `src/extensions`.
Le chemin de montage dans le conteneur dépendra de l'image de production.
Le volume ne doit pas recouvrir les extensions système. Les médias, la base,
la configuration et les secrets ont leur propre cycle de persistance.

L'autoload doit conserver la résolution PSR-4 des classes ajoutées au volume
après construction de l'image : une classmap autoritaire figée au build ne
suffit pas. Le code des extensions s'exécute avec les droits de l'application ;
ce volume doit contenir uniquement des extensions de confiance et rester hors
de la racine HTTP `src/public`.

Les namespaces séparent les classes, mais le registre et les migrations
utilisent des identifiants communs. `Application::loadExtention()` refuse tout
identifiant déjà chargé avec une `TechException`, avant l'instanciation et les
migrations de l'extension entrante. Le message identifie la clé et les deux
classes concernées ; l'entrée existante reste intacte. Une extension installée
nommée `Security` ne peut donc pas remplacer le plugin système `plg_security`.
Ce contrôle s'applique à toutes les extensions, y compris entre extensions
installées ; une collision interrompt le démarrage plutôt que de choisir une
priorité implicite.

Le dépôt fournit actuellement l'environnement Docker de développement,
qui monte tout `src`. L'image de production, son montage persistant et le
pipeline de publication Docker Hub restent à réaliser et à valider.

## Lisibilité du code

Écrire un code aéré en PHP, JavaScript et Vue. Séparer par une ligne vide les
étapes d'un traitement : vérification des entrées, lecture des données,
transformation, écriture et construction du résultat. Éviter de concentrer
plusieurs actions distinctes sur une seule ligne lorsque cela gêne la lecture.

Ajouter régulièrement des commentaires **en français** pour guider la lecture
étape par étape, en particulier dans les workflows, les transformations et les
branches dont l'intention n'est pas évidente. Placer le commentaire près du bloc
qu'il explique et préciser son rôle ou la raison d'un choix. Garder ces
commentaires à jour lors des modifications du code.

## Cycle d'une requête

`Application::initialise()` conserve la gestion des erreurs destinée au site.
`initialiseOrThrow()` exécute le même démarrage et laisse l'exception remonter
aux commandes et aux tests d'intégration. Si la table `migrations` est absente,
il crée le journal et lance les migrations du noyau et des extensions. Dès que
la table existe, les requêtes normales ne lancent plus de migration. Une
première installation interrompue n'est pas reprise automatiquement : la
remise à zéro de sa base et un script d'installation restent à traiter.

L'écran **Extensions → Gestion** permet à ROOT de lister les migrations en
attente des extensions Core actives et de lancer leur exécution. Le parcours
relit l'identifiant dans les extensions chargées, vérifie ROOT et reprend le
verrou `migration_running`. Il ne remplace pas l'updater : il couvre notamment
une extension activée après la mise à jour du code, dont les migrations n'ont
jamais été exécutées. Les extensions installées seront raccordées à ce même
parcours plus tard.

`Application::runMigrationsOrThrow()` est l'entrée explicite prévue pour
l'updater : sur une instance neuve, elle initialise la base, exécute les
migrations et charge les extensions dans leur ordre habituel, sans session ni
routes. Elle refuse `RESET_DATABASE`. Le moteur doit charger cette API depuis
la release cible, après sauvegarde et mise en maintenance.

`Migration::runMigrations()` utilise `CriticalSection('migration_running')`.
Le fichier est actuellement placé dans le répertoire temporaire PHP par
`CriticalSection`, et non dans la release. Le verrou ne couvre qu'un appel
`runMigrations()` ; la coordination de toute l'opération entre releases reste
à réaliser avec le [moteur de mise à jour](IA/CurrentWorks/MISES_A_JOUR_SYSTEME.md).

Après le chargement des extensions et de la session, Core choisit le Space
initial avant l'enregistrement des routes de menu. Un Space encore valide en
session reste prioritaire ; sans sélection, une correspondance unique de l'hôte
est choisie, puis le contexte utilisateur sert de repli. Si plusieurs Spaces
restent possibles, une requête HTML est orientée vers la page de choix avant
la résolution normale de la route. Les pages simples du Space racine admises
par ce parcours restent accessibles. `SpaceHelper::resolveInitialSpace()` porte
la sélection et `Plugin::onBeforeResolveRoute()` porte la redirection de choix.
Le formulaire historique `/space/choose` transmet le jeton CSRF de session ;
son POST le vérifie avant de charger le Space ou de changer le contexte.
La correspondance s'effectue sur la liste `Space.hosts`. En mode développement,
elle accepte aussi `localhost:<port>` en recomposant l'hôte et le port séparés
par `WebRequest`, pour la sélection initiale comme pour la liste de choix.
Un hôte configuré sans port conserve son comportement ; un port explicite doit
correspondre au port de la requête. Le formulaire d'administration ajoute le
`main_host` non vide aux hôtes lors d'un enregistrement avec `SPACE_ADMIN`.

Après ce choix, `PublicLocaleHelper` résout la langue de l'URL publique ou
`Accept-Language` dans les langues du Space. `MenuRouteHelper` enregistre les
routes publiques sous ce préfixe. Les anciens chemins de menu connus redirigent
vers leur URL préfixée ; les routes techniques conservent leur chemin. Voir
`Docs/LANGUAGES.md` pour les règles de négociation et de redirection.
Une route publique préfixée et résolue mémorise sa langue dans la session ; les
routes techniques lisent cette préférence sans la modifier.

Les routes techniques de récupération du mot de passe restent accessibles
sans choix préalable de Space, notamment depuis le navigateur qui ouvre un
lien reçu par e-mail. Le jeton et la politique du Space de création du compte
autorisent l'action ; le Space de présentation ne donne aucun droit.
Voir `Docs/PASSWORD_RESET.md`.

```text
Requête HTTP
  → Router : résolution de la route et validation des entrées déclarées
  → ApplicationRouteRunner : contrôles transverses et transaction
  → Controller : adaptation HTTP
  → WF : règles métier, validation et ACL
  → Helper : requêtes SQL / ORM et opérations techniques
  → Response : JSON, HTML ou redirection
```

Les routes sont définies avec `#[Controller]` et `#[ControllerMethod]`. Les
paramètres HTTP doivent être déclarés avec `#[ControllerParamN]` et un
`VarType` afin que le routeur valide leur présence et leur format avant
l'exécution du contrôleur. Les tableaux et objets reçus à la place d'un
scalaire ou d'une chaîne JSON sont des erreurs de paramètre. Le runner renvoie
un HTTP 400 avant le contrôleur et la transaction (JSON pour une API, page
d'erreur pour une route HTML). Le contrôle CSRF API reste prioritaire.

Après la résolution, les extensions reçoivent `onAfterResolveRoute()`. Le
plugin Sécurité y applique le contrôle des motifs du WAF avant le runner, la
transaction et le contrôleur ; voir le [contrat du WAF](PLUGIN_SECURITE.md#pare-feu-applicatif-waf).

`ApplicationRouteRunner` ouvre la transaction de la route avant d'appeler le
contrôleur et la valide ou l'annule selon le résultat. Une route qui ne doit
pas être transactionnelle doit porter `#[NoTransaction]`; un helper ne doit
pas ouvrir, valider ou annuler sa propre transaction.

Après un `COMMIT` réussi, ou après une route `#[NoTransaction]` terminée sans
exception, le runner appelle
`onAfterRouteExecuted(Route $route, bool $transactionCommitted)`. Ce hook sert
aux effets post-commit, notamment les automatisations synchrones. Le booléen
vaut `true` après une exécution réussie, y compris pour une route
`#[NoTransaction]`, dont les écritures sont validées sans transaction
explicite. Il n'est jamais appelé après un `ROLLBACK` ; ses erreurs sont
journalisées et ne remplacent pas la réponse métier.

Le webhook d'automatisation en mode `result` fait exception : il traite son
propre événement avant le commit avec `AutomationEventBuffer::drainEvent()`
pour renvoyer le statut et la sortie du run. Les événements enfants restent
dans la file et sont drainés après le commit par le hook normal.

Une session de run peut être injectée à `Application::initialiseOrThrow()` pour
exécuter une Application isolée avec un principal et un Space explicites, sans
session HTTP. Le paramètre `registerRoutes = false` évite l'enregistrement des
routes et les dépendances de langue, de menu et de WAF pour un run interne. Voir
[le chantier Automatisation](IA/CurrentWorks/AUTOMATION.md).

L'édition d'un Space est une exception : sa route `#[NoTransaction]` confie au
WF la transaction explicite, afin de coordonner le renommage éventuel du dossier
des médias avec l'écriture SQL et de tenter sa restauration en cas d'échec.
Cette coordination ne rend pas le système de fichiers transactionnel ; voir
le [contrat des médias](MEDIAS.md).

Les routes API (`isApi: true`) utilisant une méthode autre que `GET`, `HEAD`
ou `OPTIONS` exigent un jeton CSRF lié à la session dans l'en-tête
`X-CSRF-Token`. Le jeton est fourni au client Vue dans le bootstrap HTML et
renouvelé lors d'un changement d'utilisateur. Une requête sans jeton valide
reçoit une réponse JSON HTTP 403 avant l'exécution du contrôleur. Les clients
externes authentifiés autrement peuvent être exemptés explicitement par
`#[Controller(..., withoutCsrf: true)]`, après vérification de leur mécanisme
d'authentification. Cette option s'applique à toutes les routes du contrôleur.

Une route `GET` peut lire ou écrire en base dans ce projet. Elle n'est pas
soumise au contrôle CSRF automatique décrit ci-dessus : si elle change un état
protégé, son WF doit vérifier l'autorisation et la preuve propre à cette action
(par exemple `state` et code à usage unique pour un callback SSO). Prévoir
aussi la résistance aux appels répétés ou anticipés par un navigateur ou un
intermédiaire. Une simple visite d'URL ne constitue pas une preuve d'intention.

## Controller : frontière HTTP

Un contrôleur doit rester mince. Il est responsable de :

- déclarer la route, sa méthode HTTP et les paramètres d'entrée ;
- appeler le workflow adapté ;
- transformer le résultat en `ApiResponse`, réponse HTML ou redirection ;
- déclarer une ACL statique avec `#[Acl]` lorsque celle-ci ne dépend pas de
  l'objet demandé.

Un contrôleur ne contient ni règle métier, ni requête SQL, ni contrôle ACL
dépendant d'une ressource. Une route `GET` peut modifier l'état, sous les mêmes
règles de WF, transaction et autorisation qu'une autre méthode, avec une
protection explicite adaptée puisque le CSRF automatique ne couvre pas `GET`.

Exemple : `AdminApiController` expose la route puis délègue le déplacement
d'un item à `WFMenu`.

## WF : workflow métier et sécurité

Un workflow est la seule couche qui décide si l'action demandée est permise.
Il est responsable de :

- vérifier les préconditions métier qui ne sont pas seulement un format HTTP ;
- charger ou identifier la ressource concernée ;
- vérifier les ACL sur le bon périmètre (par exemple l'espace du menu) ;
- appeler un ou plusieurs helpers pour réaliser l'opération ;
- retourner un résultat métier ou lever une réponse utilisateur avec
  `ExceptionResponse`.

Toute méthode de WF appelée par un contrôleur doit vérifier les droits nécessaires
à son action, y compris pour une lecture et même si la route porte déjà un
`#[Acl]`. Le contrôle peut être factorisé dans une méthode interne du WF, mais
il doit être exécuté avant de lire des données protégées ou de les modifier.
L'annotation ACL filtre l'accès à la route ; elle ne remplace pas l'autorisation
du workflow si celui-ci est appelé depuis un autre point d'entrée.

Le workflow ne doit pas construire de SQL ni manipuler directement les
tables. Une ACL de route peut aussi filtrer une permission globale simple. Si
le droit dépend d'une ressource (espace, propriétaire, parent, etc.), le WF
identifie d'abord cette ressource, puis vérifie le droit sur son périmètre réel.

Lors de chaque évolution, prévoir les logs qui permettront de diagnostiquer
l'action en production : échecs externes, erreurs interceptées et quelques
étapes significatives. Utiliser les méthodes de `BaseApplicationItem`, des
codes stables et le Space réel ; conserver les causes internes sans dupliquer
la capture du runner. Les lectures et validations ordinaires ne demandent pas
un log systématique. Aucun secret ni contenu utilisateur ou distant brut ne
doit être enregistré ; un succès transactionnel attend le commit. Voir
[les règles d'écriture des journaux](LOGGING.md#prévoir-les-logs-pendant-le-développement).

## Helper : implémentation technique et base de données

Un helper porte les détails techniques : requêtes SQL, accès ORM, calculs de
persistance, opérations groupées et transformations de données. Il ne décide
pas si un utilisateur est autorisé à effectuer une action : cette décision
appartient au WF.

Les helpers peuvent utiliser `$this->db`, les modèles ORM et les autres
helpers partagés fournis par les traits (`TraitAllHelpers` et
`TraitCoreSharedHelpers`). Les requêtes SQL doivent utiliser les marqueurs `%`
et les valeurs passées séparément à la base de données; ne jamais concaténer
une valeur issue d'une requête HTTP dans le SQL.

Pour lire une entité, utiliser son modèle ORM lorsque le typage et l'hydratation
simplifient le code. Une lecture spécifique peut rester une requête `SELECT`
dans le helper, notamment si elle est plus simple ou plus efficace en SQL
(projection de quelques colonnes, jointure, agrégat ou verrouillage). Pour les
insertions, modifications et suppressions, utiliser l'ORM lorsque l'opération
reste simple et adaptée à ses capacités ; écrire sinon le SQL dans le helper.
La persistance d'objet n'est pas imposée.

Choisir d'abord la sécurité et l'efficacité des requêtes de lecture et d'écriture.
L'ORM apporte de la praticité lorsqu'il n'entraîne ni requêtes superflues, ni
baisse de performance, ni exposition de données sensibles. Garder les requêtes
groupées lorsqu'elles évitent des accès répétés à la base, et masquer avec
`#[Hidden]` les propriétés d'un modèle qui ne doivent pas être sérialisées.

Pour les services partagés récurrents, déclarer une propriété typée dans le
trait adapté et l'utiliser dans le métier. Réserver `getSharedExtensionsClass()`
aux traits et aux points d'infrastructure qui exposent ces accès.
`objectValidator`, fourni par `BaseApplicationItem` via `Application`, est
distinct de `sharedObjectValidator`, fourni par le registre de services.

### Accès hérités et services à réutiliser

`BaseApplicationItem` fournit déjà les accès ci-dessous. Avant d'ajouter une
propriété ou un trait, vérifier ce que la classe parente expose.

| Accès | Usage |
| --- | --- |
| `$this->app`, `getApp()` | Instance d'application et configuration via `getConf()`. |
| `$this->db` | Base partagée ; requêtes paramétrées dans les helpers. |
| `$this->request`, `$this->router` | Contexte HTTP et routage ; la requête peut être absente hors HTTP. |
| `$this->session` | Gestionnaire de session courant. |
| `$this->pendingMessageQueue` | Messages différés au prochain affichage. |
| `$this->logger` | Service Framework de journaux privés, sans SQL ; raccourcis `logInfo()`, `logWarning()`, `logError()` et `logException()`. Voir [Journaux](LOGGING.md). |
| `$this->objectValidator` | Validateur attaché à l'application. |
| `$this->url(routeName, params, query)` | URL produite par le routeur à partir d'un nom de route. |
| `$this->in_dev`, `$this->in_debug` | Indicateurs de configuration ; ne pas recréer leur lecture partout. |

| Surface | Accès / règle |
| --- | --- |
| `TraitFrameworkSharedExtensions` | `formFactory`, `sharedObjectValidator`, `sessionManager`. |
| `TraitCoreSharedHelpers` | Services Core réutilisables : ACL/Space, langues, mail, chiffrement, polices, pages, shortcodes, HLS, IA et SSO. Lire les propriétés du trait avant d'en créer. |
| `TraitCoreAdditionalHelpers` | Helpers internes Core : administration, médias, configuration, SMTP administratif, récupération et autres opérations spécialisées. |
| `TraitAllHelpers` | Compose les deux surfaces Core et ajoute les helpers internes de formulaires et d'administration des utilisateurs. |
| `TraitWF` | Accès typés aux workflows ; un helper ne s'en sert pas pour déplacer l'autorisation hors du WF. |
| `ExtensionApi/BaseOrmObject` | Accès ORM spécifiques avec `#[Hidden]` ; ne pas ajouter un large trait de services aux modèles par commodité. |

Une classe qui hérite de `TraitCoreSharedHelpers`, notamment un `BaseHelper`
ou un `PageComponent`, compose seulement `TraitCoreAdditionalHelpers` si
nécessaire. Ne pas lui ajouter `TraitAllHelpers` : réimporter un trait de
propriétés à hooks dans une classe enfant peut provoquer un conflit PHP 8.4.
Un trait peut composer un autre trait ; garder les annotations ORM sur les
accès dédiés aux modèles.

`HtmlTools::escape()` échappe les textes et attributs HTML ; cela ne valide ni
une URL ni du CSS. Les valeurs utilisées par `HtmlTools::buildStyleLine()`
doivent être validées avant assemblage. Réutiliser les validateurs spécialisés
(`PageComponents/Support/PageLinks`, `MediaHelper`, etc.) et le sanitizer du domaine concerné : le
profil HTML d'une page et celui d'un e-mail ont des contrats différents.

`TraitFrameworkSharedExtensions` expose également `sessionManager` pour
`SessionManager::deleteUserSessions()`. La révocation de toutes les sessions
d'un utilisateur est disponible avec le stockage en base ; le stockage PHP
ne permet de détruire que la session courante. Voir `Docs/PASSWORD_RESET.md`.

Les définitions de formulaires PHP sont regroupées par ressource dans un
`FormsHelper` de son namespace métier (`Helper/Menu`, `Helper/Space`,
`Helper/SsoProvider`, etc.). Chaque classe métier utilise l'accès typé au
helper de son domaine. Le WF autorise la lecture et l'écriture du formulaire,
l'API transmet sa définition JSON au client, puis le WF valide la soumission
avec la définition PHP reconstruite. Voir `Docs/FORMS.md`.
Les hooks de `BaseExtension` préparent la configuration pour le stockage et
l'éditeur, vérifient les champs soumis et signalent les sections masquées par
Space. Les règles Bunny et HLS restent dans `Core/Helper/Config/ConfigurationHelper` ;
`WFExtensions` appelle le même contrat pour toutes les extensions.

Pour une clause `IN` alimentée par une liste, passer un seul marqueur `%` et
`FormatIn::get($ids)` dans le tableau des valeurs, par exemple
`$this->db->select('SELECT * FROM pages WHERE space_id IN (%)', [FormatIn::get($ids)])`.
Ne pas construire manuellement la liste des marqueurs. `FormatIn` refuse une
liste vide : traiter ce cas avant l'appel, par exemple en retournant `[]` pour
une recherche sans identifiant.

Pour une modification cohérente de plusieurs lignes, effectuer l'opération
dans le helper, mais laisser la transaction au `ApplicationRouteRunner`.
Lorsque c'est pertinent, préférer une requête SQL groupée à une succession de
petites mises à jour.

Exemple : `MenuAdminHelper::moveItemUp()` / `moveItemDown()` verrouille les
frères, échange les deux positions puis normalise l'ensemble de leurs valeurs
`ordering` par un `UPDATE ... CASE` unique. `WFMenu` vérifie au préalable la
permission `MENU_CRUD` sur l'espace du menu.

## ACL et règles de sécurité

Les espaces sont identifiés par `spaces.id` (la racine a l'ID `1`) et leur
hiérarchie est portée par `spaces.parent_id`. Les tables qui portent un
périmètre utilisent une clé étrangère `space_id`; ne pas reconstruire une
hiérarchie à partir d'un nom ou d'une chaîne contenant des `/`.

Seul le Space racine reste sans parent (`parent_id` NULL). Tout autre Space doit
en désigner un : `WFSpace` le vérifie à la création et à la modification, et le
formulaire réserve le choix « Aucun parent » à la racine. Un administrateur
délégataire ne voit que les parents qu'il peut administrer.

- Les permissions sont définies dans
  `src/releases/vdev/core_extensions/Core/ExtensionApi/Acl/Permissions.php`.
- Les prérequis `RequiresPermissions` sont explicites et transitifs dans chaque
  rôle enregistré. Le catalogue est vérifié après l’enregistrement des extensions ;
  un prérequis inconnu ou un cycle est une erreur technique. Les sauvegardes de
  rôles incomplets sont refusées ; le calcul des ACL ne complète jamais les
  permissions à la lecture. Voir [Rôles](ROLES.md#prérequis-explicites-dans-chaque-rôle).
- Utiliser `coreAclHelper->hasGlobalPermission()` avec l'espace réel de la
  ressource pour une action d'administration ciblée.
- ROOT désigne partout le super administrateur de l’installation : utiliser
  `coreAclHelper->isRoot()`, fondé uniquement sur la permission ROOT globale du
  Space 1, jamais sur l’ID du rôle système. ROOT ne complète pas les autres
  permissions. Sa transmission manuelle exige un ROOT connecté ; l’inscription
  ne peut jamais le transmettre, même par un groupe parent. Voir [Rôles](ROLES.md#root-à-léchelle-de-linstallation).
- La sérialisation ORM respecte `#[Acl(globalPermission: ..., spaceId: ...)]`.
  Si un rôle et une permission sont déclarés ensemble, les deux sont requis ;
  une annotation ACL vide refuse le champ. Les champs internes de `User`
  utilisent ROOT sur le Space 1 ; `Hidden` conserve les secrets hors JSON,
  même pour ROOT.
- Les rôles et permissions globaux sont hérités des Spaces parents vers leurs
  descendants. La permission `MEMBER` définit l'appartenance et permet l'accès
  sans inscription supplémentaire, même si les inscriptions de l'enfant sont
  fermées. Utiliser `hasGlobalPermission(Permissions::MEMBER, $space)` pour la
  session ou `userHasGlobalPermission($userId, Permissions::MEMBER, $space)` avant
  une connexion SSO. Un contrôle de rôle exact ne répond pas à cette question.
  Voir `Docs/REGISTRATION.md` et `Docs/USERS.md`.
- `AclHelper` conserve le catalogue des rôles et permissions pour la requête,
  puis charge à la demande un instantané par compte (groupes et parents, rôles
  globaux et permissions directes par Space). La session courante et les contrôles
  par user ID utilisent ce même chargement et ce même calcul d’héritage des
  Spaces ; l’invité a une clé distincte de tout ID utilisateur. Un changement
  d’utilisateur en session sélectionne donc le bon instantané.
  Après une modification d’attributions, `invalidateUser($userId)` invalide
  uniquement le compte concerné avant un nouveau contrôle dans la même requête.
  Invalider également un instantané calculé avant la fin d’une transaction si
  celle-ci peut encore être annulée. Cette méthode ne recharge pas le catalogue :
  elle vise les attributions de groupes/rôles, pas une édition des permissions
  d’un rôle. Le contrôle après inscription suit ce contrat, sans autre calcul ACL.
- Ne jamais faire confiance à un ID, un statut, un parent ou un ordre envoyé
  par le navigateur : relire la ressource en base avant une écriture.
- Les ACL d'instance utilisent `AclHelper::hasPermissionOn()` avec son cinquième
  argument `objectId`. Les droits globaux et de type sont conservés ; les droits
  sur une instance n'accordent rien sur une autre ni globalement.
  `canDelegatePermissionsOn()` contrôle la gestion des accès et chaque permission
  transmise. Le WF doit relire/verrouiller la ressource et les rôles, utiliser le
  Space réel et borner les permissions au métier avant les écritures du helper.
  Voir [Droits sur une instance d'objet](ROLES.md#droits-sur-une-instance-dobjet).
- Les contrôles côté Vue améliorent l'interface, mais ne remplacent jamais les
  contrôles du WF.
- Une annotation `#[Acl]` dans un contrôleur ne dispense jamais la méthode de
  WF appelée de vérifier ses propres droits d'accès.
- Les erreurs métier attendues doivent utiliser `ExceptionResponse` afin que
  l'API rende une réponse utilisateur structurée. Les exceptions techniques
  doivent remonter pour provoquer l'annulation de la transaction.

### Réglage de configuration et bilan de sécurité

Un réglage de configuration qui, mal positionné, peut créer un risque de
sécurité doit être couvert par un contrôle du bilan de sécurité (onglet
« Sécurité » de l'écran Système, `Core\ExtensionApi\Security\ISecurityCheck`).

Concerne notamment un réglage qui ouvre une exécution différée (un format de
fichier interprété plus tard), élargit une autorisation, désactive une
protection, ou laisse une ressource exposée. Sans contrôle, un tel réglage reste
invisible en exploitation : le bilan est le seul endroit où l'administrateur
peut constater l'état réel du site et le corriger.

Le contrôle lit toujours la configuration effective, jamais une valeur supposée,
ne persiste rien, et propose une correction concrète quand il en existe une.
Voir [Sécurité de l'extension](../PLUGIN_SECURITE.md#bilan-danalyse-de-sécurité).

## Politique de sécurité HTTP

Le plugin `Security` ajoute les en-têtes de sécurité à chaque `Response`. La
politique CSP de production n'autorise par défaut les scripts, feuilles de style, images,
polices, connexions et médias qu'à la même origine. Les ROOT peuvent ajouter,
par Space, des origines HTTPS pour les images, médias, styles et polices, et
choisir les origines des iframes : tous HTTPS (défaut historique), liste précise
ou origine du site uniquement. Les scripts et connexions restent limités.
Les `@import` et `url(...)` du SCSS restent soumis aux listes correspondantes.
Le [guide Sécurité](PLUGIN_SECURITE.md) décrit les champs, la validation, les exemples,
la CSP des aperçus, le cas de l’administration multi-Space et la migration.

Le lecteur HLS Video.js utilise un worker et peut attacher une source média
`blob:`. La CSP autorise `worker-src 'self' blob:` et `media-src 'self'
blob:` ; `blob:` n’est pas ajouté à `script-src`. En développement, la source
Vite est aussi admise pour les workers.

Les attributs `style` restent autorisés car l'interface Vue les utilise déjà ;
ils ne permettent pas de charger une feuille de style externe. En développement,
la CSP autorise les seules origines locales des Vite sélectionnés et leurs WebSockets.
Toute nouvelle ressource externe doit être ajoutée explicitement à cette
politique après revue de sécurité. L’Import Map générée par Core reçoit un
nonce propre à la réponse. Les serveurs Vite sélectionnés localement sont
admis seulement en DEV, avec leurs WebSockets et leurs ressources frontend.

La route résolue `AdminController::home` autorise aussi
`https://fonts.googleapis.com` dans `style-src` et `https://fonts.gstatic.com`
dans `font-src`, pour les aperçus du catalogue de polices dans l'administration.
Cette exception vérifie le contrôleur et sa méthode, sans se baser sur un
paramètre du navigateur ; elle ne s'applique ni aux routes publiques ni aux API.
Les pages publiques et les champs de choix de polices utilisent les fichiers
installés localement. Voir `Docs/FONTS.md`.

L’environnement `docker/dev` conserve le port 3000 pour le Vite Core :
`APP_DEV=true` active le mode `dev` de PHP, et `DEV_CORE_VITE=true` lance ce
service Node 24. `DEV_EXTENSION` sélectionne au plus une extension sur son
propre Vite ; les autres frontends utilisent leurs manifestes compilés. Le serveur
Vite reçoit l’origine Apache pour CORS, avec possibilité de surcharge par
`VITE_BACKEND_ORIGIN`. Les sources et le dossier public des assets sont montés
aux mêmes chemins relatifs pour le serveur et le build. Le fonctionnement
Docker est détaillé dans `docker/dev/README.md`.

Les en-têtes d'une réponse peuvent être enrichis avant envoi : `httpHeaders`
est donc mutable, tandis que son corps et son code HTTP restent immuables.

`RedirectResponse` exige par défaut un chemin commençant par un seul `/`.
Les références `//`, les antislashs, espaces et caractères de contrôle sont
refusés. Chemin, query et fragment encodés sont conservés sans reconstruire
un domaine depuis Host. Après une erreur de formulaire, une URI incompatible
est remplacée par `/`. Le départ SSO utilise explicitement
`RedirectResponse::external()` : HTTPS, ou HTTP sur localhost/IP de boucle
locale ; le WF reste responsable du choix du fournisseur autorisé.

## Contexte d'hôte HTTP

`Request::$host` contient l'hôte complet de la requête, sans port, par exemple
`www.toto.fr`. Les adresses IP et `localhost` sont également conservés tels
quels. Le port est toujours exposé séparément par `Request::$port`.

Les hôtes configurés sont des noms exacts, sans protocole. En production, ils
sont comparés à `Request::$host` sans port. Le mode développement admet aussi
`localhost:<port>` en comparant séparément `Request::$port`, comme décrit dans
le cycle d'une requête.

## Frontend Vue et stores

Le frontend utilise Vue 3 et Pinia. Les composants présentent les données ;
les stores métier portent l'état partagé et les appels API. Le
[guide frontend](FRONTEND.md) détaille les factories de stores, le mixin global,
les modales d'attente/succès, les confirmations asynchrones, les classes
`ctrl-s` / `esc` et les composants réutilisables. Appliquer ces conventions à
chaque écran pour conserver les mêmes interactions.

`components/page/` contient uniquement les points d’entrée Vue publics.
Les composants internes et comportements sont regroupés par fonctionnalité
dans `components/features/` ; les contrôles et utilitaires partagés restent
dans `components/common/`, et le registre de montage public dans
`application/publicPageRegistry.js`. Voir la répartition du code dans le
[guide frontend](FRONTEND.md#répartition-du-code).

## Règle pratique pour une nouvelle action

1. Déclarer un endpoint et ses paramètres validés dans le contrôleur.
2. Créer une méthode de WF qui vérifie les préconditions et les ACL.
3. Mettre les accès SQL/ORM et la modification des données dans un helper.
4. Retourner une réponse API simple et cohérente.
5. Ajouter l'appel au store Pinia, puis raccorder le composant.
6. Recharger ou mettre à jour l'état du store après une écriture.
7. Vérifier au minimum la syntaxe PHP et le build frontend si du Vue/JS a été
   modifié.

## Maintenance de cette documentation

Mettre à jour ce fichier lorsqu'une convention d'architecture, de sécurité,
de transaction ou d'état frontend évolue. Avant de modifier une fonctionnalité
existante, lire les sections concernées et suivre les conventions locales du
plugin visé.
