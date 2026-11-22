#!/usr/bin/env bash
set -euo pipefail

# Les chemins PHP relatifs sont résolus depuis src dans le conteneur Apache.
cd -- "$(dirname -- "${BASH_SOURCE[0]}")"

# Le wrapper PHP gère stdin ; transmettre chaque argument sans le découper.
exec ./php.sh releases/vdev/vendor/bin/phpunit --configuration releases/vdev/phpunit.xml "$@"
