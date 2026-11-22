#!/usr/bin/env bash
set -euo pipefail

# Déléguer au build isolé, quel que soit le dossier courant du terminal.
build_directory=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)

# Montrer la source de vérité avant de lancer les opérations Docker coûteuses.
if [[ $# -eq 0 ]]; then
    echo "Paramètres du build (version.json) :"
    cat -- "$build_directory/../version.json"
    printf '\nLancer le build avec ces paramètres ? [o/N] : '
    build_answer=''
    if ! IFS= read -r build_answer || [[ ! "$build_answer" =~ ^([oO]|[oO][uU][iI])$ ]]; then
        echo "Build annulé."
        exit 0
    fi
fi

# Le nettoyage initial et final est réalisé sous le même verrou que les deux étapes Docker.
export TYCMS_BUILD_CLEAN_TEMP=1
exec bash "$build_directory/../docker/build/build.sh" "$@"
