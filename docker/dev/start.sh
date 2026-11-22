#!/usr/bin/env bash
set -euo pipefail

# Résoudre les chemins même si le script est lancé depuis un autre dossier.
cd -- "$(dirname -- "${BASH_SOURCE[0]}")"
source ./identity.sh
source ./extension-settings.sh
command -v docker >/dev/null || { echo "Installer Docker Engine et le plugin Docker Compose." >&2; exit 1; }
docker compose version >/dev/null

# Conserver les identifiants et la clé de chiffrement entre les redémarrages.
if [[ ! -f .env ]]; then
    (umask 077; cp .env.example .env)
    if [[ $(id -u) == 0 ]]; then chown "$TYCMS_DEV_UID:$TYCMS_DEV_GID" .env; fi
fi
if ! grep -q '^APP_SECRET=' .env; then
    secret=$(od -An -N32 -tx1 /dev/urandom | tr -d ' \n')
    printf '\nAPP_SECRET=%s\n' "$secret" >> .env
fi
for directory in containers_datas containers_datas/{mariadb,mailpit,logs,tmp,node_modules,npm}; do
    if [[ ! -d $directory ]]; then
        mkdir -p "$directory"
        if [[ $(id -u) == 0 ]]; then chown "$TYCMS_DEV_UID:$TYCMS_DEV_GID" "$directory"; fi
    fi
done

# Lire le mode résolu par Compose, sans exécuter le contenu du fichier .env.
dev_mode=$(docker compose config --environment | sed -n 's/^APP_DEV=//p')
dev_mode=${dev_mode:-true}
case "${dev_mode,,}" in
    true|false) ;;
    *) echo "APP_DEV doit valoir true ou false dans .env." >&2; exit 1 ;;
esac

# Valider les chemins avant que Docker ne les interprète comme montages.
load_extension_settings
if [[ -n $DEV_EXTENSION ]]; then
    prepare_extension_storage
    if [[ ! -f ../../src/extensions/$DEV_EXTENSION/frontend/package-lock.json ]]; then
        echo "Créer le lockfile : ./docker/dev/extension-npm.sh $DEV_EXTENSION install --package-lock-only" >&2
        exit 1
    fi
fi

# Construire Apache et la surcharge Adminer avec son éditeur JSON local.
docker compose config --quiet
docker compose build apache adminer

# Créer le schéma et le compte de test, même avec un volume MariaDB existant.
# Cette étape ne réinitialise aucune table : le reset du banc reste explicite.
docker compose up -d --wait mariadb
docker compose exec -T mariadb sh /usr/local/bin/tycms-test-db.sh

# Arrêter Vite avant npm ci, car le build partage ses dépendances.
docker compose --profile dev --profile extension-dev stop vite extension-vite

if [[ "${dev_mode,,}" == true && "${DEV_CORE_VITE,,}" == true ]]; then
    # Recréer Vite pour prendre en compte le lockfile après un git pull.
    docker compose --profile dev up -d --wait --force-recreate vite
else
    docker compose run --rm --no-deps frontend
fi

if [[ "${dev_mode,,}" == true && -n $DEV_EXTENSION ]]; then
    docker compose --profile extension-dev up -d --wait --force-recreate extension-vite
fi

# L’image Apache installe Composer et prépare les droits avant de servir le site.
docker compose up -d --wait

echo "Environnement démarré. Adresses et configuration : docker/dev/README.md"
docker compose ps
