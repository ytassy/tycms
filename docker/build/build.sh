#!/usr/bin/env bash
set -euo pipefail

# Préparer la copie avant de lancer les outils tiers, sans leur exposer le dépôt.
repository=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)
if [[ $# -gt 1 || ( $# -eq 1 && "$1" != "--prepare-only" ) ]]; then
    echo "Usage : $0 [--prepare-only]" >&2
    exit 1
fi

# Empêcher un autre build de supprimer une copie encore en cours d'utilisation.
lock_path="$repository/build/.build.lock"
if [[ -L "$lock_path" || ( -e "$lock_path" && ! -f "$lock_path" ) ]]; then
    echo "Verrou de build invalide." >&2
    exit 1
fi
exec 9>"$lock_path"
if ! flock -n 9; then
    echo "Un build est déjà en cours." >&2
    exit 1
fi
if [[ -L "$repository/build/temp" ]]; then
    echo "Le dossier temporaire ne peut pas être un lien symbolique." >&2
    exit 1
fi
mkdir -p "$repository/build/temp" "$repository/build/dist"

# Le wrapper interactif efface les anciennes copies après confirmation ;
# le mode --prepare-only conserve la nouvelle copie pour inspection.
if [[ "${TYCMS_BUILD_CLEAN_TEMP:-}" == 1 ]]; then
    find "$repository/build/temp" -mindepth 1 -maxdepth 1 ! -name .gitkeep -exec rm -rf -- {} +
fi
docker build --tag tycms-build --file "$repository/docker/build/Dockerfile" "$repository/docker/build"

# La préparation utilise seulement notre PHP/Git, sans réseau ni outil tiers exécuté.
preparation=$(docker run --rm --network none --user "$(id -u):$(id -g)" \
    --mount "type=bind,src=$repository,dst=/repository,readonly" \
    --mount "type=bind,src=$repository/build/temp,dst=/repository/build/temp" \
    --entrypoint php tycms-build /repository/build/build.php --prepare-only)
printf '%s\n' "$preparation"
work=$(printf '%s\n' "$preparation" | tail -n 1)

# Convertir le chemin interne en chemin de montage sur l'hôte.
if [[ "$work" != /repository/build/temp/build-* ]]; then
    echo "Chemin de préparation inattendu." >&2
    exit 1
fi
work="$repository/${work#/repository/}"
echo "Copie de travail locale : $work"

if [[ "${1:-}" == "--prepare-only" ]]; then
    exit 0
fi
docker run --rm --user "$(id -u):$(id -g)" \
    --mount "type=bind,src=$repository/build/build.php,dst=/build-tools/build.php,readonly" \
    --mount "type=bind,src=$repository/build/functions.php,dst=/build-tools/functions.php,readonly" \
    --mount "type=bind,src=$repository/update_server/lib/Protocol.php,dst=/update_server/lib/Protocol.php,readonly" \
    --mount "type=bind,src=$work,dst=/work" \
    --mount "type=bind,src=$repository/build/dist,dst=/dist" \
    tycms-build

# Le build est terminé : libérer la copie de travail, sans supprimer le dossier suivi.
find "$repository/build/temp" -mindepth 1 -maxdepth 1 ! -name .gitkeep -exec rm -rf -- {} +
echo "Copie de travail supprimée."
