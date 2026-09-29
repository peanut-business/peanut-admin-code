#!/bin/sh
set -eu
SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)
DOCKER_DIR=$(CDPATH= cd -- "$SCRIPT_DIR/.." && pwd -P)
SERVER_DIR=$(CDPATH= cd -- "$DOCKER_DIR/.." && pwd -P)
[ -f "$DOCKER_DIR/.env" ] && [ ! -L "$DOCKER_DIR/.env" ] || {
    echo "server/docker/.env is required; create it from the released orchestration template" >&2
    exit 1
}
umask 077
mkdir -p "$DOCKER_DIR/mysql" "$DOCKER_DIR/secrets" \
    "$SERVER_DIR/runtime" "$SERVER_DIR/public/storage" "$SERVER_DIR/private/storage" "$SERVER_DIR/private/installation"
if [ ! -f "$DOCKER_DIR/secrets/mysql-root-password" ]; then
    command -v openssl >/dev/null 2>&1 || { echo "openssl is required to generate the MySQL bootstrap secret" >&2; exit 1; }
    openssl rand -hex 32 > "$DOCKER_DIR/secrets/mysql-root-password"
fi
chmod 600 "$DOCKER_DIR/secrets/mysql-root-password"
[ -f "$SERVER_DIR/.env" ] && [ ! -L "$SERVER_DIR/.env" ] || {
    echo "server/.env is not configured; browser database/configuration bootstrap has not passed its Gate" >&2
    exit 1
}
mode=$(stat -f '%Lp' "$SERVER_DIR/.env" 2>/dev/null || stat -c '%a' "$SERVER_DIR/.env")
[ "$mode" = "600" ] || { echo "server/.env must have mode 0600" >&2; exit 1; }
[ -f "$SERVER_DIR/vendor/autoload.php" ] || {
    echo "server/vendor is missing; install the exact composer.lock dependencies before start" >&2
    exit 1
}
cd "$DOCKER_DIR"
docker compose --env-file .env -f compose.yaml "$@" config --quiet
exec docker compose --env-file .env -f compose.yaml "$@" up -d --no-build
