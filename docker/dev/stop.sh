#!/usr/bin/env bash
set -euo pipefail

cd -- "$(dirname -- "${BASH_SOURCE[0]}")"
source ./identity.sh

# Supprimer les conteneurs et le réseau ; les données sur disque sont conservées.
docker compose --profile dev --profile extension-dev --profile extension-tools down
