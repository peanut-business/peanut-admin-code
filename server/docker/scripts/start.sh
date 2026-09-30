#!/bin/sh
set -eu
SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)
DOCKER_DIR=$(CDPATH= cd -- "$SCRIPT_DIR/.." && pwd -P)
SERVER_DIR=$(CDPATH= cd -- "$DOCKER_DIR/.." && pwd -P)
file_links() { stat -f '%l' "$1" 2>/dev/null || stat -c '%h' "$1"; }
file_mode() { stat -f '%Lp' "$1" 2>/dev/null || stat -c '%a' "$1"; }
[ -f "$DOCKER_DIR/.env" ] && [ ! -L "$DOCKER_DIR/.env" ] && [ "$(file_links "$DOCKER_DIR/.env")" = 1 ] || {
    echo "server/docker/.env is required; create it from the released orchestration template" >&2
    exit 1
}
umask 077
for path in "$SERVER_DIR/public" "$SERVER_DIR/private" "$SERVER_DIR/docker" \
    "$DOCKER_DIR/mysql" "$DOCKER_DIR/secrets" "$SERVER_DIR/runtime" \
    "$SERVER_DIR/public/storage" "$SERVER_DIR/private/storage" \
    "$SERVER_DIR/private/installation" "$SERVER_DIR/private/resources"; do
    [ ! -L "$path" ] && { [ ! -e "$path" ] || [ -d "$path" ]; } || {
        echo "protected directory is linked or has an unsafe type: $path" >&2; exit 1;
    }
done
for path in "$SERVER_DIR/private/installation/installed.json" \
    "$DOCKER_DIR/secrets/mysql-root-password" "$DOCKER_DIR/secrets/install-token"; do
    if [ -e "$path" ] || [ -L "$path" ]; then
        [ -f "$path" ] && [ ! -L "$path" ] && [ "$(file_links "$path")" = 1 ] || {
            echo "protected state file is unsafe: $path" >&2; exit 1;
        }
    fi
done

if [ -e "$SERVER_DIR/.env" ]; then
    [ -f "$SERVER_DIR/.env" ] && [ ! -L "$SERVER_DIR/.env" ] && [ "$(file_links "$SERVER_DIR/.env")" = 1 ] || { echo "server/.env is unsafe" >&2; exit 1; }
    mode=$(file_mode "$SERVER_DIR/.env")
    [ "$mode" = "600" ] || { echo "server/.env must have mode 0600" >&2; exit 1; }
fi
php_image=$(sed -n 's/^PHP_IMAGE=//p' "$DOCKER_DIR/.env" | tail -n 1)
printf '%s' "$php_image" | grep -Eq '^(sha256:[a-f0-9]{64}|[A-Za-z0-9][A-Za-z0-9._:/-]{0,160}@sha256:[a-f0-9]{64})$' || {
    echo "PHP_IMAGE must be a prepared immutable image ID" >&2
    exit 1
}
mkdir -p "$SERVER_DIR/runtime"
"$SCRIPT_DIR/prepare-vendor.sh" start "$SERVER_DIR" "$php_image"

mkdir -p "$DOCKER_DIR/mysql" "$DOCKER_DIR/secrets" \
    "$SERVER_DIR/public/storage" "$SERVER_DIR/private/storage" \
    "$SERVER_DIR/private/installation" "$SERVER_DIR/private/resources"
installed="$SERVER_DIR/private/installation/installed.json"
root_secret="$DOCKER_DIR/secrets/mysql-root-password"
if [ ! -f "$root_secret" ]; then
    if [ -e "$installed" ] || [ -L "$installed" ] \
        || [ -n "$(find "$DOCKER_DIR/mysql" ! -path "$DOCKER_DIR/mysql" -print -quit)" ]; then
        echo "existing instance or MySQL data has no root secret; refusing to generate a replacement" >&2
        exit 1
    fi
    command -v openssl >/dev/null 2>&1 || { echo "openssl is required to generate the MySQL bootstrap secret" >&2; exit 1; }
    temporary="$root_secret.tmp-$$"
    openssl rand -hex 32 > "$temporary"
    chmod 600 "$temporary"
    mv "$temporary" "$root_secret"
fi
[ "$(file_mode "$root_secret")" = 600 ] || { echo "MySQL root secret must have mode 0600" >&2; exit 1; }

token="$DOCKER_DIR/secrets/install-token"
if [ ! -e "$installed" ]; then
    if [ ! -f "$token" ]; then
        for marker in executing.json baseline.json migration.json deployment.json; do
            [ ! -e "$SERVER_DIR/private/installation/$marker" ] && [ ! -L "$SERVER_DIR/private/installation/$marker" ] || {
                echo "installation state exists without its original token" >&2; exit 1;
            }
        done
        command -v openssl >/dev/null 2>&1 || { echo "openssl is required to generate the installation token" >&2; exit 1; }
        temporary="$token.tmp-$$"
        openssl rand -hex 32 > "$temporary"
        chmod 600 "$temporary"
        mv "$temporary" "$token"
    fi
    [ "$(file_mode "$token")" = 600 ] || { echo "installation token must have mode 0600" >&2; exit 1; }
else
    rm -f "$token"
fi

cd "$DOCKER_DIR"
docker compose --env-file .env -f compose.yaml "$@" config --quiet
docker compose --env-file .env -f compose.yaml "$@" up -d --no-build
if [ ! -e "$installed" ]; then
    printf 'Peanut installation bootstrap is active. Read the one-time token from: %s\n' "$DOCKER_DIR/secrets/install-token"
    printf 'Open /install/ on the configured HTTP endpoint. The token value is not printed to logs.\n'
fi
