#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)
DOCKER_DIR=$(CDPATH= cd -- "$SCRIPT_DIR/.." && pwd -P)
SERVER_DIR=$(CDPATH= cd -- "$DOCKER_DIR/.." && pwd -P)

docker() {
    python3 - "$@" <<'PY'
import hashlib, shutil, subprocess, sys
binary = shutil.which('docker')
if binary is None: raise SystemExit('docker CLI is unavailable')
args = sys.argv[1:]
limit = 650 if args and args[0] == 'run' else (120 if args and args[0] == 'compose' else 30)
try:
    result = subprocess.run([binary, *args], stdout=sys.stdout.buffer, stderr=subprocess.PIPE, timeout=limit)
except subprocess.TimeoutExpired:
    print(f'docker command timed out after {limit}s', file=sys.stderr)
    raise SystemExit(124)
if result.returncode:
    print(f'docker command exit={result.returncode}; stderr_sha256={hashlib.sha256(result.stderr).hexdigest()}; stderr_bytes={len(result.stderr)}', file=sys.stderr)
raise SystemExit(result.returncode)
PY
}

usage() {
    printf '%s\n' "Usage:" >&2
    printf '%s\n' "  server/docker/scripts/update.sh plan --archive=/absolute/server.tar.gz --expected-sha256=<trusted-64-hex> --workspace=/absolute/update-workspace" >&2
    printf '%s\n' "  server/docker/scripts/update.sh apply --workspace=/absolute/update-workspace" >&2
    printf '%s\n' "  server/docker/scripts/update.sh recover --workspace=/absolute/update-workspace" >&2
    printf '%s\n' "  All commands accept --instance-server=/absolute/server for a separately verified maintenance tool." >&2
    exit 64
}

case "${1:-}" in
    plan) [ "$#" -ge 4 ] && [ "$#" -le 5 ] || usage ;;
    apply|recover) [ "$#" -ge 2 ] && [ "$#" -le 3 ] || usage ;;
    *) usage ;;
esac
command=$1
archive=
expected=
workspace=
instance_server=
shift
for argument in "$@"; do
    case "$argument" in
        --archive=/*) [ "$command" = plan ] || usage; archive=${argument#*=} ;;
        --expected-sha256=*) [ "$command" = plan ] || usage; expected=${argument#*=} ;;
        --workspace=/*) workspace=${argument#*=} ;;
        --instance-server=/*) [ -z "$instance_server" ] || usage; instance_server=${argument#*=} ;;
        *) usage ;;
    esac
done
if [ -n "$instance_server" ]; then
    [ -d "$instance_server" ] && [ ! -L "$instance_server" ] || { echo "instance server is unavailable" >&2; exit 1; }
    server_real=$(CDPATH= cd -- "$instance_server" && pwd -P)
    [ "$server_real" = "$instance_server" ] || { echo "instance server must be canonical" >&2; exit 1; }
    SERVER_DIR=$server_real
    DOCKER_DIR=$SERVER_DIR/docker
fi
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
printf '%s' "$php_image" | grep -Eq '^(sha256:[a-f0-9]{64}|[A-Za-z0-9][A-Za-z0-9._:/-]{0,160}@sha256:[a-f0-9]{64})$' || {
    echo "PHP_IMAGE must select a prepared immutable image" >&2
    exit 1
}

for image_key in PHP_IMAGE NGINX_IMAGE MYSQL_IMAGE; do
    image=$(sed -n "s/^$image_key=//p" "$DOCKER_DIR/.env" | tail -n 1)
    printf '%s' "$image" | grep -Eq '^(sha256:[a-f0-9]{64}|[A-Za-z0-9][A-Za-z0-9._:/-]{0,160}@sha256:[a-f0-9]{64})$' || {
        echo "$image_key must select a prepared immutable image" >&2
        exit 1
    }
    docker image inspect "$image" >/dev/null
done

trusted_tool="$workspace/.trusted-update-plan.php"
tool_dir="$workspace/.trusted-tools"
if [ "$command" = plan ]; then
    [ ! -e "$trusted_tool" ] && [ ! -L "$trusted_tool" ] || {
        echo "update workspace already contains a trusted tool" >&2
        exit 1
    }
    cp "$SCRIPT_DIR/update-plan.php" "$trusted_tool"
    chmod 0600 "$trusted_tool"
    [ ! -e "$tool_dir" ] && [ ! -L "$tool_dir" ] || { echo "update workspace already contains trusted runtime tools" >&2; exit 1; }
    mkdir -m 0700 "$tool_dir"
    cp "$SCRIPT_DIR/prepare-vendor.sh" "$SCRIPT_DIR/vendor-state.py" \
        "$SCRIPT_DIR/update-preparation.py" "$SCRIPT_DIR/update-recovery.py" \
        "$SCRIPT_DIR/update-database.php" "$tool_dir/"
    chmod 0700 "$tool_dir/prepare-vendor.sh"
    python3 - "$workspace" <<'PY'
import hashlib, json, os, pathlib, sys
root = pathlib.Path(sys.argv[1])
names = ['.trusted-update-plan.php'] + [str(path.relative_to(root)) for path in sorted((root / '.trusted-tools').iterdir())]
rows = {}
for name in names:
    path = root / name
    if path.is_symlink() or not path.is_file() or path.stat().st_nlink != 1: raise SystemExit('trusted tool has unsafe type')
    rows[name] = hashlib.sha256(path.read_bytes()).hexdigest()
path = root / '.trusted-tools/manifest.json'
with path.open('x') as output:
    os.fchmod(output.fileno(), 0o600)
    json.dump({'protocol':'peanut.server-update-tools.v1','files':rows}, output, sort_keys=True)
    output.flush(); os.fsync(output.fileno())
PY
else
    [ -f "$trusted_tool" ] && [ ! -L "$trusted_tool" ] || {
        echo "planned trusted update tool is unavailable" >&2
        exit 1
    }
    [ -d "$tool_dir" ] && [ ! -L "$tool_dir" ] || { echo "trusted runtime tools are unavailable" >&2; exit 1; }
fi

verify_trusted_tools() {
    python3 - "$workspace" <<'PY'
import hashlib, json, pathlib, sys
root = pathlib.Path(sys.argv[1]); manifest = root / '.trusted-tools/manifest.json'
if manifest.is_symlink() or not manifest.is_file() or manifest.stat().st_nlink != 1 or manifest.stat().st_mode & 0o777 != 0o600: raise SystemExit('trusted tool manifest is unsafe')
data = json.loads(manifest.read_text())
expected = {'.trusted-update-plan.php', '.trusted-tools/prepare-vendor.sh', '.trusted-tools/vendor-state.py', '.trusted-tools/update-preparation.py', '.trusted-tools/update-recovery.py', '.trusted-tools/update-database.php'}
if data.get('protocol') != 'peanut.server-update-tools.v1' or set(data.get('files', {})) != expected: raise SystemExit('trusted tool manifest is incomplete')
for name, digest in data['files'].items():
    path = root / name
    if path.is_symlink() or not path.is_file() or path.stat().st_nlink != 1 or hashlib.sha256(path.read_bytes()).hexdigest() != digest: raise SystemExit('trusted tool bytes changed')
plan = root / 'plan.json'
if plan.is_file() and json.loads(plan.read_text()).get('tools_sha256') != hashlib.sha256(manifest.read_bytes()).hexdigest(): raise SystemExit('trusted tools differ from update plan')
PY
}
verify_trusted_tools

prepare_dependencies() {
    if [ -f "$workspace/preparation.json" ]; then
        python3 "$tool_dir/update-preparation.py" check --server "$SERVER_DIR" --workspace "$workspace"
        return
    fi
    target="$workspace/prepared/server"
    if ! cmp -s "$SERVER_DIR/composer.lock" "$target/composer.lock" \
        || ! cmp -s "$SERVER_DIR/composer.json" "$target/composer.json" \
        || ! python3 "$tool_dir/vendor-state.py" check --server "$SERVER_DIR" >/dev/null 2>&1; then
        "$tool_dir/prepare-vendor.sh" prepared "$target" "$php_image"
    fi
    python3 "$tool_dir/update-preparation.py" record --server "$SERVER_DIR" --workspace "$workspace"
}

run_database() {
    phase=$1
    mysql_container=$(compose ps -q mysql)
    [ -n "$mysql_container" ] || { echo "mysql container is unavailable for native migration" >&2; return 1; }
    docker run --rm --network "container:$mysql_container" \
        --mount "type=bind,src=$SERVER_DIR,dst=/instance-server,readonly" \
        --mount "type=bind,src=$SERVER_DIR/runtime,dst=/instance-server/runtime" \
        --mount "type=bind,src=$workspace,dst=/workspace" \
        --mount "type=bind,src=$tool_dir/update-database.php,dst=/tool/update-database.php,readonly" \
        --entrypoint php "$php_image" /tool/update-database.php "$phase" /instance-server /workspace
}

prepare_nginx_configuration() {
    needs_nginx=$(python3 - "$workspace/plan.json" <<'PY'
import json, sys
print('yes' if json.load(open(sys.argv[1]))['requirements']['nginx_configuration_changed'] else 'no')
PY
)
    [ "$needs_nginx" = yes ] || return 0
    nginx_image=$(env_value NGINX_IMAGE)
    target_conf="$workspace/prepared/server/docker/conf/nginx.conf"
    docker run --rm --network none --add-host php:127.0.0.1 \
        --mount "type=bind,src=$target_conf,dst=/etc/nginx/nginx.conf,readonly" \
        --entrypoint nginx "$nginx_image" -t
    python3 - "$workspace" "$nginx_image" <<'PY'
import hashlib, json, os, pathlib, sys
root=pathlib.Path(sys.argv[1])
data={'protocol':'peanut.server-update-nginx-preparation.v1','status':'completed',
      'plan_sha256':hashlib.sha256((root/'plan.json').read_bytes()).hexdigest(),
      'configuration_sha256':hashlib.sha256((root/'prepared/server/docker/conf/nginx.conf').read_bytes()).hexdigest(),
      'image':sys.argv[2]}
path=root/'nginx-preparation.json'
if path.exists():
    assert not path.is_symlink() and path.stat().st_nlink==1 and json.loads(path.read_text())==data
else:
    with path.open('x') as output:
        os.fchmod(output.fileno(),0o600);json.dump(data,output,sort_keys=True);output.flush();os.fsync(output.fileno())
PY
}

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
        --mount "type=bind,src=$SERVER_DIR,dst=/instance-server,readonly" \
        --mount "type=bind,src=$SERVER_DIR/runtime,dst=/instance-server/runtime" \
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
        prepare_dependencies
        prepare_nginx_configuration
        if [ -f "$workspace/dependency-switch.json" ]; then
            switch_status=$(python3 - "$workspace/dependency-switch.json" <<'PY'
import json, sys
print(json.load(open(sys.argv[1])).get('status', 'invalid'))
PY
)
            if [ "$switch_status" = started ]; then
                run_update_tool assert-recoverable
                compose stop --timeout 60 nginx php
                python3 "$tool_dir/update-preparation.py" switch --server "$SERVER_DIR" --workspace "$workspace"
            fi
        fi
        if run_update_tool begin; then
            next_action=apply
        else
            begin_code=$?
            case "$begin_code" in
                10) exit 0 ;;
                11) next_action=verify_activate ;;
                12) next_action=migrate ;;
                *) exit "$begin_code" ;;
            esac
        fi
        if [ "$next_action" = apply ]; then
            compose stop --timeout 60 nginx php
            if [ ! -f "$workspace/backup.json" ]; then
                python3 "$tool_dir/update-recovery.py" backup --server "$SERVER_DIR" --workspace "$workspace"
            else
                python3 "$tool_dir/update-recovery.py" verify --server "$SERVER_DIR" --workspace "$workspace"
            fi
            python3 "$tool_dir/update-preparation.py" switch --server "$SERVER_DIR" --workspace "$workspace"
            run_update_tool apply
            run_database migrate
            run_database verify
        fi
        if [ "$next_action" = migrate ]; then
            compose stop --timeout 60 nginx php
            run_database migrate
            run_database verify
        fi
        compose up -d --no-build --force-recreate php nginx
        require_maintenance_marker
        verify_runtime verify-applied
        run_update_tool activate
        http_healthz 200
        ;;
    recover)
        run_update_tool assert-recoverable
        compose stop --timeout 60 nginx php
        if [ -f "$workspace/backup.json" ]; then
            python3 "$tool_dir/update-recovery.py" restore --server "$SERVER_DIR" --workspace "$workspace"
        fi
        run_update_tool recover
        python3 "$tool_dir/update-preparation.py" recover --server "$SERVER_DIR" --workspace "$workspace"
        compose up -d --no-build --force-recreate php nginx
        require_maintenance_marker
        verify_runtime verify-recovered
        run_update_tool finish-recovery
        http_healthz 200
        ;;
esac
