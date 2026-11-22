#!/usr/bin/env bash

# Partager l'identité de l'utilisateur hôte avec les conteneurs de développement.
if [[ -z ${TYCMS_DEV_UID:-} && -z ${TYCMS_DEV_GID:-} ]]; then
    if [[ $(id -u) == 0 ]]; then
        TYCMS_DEV_UID=${SUDO_UID:-}
        TYCMS_DEV_GID=${SUDO_GID:-}
    else
        TYCMS_DEV_UID=$(id -u)
        TYCMS_DEV_GID=$(id -g)
    fi
fi
if [[ ! ${TYCMS_DEV_UID:-} =~ ^[1-9][0-9]{0,9}$ || ! ${TYCMS_DEV_GID:-} =~ ^[1-9][0-9]{0,9}$ ]] \
    || (( TYCMS_DEV_UID > 2147483647 || TYCMS_DEV_GID > 2147483647 )); then
    echo 'Identité DEV invalide : utiliser un compte non root ou définir TYCMS_DEV_UID et TYCMS_DEV_GID.' >&2
    return 1 2>/dev/null || exit 1
fi
export TYCMS_DEV_UID TYCMS_DEV_GID
