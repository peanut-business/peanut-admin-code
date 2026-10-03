#!/bin/sh
set -eu
SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)
DOCKER_DIR=$(CDPATH= cd -- "$SCRIPT_DIR/.." && pwd -P)
[ -f "$DOCKER_DIR/.env" ] && [ ! -L "$DOCKER_DIR/.env" ] || { echo "docker .env is unavailable" >&2; exit 1; }
cd "$DOCKER_DIR"
exec docker compose --env-file .env -f compose.yaml stop
