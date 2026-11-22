#!/usr/bin/env bash
set -euo pipefail

# Les chemins PHP relatifs sont résolus depuis src dans le conteneur Apache.
cd -- "$(dirname -- "${BASH_SOURCE[0]}")"
source ./identity.sh

# Conserver stdin pour php -r, les pipes et les suites lancées par un agent.
terminal_options=()
if [[ ! -t 0 || ! -t 1 ]]; then
    terminal_options=(-T)
fi

# Réutiliser l’environnement actif ; rester utilisable après stop.sh.
running=$(docker compose ps --status running --quiet apache)
if [[ -n "$running" ]]; then
    exec docker compose exec "${terminal_options[@]}" --user "$TYCMS_DEV_UID:$TYCMS_DEV_GID" apache php "$@"
fi

exec docker compose run --rm --no-deps "${terminal_options[@]}" --user "$TYCMS_DEV_UID:$TYCMS_DEV_GID" apache php "$@"
