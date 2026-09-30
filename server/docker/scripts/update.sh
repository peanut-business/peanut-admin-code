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
workspace_real=$(CDPATH= cd -- "$workspace" && pwd -P)
[ "$workspace_real" = "$workspace" ] || { echo "update workspace must be canonical" >&2; exit 1; }
[ -f "$DOCKER_DIR/.env" ] && [ ! -L "$DOCKER_DIR/.env" ] || { echo "server/docker/.env is unavailable" >&2; exit 1; }

php_image=$(sed -n 's/^PHP_IMAGE=//p' "$DOCKER_DIR/.env" | tail -n 1)
printf '%s' "$php_image" | grep -Eq '^[A-Za-z0-9][A-Za-z0-9._/@:-]{0,255}$' || {
    echo "PHP_IMAGE is missing or unsafe" >&2
    exit 1
}

docker image inspect "$php_image" >/dev/null

trusted_tool="$workspace/.trusted-update-plan.php"
if [ "$command" = plan ]; then
    [ ! -e "$trusted_tool" ] && [ ! -L "$trusted_tool" ] || {
        echo "update workspace already contains a trusted tool" >&2
        exit 1
    }
    cp "$SCRIPT_DIR/update-plan.php" "$trusted_tool"
    chmod 0600 "$trusted_tool"
else
    [ -f "$trusted_tool" ] && [ ! -L "$trusted_tool" ] || {
        echo "planned trusted update tool is unavailable" >&2
        exit 1
    }
fi

run_update_tool() {
    phase=$1
    docker run --rm --network none \
        --mount "type=bind,src=$SERVER_DIR,dst=/instance-server" \
        --mount "type=bind,src=$trusted_tool,dst=/tool/update-plan.php,readonly" \
        --mount "type=bind,src=$workspace,dst=/workspace" \
        --entrypoint php "$php_image" /tool/update-plan.php "$phase" \
        --instance-server=/instance-server --workspace=/workspace
}

if [ "$command" = plan ]; then
    exec docker run --rm --network none \
        --mount "type=bind,src=$SERVER_DIR,dst=/instance-server,readonly" \
        --mount "type=bind,src=$trusted_tool,dst=/tool/update-plan.php,readonly" \
        --mount "type=bind,src=$archive,dst=/target.tar.gz,readonly" \
        --mount "type=bind,src=$workspace,dst=/workspace" \
        --entrypoint php "$php_image" /tool/update-plan.php plan \
        --instance-server=/instance-server --archive=/target.tar.gz \
        --expected-sha256="$expected" --workspace=/workspace
fi

compose() {
    docker compose --env-file .env -f compose.yaml "$@"
}

env_value() {
    key=$1
    sed -n "s/^$key=//p" "$DOCKER_DIR/.env" | tail -n 1
}

require_maintenance_marker() {
    marker="$SERVER_DIR/runtime/upgrade/maintenance.json"
    [ -f "$marker" ] && [ ! -L "$marker" ] || {
        echo "server update maintenance marker is not active" >&2
        exit 1
    }
}

wait_compose_health() {
    service=$1
    attempts=0
    while [ "$attempts" -lt 30 ]; do
        container_id=$(compose ps -q "$service" 2>/dev/null || true)
        if [ -n "$container_id" ]; then
            status=$(docker inspect --format '{{.State.Status}}' "$container_id" 2>/dev/null || true)
            health=$(docker inspect --format '{{.State.Health.Status}}' "$container_id" 2>/dev/null || true)
            if [ "$status" = running ] && { [ "$health" = healthy ] || [ "$health" = "" ]; }; then
                return 0
            fi
        fi
        attempts=$((attempts + 1))
        sleep 2
    done
    echo "compose service did not become healthy: $service" >&2
    return 1
}

http_healthz() {
    expected=$1
    bind=$(env_value HTTP_BIND)
    port=$(env_value HTTP_PORT)
    [ -n "$bind" ] || bind=127.0.0.1
    [ "$bind" = "0.0.0.0" ] && bind=127.0.0.1
    [ -n "$port" ] || port=21080
    actual=$(curl -sS --max-time 5 -o /dev/null -w '%{http_code}' "http://$bind:$port/healthz")
    [ "$actual" = "$expected" ] || {
        echo "public health route returned $actual while $expected was required" >&2
        return 1
    }
}

run_private_verifier() {
    phase=$1
    php_container=$(compose ps -q php)
    [ -n "$php_container" ] || { echo "php container is unavailable for private verification" >&2; return 1; }
    docker run --rm --network "container:$php_container" \
        --mount "type=bind,src=$SERVER_DIR,dst=/instance-server" \
        --mount "type=bind,src=$trusted_tool,dst=/tool/update-plan.php,readonly" \
        --mount "type=bind,src=$workspace,dst=/workspace" \
        --env PEANUT_SERVER_ENV_FILE=/instance-server/.env \
        --entrypoint php "$php_image" /tool/update-plan.php "$phase" \
        --instance-server=/instance-server --workspace=/workspace
}

verify_runtime() {
    phase=$1
    wait_compose_health php
    wait_compose_health nginx
    http_healthz 503
    run_private_verifier "$phase"
}

cd "$DOCKER_DIR"
compose config --quiet

case "$command" in
    apply)
        if run_update_tool begin; then
            next_action=apply
        else
            begin_code=$?
            case "$begin_code" in
                10) exit 0 ;;
                11) next_action=verify_activate ;;
                *) exit "$begin_code" ;;
            esac
        fi
        if [ "$next_action" = apply ]; then
            compose stop nginx php
            run_update_tool apply
        fi
        compose up -d --no-build php nginx
        require_maintenance_marker
        verify_runtime verify-applied
        run_update_tool activate
        http_healthz 200
        ;;
    recover)
        run_update_tool assert-recoverable
        compose stop nginx php
        run_update_tool recover
        compose up -d --no-build php nginx
        require_maintenance_marker
        verify_runtime verify-recovered
        run_update_tool finish-recovery
        http_healthz 200
        ;;
esac
