#!/bin/sh
set -eu

# The caller supplies a verified runtime image ID. Composer runs only against
# this server's composer.lock and never executes package plugins or scripts.
[ "$#" -eq 3 ] || { echo 'Usage: prepare-vendor.sh start|prepared SERVER_DIR PHP_IMAGE' >&2; exit 64; }
mode=$1
server=$2
php_image=$3
case "$mode" in start|prepared) ;; *) exit 64 ;; esac
case "$server" in /*) ;; *) exit 64 ;; esac
[ -d "$server" ] && [ ! -L "$server" ] || { echo 'server root is unsafe' >&2; exit 1; }
printf '%s' "$php_image" | grep -Eq '^(sha256:[a-f0-9]{64}|[A-Za-z0-9][A-Za-z0-9._:/-]{0,160}@sha256:[a-f0-9]{64})$' || {
    echo 'PHP_IMAGE must be an immutable digest reference' >&2; exit 1;
}
script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)
command -v python3 >/dev/null 2>&1 || { echo 'python3 is required for vendor verification' >&2; exit 1; }
if python3 "$script_dir/vendor-state.py" check --server "$server"; then
    exit 0
fi
if [ -e "$server/vendor" ] || [ -L "$server/vendor" ]; then
    echo 'vendor is present but incomplete or damaged' >&2
else
    echo 'vendor is missing' >&2
fi
if [ "$mode" = start ] && { [ -e "$server/private/installation/installed.json" ] || [ -L "$server/private/installation/installed.json" ]; }; then
    echo 'installed instance requires a stopped-writer maintenance update to repair vendor; normal start leaves it unchanged' >&2
    exit 1
fi
if [ "$mode" = prepared ]; then
    case "$server" in */prepared/server) ;; *) echo 'prepared mode requires a prepared/server workspace' >&2; exit 1 ;; esac
fi
docker image inspect "$php_image" >/dev/null
work=$server
if [ "$mode" = start ]; then
    runtime="$server/runtime"
    [ -d "$runtime" ] && [ ! -L "$runtime" ] || { echo 'runtime staging root is unsafe' >&2; exit 1; }
    work="$runtime/vendor-stage-$$"
    python3 "$script_dir/vendor-state.py" stage --server "$server" --destination "$work"
else
    [ ! -L "$server/vendor" ] || { echo 'prepared vendor is unsafe' >&2; exit 1; }
    if [ -d "$server/vendor" ]; then
        rm -r -- "$server/vendor"
    fi
fi

if ! docker run --rm --network bridge \
    --mount "type=bind,src=$work,dst=/srv" --workdir /srv --entrypoint /bin/sh "$php_image" -ec '
      composer --version --no-ansi | grep -Eq "^Composer version 2[.]10[.]2 "
      printf "%s  %s\n" 5ee7125f8a30a34d246cefdc0bc85b8a783b28f2aec968994118512350d28027 /usr/local/bin/composer | sha256sum -c -
      COMPOSER_ALLOW_SUPERUSER=1 composer validate --no-check-publish
      COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --no-plugins
      php docker/scripts/prepare-vendor.php
      COMPOSER_ALLOW_SUPERUSER=1 composer check-platform-reqs --no-dev
    '
then
    exit 1
fi
python3 "$script_dir/vendor-state.py" seal --server "$work"
python3 "$script_dir/vendor-state.py" check --server "$work"

if [ "$mode" = start ]; then
    old="$runtime/vendor-previous-$$"
    if [ -e "$server/vendor" ] || [ -L "$server/vendor" ]; then
        [ -d "$server/vendor" ] && [ ! -L "$server/vendor" ] || { echo 'existing vendor is unsafe' >&2; exit 1; }
        mv -- "$server/vendor" "$old"
    fi
    if ! mv -- "$work/vendor" "$server/vendor"; then
        [ ! -d "$old" ] || mv -- "$old" "$server/vendor"
        echo 'prepared vendor could not be activated' >&2
        exit 1
    fi
    python3 "$script_dir/vendor-state.py" check --server "$server"
    rm -r -- "$work"
fi
