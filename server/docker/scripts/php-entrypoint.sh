#!/bin/sh
set -eu
SERVER_ROOT=/var/www/peanut-admin/server
cd "$SERVER_ROOT"
for path in runtime public/storage private/storage private/installation; do
    [ ! -L "$path" ] || { echo "runtime path is a symlink: $path" >&2; exit 1; }
    mkdir -p "$path"
done
[ -f .env ] && [ ! -L .env ] || { echo "server/.env is unavailable" >&2; exit 1; }
[ -f vendor/autoload.php ] || { echo "composer dependencies are unavailable" >&2; exit 1; }
export PEANUT_SERVER_ENV_FILE="$SERVER_ROOT/.env"
installation_mode=$(sed -n 's/^PEANUT_INSTALLATION_MODE=//p' .env | tail -n 1)
case "$installation_mode" in
  guided) ;;
  automatic)
    php database/environment-guard.php --wait=60
    php database/install.php --skip-if-installed
    php database/environment-guard.php --current
    ;;
  *) echo "PEANUT_INSTALLATION_MODE must be guided or automatic" >&2; exit 1 ;;
esac
exec php-fpm -F
