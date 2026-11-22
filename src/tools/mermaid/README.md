# Compilation des bibliothèques Mermaid

Node 24 est utilisé par l’environnement Docker du projet. Depuis ce dossier :

```bash
npm ci
npm run build
```

Les versions sont verrouillées dans `package-lock.json`. Le build publie Mermaid
avec ses chunks ESM, Svg2Roughjs et les licences dans
`src/public/assets_core_v<APP_VERSION_STRING>/plg_core/mermaid`. Le lecteur
commun `../core-assets.mjs` lit la définition littérale dans
`src/releases/vdev/version.php`, comme Vite. Une version invalide arrête le
build avant toute suppression. Il remplace seulement les bundles tiers ;
`runtime.mjs`, `preview.mjs`, `style.css` et `.htaccess` restent des sources versionnées.
Le build principal Vite n’a pas besoin de réinstaller ces dépendances.

Dans Docker, utiliser un conteneur Node ponctuel avec le dépôt monté en
`/workspace`, un répertoire de dépendances dédié à cet outil et le dossier de
travail `/workspace/src/tools/mermaid`. Ne pas réutiliser les dépendances de Vite.
Le serveur HTTP doit servir `.mjs` en JavaScript et permettre CORS sans credentials
sur ce seul répertoire public, comme le fait son `.htaccess` Apache.

Exemple depuis la racine du dépôt, avec les droits de l’utilisateur local :

```bash
docker run --rm --user "$(id -u):$(id -g)" \
  --mount "type=bind,src=$PWD,dst=/workspace" \
  --workdir /workspace/src/tools/mermaid \
  -e npm_config_cache=/tmp/mermaid-npm node:24-bookworm-slim npm ci

docker run --rm --user "$(id -u):$(id -g)" \
  --mount "type=bind,src=$PWD,dst=/workspace" \
  --workdir /workspace/src/tools/mermaid \
  -e npm_config_cache=/tmp/mermaid-npm node:24-bookworm-slim npm run build
```
