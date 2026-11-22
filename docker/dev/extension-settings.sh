#!/usr/bin/env bash

# Lire Compose sans exécuter le .env ; chaque appelant a déjà chargé identity.sh.
load_extension_settings() {
    local resolved
    resolved=$(docker compose config --environment)
    DEV_EXTENSION=${DEV_EXTENSION:-$(sed -n 's/^DEV_EXTENSION=//p' <<< "$resolved")}
    DEV_EXTENSION_VITE_PORT=${DEV_EXTENSION_VITE_PORT:-$(sed -n 's/^DEV_EXTENSION_VITE_PORT=//p' <<< "$resolved")}
    DEV_EXTENSION_VITE_PORT=${DEV_EXTENSION_VITE_PORT:-3001}
    DEV_CORE_VITE=${DEV_CORE_VITE:-$(sed -n 's/^DEV_CORE_VITE=//p' <<< "$resolved")}
    DEV_CORE_VITE=${DEV_CORE_VITE:-true}

    if [[ ! ${DEV_CORE_VITE,,} =~ ^(true|false)$ || ! $DEV_EXTENSION_VITE_PORT =~ ^[1-9][0-9]{3,4}$ ]] \
        || (( DEV_EXTENSION_VITE_PORT < 1024 || DEV_EXTENSION_VITE_PORT > 65535 || DEV_EXTENSION_VITE_PORT == 3000 )); then
        echo 'DEV_CORE_VITE ou DEV_EXTENSION_VITE_PORT invalide.' >&2
        return 1
    fi
    if [[ -n $DEV_EXTENSION ]]; then validate_extension "$DEV_EXTENSION"; fi
    export DEV_EXTENSION DEV_EXTENSION_VITE_PORT DEV_CORE_VITE
}

validate_extension() {
    local extension=$1 root frontend
    if [[ ! $extension =~ ^(plugins|modules|templates)/[A-Z][A-Za-z0-9]*$ ]]; then
        echo 'Extension attendue : plugins/Lms, modules/MonModule ou templates/MonTheme.' >&2
        return 1
    fi
    root=$(realpath ../../src/extensions)
    frontend=$(realpath -e "../../src/extensions/$extension/frontend") || return 1
    if [[ $frontend != "$root/$extension/frontend" || ! -f $frontend/package.json ]]; then
        echo 'Frontend absent, redirigé par un lien symbolique ou dépourvu de package.json.' >&2
        return 1
    fi
}

prepare_extension_storage() {
    local directory="containers_datas/extensions/$DEV_EXTENSION/node_modules"
    mkdir -p "$directory"
    if [[ $(id -u) == 0 ]]; then chown -R "$TYCMS_DEV_UID:$TYCMS_DEV_GID" "containers_datas/extensions"; fi
    if [[ ! -w $directory ]]; then
        echo "Corriger les droits de $directory pour l’utilisateur $TYCMS_DEV_UID:$TYCMS_DEV_GID." >&2
        return 1
    fi
}
