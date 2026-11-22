#!/usr/bin/env bash
set -Eeuo pipefail

stage='initialisation'
trap 'status=$?; printf "Build arrêté à l’étape « %s » (code %s).\n" "$stage" "$status" >&2; exit "$status"' ERR

repository=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
if [[ $# -gt 1 || ( $# -eq 1 && ! $1 =~ ^(plugins|modules|templates)/[A-Z][A-Za-z0-9]{0,63}$ ) ]]; then
    echo "Usage : ./build_extensions/build.sh [plugins/Lms]" >&2
    exit 1
fi
# Signaler les fichiers manquants avant de démarrer Docker ou une compilation.
for required in docker/dev/identity.sh docker/build/Dockerfile build_extensions/build.php \
    build_extensions/functions.php build_extensions/Protocol.php build_extensions/sign.php \
    src/releases/vdev/framework/Extensions/ExtensionMetadata.php \
    src/releases/vdev/framework/Extensions/ExtensionPackage.php; do
    if [[ ! -f $repository/$required || ! -r $repository/$required ]]; then
        echo "Fichier de build requis absent ou illisible : $repository/$required" >&2
        exit 1
    fi
done
source "$repository/docker/dev/identity.sh"
command -v php >/dev/null || { echo 'PHP local avec sodium et zip requis pour signer.' >&2; exit 1; }
command -v docker >/dev/null || { echo 'Installer Docker Engine et démarrer son service.' >&2; exit 1; }
command -v flock >/dev/null || { echo 'Commande flock introuvable : installer util-linux pour verrouiller le build.' >&2; exit 1; }

# Un verrou propre aux extensions laisse le build système indépendant.
for directory in "$repository/build_extensions/temp" "$repository/build_extensions/dist"; do
    if [[ -L $directory || ( -e $directory && ! -d $directory ) ]]; then echo "Dossier de build invalide : $directory" >&2; exit 1; fi
    mkdir -p "$directory"
done
lock_file="$repository/build_extensions/.build.lock"
if [[ -L $lock_file || ( -e $lock_file && ! -f $lock_file ) ]]; then echo "Verrou de build invalide : $lock_file" >&2; exit 1; fi
exec 9>"$lock_file"
flock -n 9 || { echo 'Un build d’extension est déjà en cours.' >&2; exit 1; }

# Même image et mêmes outils que TyCMS, avec un point d’entrée PHP adapté.
stage='image Docker de build'
docker build --tag tycms-build --file "$repository/docker/build/Dockerfile" "$repository/docker/build"
selection_arguments=(--select)
if [[ $# -eq 1 ]]; then selection_arguments+=("$1"); fi
stage='sélection de l’extension'
selection=$(docker run --rm -i --network none --user "$TYCMS_DEV_UID:$TYCMS_DEV_GID" \
    --mount "type=bind,src=$repository,dst=/repository,readonly" \
    --entrypoint php tycms-build /repository/build_extensions/build.php "${selection_arguments[@]}")
if [[ -z $selection ]]; then echo 'Build annulé.'; exit 0; fi
if [[ ! $selection =~ ^(plugins|modules|templates)/[A-Z][A-Za-z0-9]{0,63}$ ]]; then echo 'Sélection invalide.' >&2; exit 1; fi

# Vérifier la configuration avant npm, sans la transmettre aux conteneurs.
stage='configuration de signature'
php "$repository/build_extensions/sign.php" --check
stage='préparation des sources'
prepared=$(docker run --rm --network none --user "$TYCMS_DEV_UID:$TYCMS_DEV_GID" \
    --mount "type=bind,src=$repository,dst=/repository,readonly" \
    --mount "type=bind,src=$repository/build_extensions/temp,dst=/repository/build_extensions/temp" \
    --entrypoint php tycms-build /repository/build_extensions/build.php --prepare "$selection")
if [[ ! $prepared =~ ^/repository/build_extensions/temp/(plg|mod|tpl)_[a-z0-9_]+-[0-9]+_[0-9]+_[0-9]+-[a-f0-9]{16}$ ]]; then
    echo 'Chemin de préparation inattendu.' >&2; exit 1
fi
work="$repository/${prepared#/repository/}"
echo "Copie isolée : $work"

# Garder une copie du plan hors du montage npm : il ne peut pas changer l’identité signée.
expected_file=$(mktemp "$repository/build_extensions/temp/.expected-XXXXXXXX")
trap 'rm -f -- "$expected_file"' EXIT
cp -- "$work/build-plan.json" "$expected_file"

# Monter seulement les outils contrôlés et la copie : ni dépôt, ni clé, ni dist.
stage='compilation et assemblage ZIP'
docker run --rm --user "$TYCMS_DEV_UID:$TYCMS_DEV_GID" \
    --mount "type=bind,src=$repository/build_extensions/build.php,dst=/build-tools/build.php,readonly" \
    --mount "type=bind,src=$repository/build_extensions/functions.php,dst=/build-tools/functions.php,readonly" \
    --mount "type=bind,src=$repository/build_extensions/Protocol.php,dst=/build-tools/Protocol.php,readonly" \
    --mount "type=bind,src=$repository/src/releases/vdev/framework/Extensions/ExtensionMetadata.php,dst=/src/releases/vdev/framework/Extensions/ExtensionMetadata.php,readonly" \
    --mount "type=bind,src=$repository/src/releases/vdev/framework/Extensions/ExtensionPackage.php,dst=/src/releases/vdev/framework/Extensions/ExtensionPackage.php,readonly" \
    --mount "type=bind,src=$work,dst=/work" \
    --entrypoint php tycms-build /build-tools/build.php --compile /work

# Le conteneur npm a terminé : seule la commande hôte lit alors la clé du Store.
stage='contrôle et signature des artefacts'
php "$repository/build_extensions/sign.php" "$work" "$expected_file"

# Le build est terminé : libérer la copie de travail, sans supprimer le dossier suivi.
stage='nettoyage des fichiers temporaires'
find "$repository/build_extensions/temp" -mindepth 1 -maxdepth 1 ! -name .gitkeep -exec rm -rf -- {} +
echo "Copie de travail supprimée."
