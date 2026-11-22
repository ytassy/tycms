#!/bin/sh
set -eu

# Initialiser les dépendances à chaque démarrage du serveur, depuis le lockfile.
if [ "${1:-}" = "apache2-foreground" ]; then
    cd /var/www/html
    for identity in "${TYCMS_DEV_UID:-}" "${TYCMS_DEV_GID:-}"; do
        case "$identity" in ''|*[!0-9]*|0*) echo 'Identité DEV invalide.' >&2; exit 1 ;; esac
    done

    # Apache vérifie que les identifiants numériques correspondent à des comptes locaux.
    if ! getent group "$TYCMS_DEV_GID" >/dev/null; then
        groupadd -g "$TYCMS_DEV_GID" "tycmsdev_$TYCMS_DEV_GID"
    fi
    if ! getent passwd "$TYCMS_DEV_UID" >/dev/null; then
        useradd -M -u "$TYCMS_DEV_UID" -g "$TYCMS_DEV_GID" -d /tmp -s /usr/sbin/nologin "tycmsdev_$TYCMS_DEV_UID"
    fi

    # Apache démarre en root, puis ses processus PHP utilisent les identifiants de l'hôte.
    export APACHE_RUN_USER="#${TYCMS_DEV_UID}"
    export APACHE_RUN_GROUP="#${TYCMS_DEV_GID}"

    # Les dépendances et les fichiers applicatifs appartiennent au même utilisateur.
    setpriv --reuid "$TYCMS_DEV_UID" --regid "$TYCMS_DEV_GID" --clear-groups \
        mkdir -p var/updates logs tmp/migrations public/medias public/assets/fonts
    setpriv --reuid "$TYCMS_DEV_UID" --regid "$TYCMS_DEV_GID" --clear-groups \
        env HOME=/tmp COMPOSER_HOME=/tmp/tycms-composer \
        composer --working-dir=releases/vdev install --no-interaction

    # Refuser de démarrer si PHP ne peut pas réellement créer ses fichiers.
    setpriv --reuid "$TYCMS_DEV_UID" --regid "$TYCMS_DEV_GID" --clear-groups \
        php /usr/local/lib/dev-check-writable.php
fi

# Conserver le comportement de l’image PHP officielle et la gestion des signaux.
exec docker-php-entrypoint "$@"
