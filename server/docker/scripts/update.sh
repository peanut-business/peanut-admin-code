#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)
DOCKER_DIR=$(CDPATH= cd -- "$SCRIPT_DIR/.." && pwd -P)
SERVER_DIR=$(CDPATH= cd -- "$DOCKER_DIR/.." && pwd -P)

usage() {
    printf '%s\n' "Usage:" >&2
    printf '%s\n' "  server/docker/scripts/update.sh plan --archive=/absolute/server.tar.gz --expected-sha256=<trusted-64-hex> --workspace=/absolute/update-workspace" >&2
    printf '%s\n' "  server/docker/scripts/update.sh apply --workspace=/absolute/update-workspace" >&2
    printf '%s\n' "  server/docker/scripts/update.sh recover --workspace=/absolute/update-workspace" >&2
    exit 64
}

case "${1:-}" in
    plan) [ "$#" -eq 4 ] || usage ;;
    apply|recover) [ "$#" -eq 2 ] || usage ;;
    *) usage ;;
esac
command=$1
archive=
expected=
workspace=
shift
for argument in "$@"; do
    case "$argument" in
        --archive=/*) [ "$command" = plan ] || usage; archive=${argument#*=} ;;
        --expected-sha256=*) [ "$command" = plan ] || usage; expected=${argument#*=} ;;
        --workspace=/*) workspace=${argument#*=} ;;
        *) usage ;;
    esac
done
[ -n "$workspace" ] || usage
if [ "$command" = plan ]; then
    [ -n "$archive" ] || usage
    printf '%s' "$expected" | grep -Eq '^[a-f0-9]{64}$' || usage
    [ -f "$archive" ] && [ ! -L "$archive" ] || { echo "target archive is unavailable" >&2; exit 1; }
fi
[ -d "$workspace" ] && [ ! -L "$workspace" ] || { echo "update workspace is unavailable" >&2; exit 1; }
[ -f "$DOCKER_DIR/.env" ] && [ ! -L "$DOCKER_DIR/.env" ] || { echo "server/docker/.env is unavailable" >&2; exit 1; }

php_image=$(sed -n 's/^PHP_IMAGE=//p' "$DOCKER_DIR/.env" | tail -n 1)
printf '%s' "$php_image" | grep -Eq '^[A-Za-z0-9][A-Za-z0-9._/@:-]{0,255}$' || {
    echo "PHP_IMAGE is missing or unsafe" >&2
    exit 1
}

docker image inspect "$php_image" >/dev/null
if [ "$command" = plan ]; then
    exec docker run --rm --network none \
        --mount "type=bind,src=$SERVER_DIR,dst=/instance-server,readonly" \
        --mount "type=bind,src=$SCRIPT_DIR/update-plan.php,dst=/tool/update-plan.php,readonly" \
        --mount "type=bind,src=$archive,dst=/target.tar.gz,readonly" \
        --mount "type=bind,src=$workspace,dst=/workspace" \
        --entrypoint php "$php_image" /tool/update-plan.php plan \
        --instance-server=/instance-server --archive=/target.tar.gz \
        --expected-sha256="$expected" --workspace=/workspace
fi

exec docker run --rm --network none \
    --mount "type=bind,src=$SERVER_DIR,dst=/instance-server" \
    --mount "type=bind,src=$SCRIPT_DIR/update-plan.php,dst=/tool/update-plan.php,readonly" \
    --mount "type=bind,src=$workspace,dst=/workspace" \
    --entrypoint php "$php_image" /tool/update-plan.php "$command" \
    --instance-server=/instance-server --workspace=/workspace
