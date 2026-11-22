#!/usr/bin/env bash
set -euo pipefail

# Utiliser les sources frontend du projet, quel que soit le dossier de l’appelant.
cd -- "$(dirname -- "${BASH_SOURCE[0]}")"
source ./identity.sh

# Préserver les pipes et les appels automatisés sans imposer de terminal.
terminal_options=()
if [[ ! -t 0 || ! -t 1 ]]; then
    terminal_options=(-T)
fi

# Réutiliser Node de Vite, ou créer un conteneur ponctuel en mode compilé.
running=$(docker compose --profile dev ps --status running --quiet vite)
if [[ -n "$running" ]]; then
    exec docker compose --profile dev exec "${terminal_options[@]}" --user "$TYCMS_DEV_UID:$TYCMS_DEV_GID" vite npm "$@"
fi

exec docker compose run --rm --no-deps "${terminal_options[@]}" frontend npm "$@"
