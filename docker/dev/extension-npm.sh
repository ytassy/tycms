#!/usr/bin/env bash
set -euo pipefail

cd -- "$(dirname -- "${BASH_SOURCE[0]}")"
source ./identity.sh
source ./extension-settings.sh

if [[ $# -lt 2 ]]; then
    echo 'Usage : ./docker/dev/extension-npm.sh plugins/Lms ci|run build|…' >&2
    exit 1
fi

# L’argument permet de construire une autre extension sans changer celle sélectionnée en DEV.
export DEV_EXTENSION=$1
shift
load_extension_settings
prepare_extension_storage
terminal_options=()
if [[ ! -t 0 || ! -t 1 ]]; then terminal_options=(-T); fi

# Ne jamais réinstaller des dépendances pendant que le serveur sélectionné les utilise.
running=$(docker compose --profile extension-dev ps --status running --quiet extension-vite)
if [[ -n $running ]]; then
    selected=$(docker inspect --format '{{range .Config.Env}}{{println .}}{{end}}' "$running" | sed -n 's/^DEV_EXTENSION=//p')
    if [[ $selected == "$DEV_EXTENSION" ]]; then
        if [[ ${1:-} == ci || ${1:-} == install || ${1:-} == update || ${1:-} == uninstall ]]; then
            echo 'Arrêter extension-vite avant de modifier ses dépendances.' >&2
            exit 1
        fi
        exec docker compose --profile extension-dev exec "${terminal_options[@]}" extension-vite npm "$@"
    fi
fi
exec docker compose --profile extension-tools run --rm --no-deps "${terminal_options[@]}" extension-frontend npm "$@"
