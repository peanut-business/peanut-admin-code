#!/bin/sh
set -eu
# Public server-release arguments map to the same installed product coordinator.
SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)
SERVER_DIR=$(CDPATH= cd -- "$SCRIPT_DIR/../.." && pwd -P)
usage() {
    echo 'Usage: update.sh plan --archive=/absolute/server.tar.gz --expected-sha256=SHA --workspace=/absolute/workspace [--instance-server=/absolute/server]' >&2
    echo '       update.sh plan --git-input=/absolute/fixed-git-source.json --expected-source-sha256=SHA --workspace=/absolute/workspace [--instance-server=/absolute/server]' >&2
    echo '       update.sh apply|verify|recover --workspace=/absolute/workspace [--instance-server=/absolute/server]' >&2
    exit 64
}
command=${1:-}; shift || usage
case "$command" in plan|apply|verify|recover) ;; *) usage ;; esac
archive= expected= git_input= source_sha= workspace= instance=
for argument in "$@"; do
    case "$argument" in
        --archive=/*) [ "$command" = plan ] && [ -z "$archive" ] || usage; archive=${argument#*=} ;;
        --expected-sha256=*) [ "$command" = plan ] && [ -z "$expected" ] || usage; expected=${argument#*=} ;;
        --git-input=/*) [ "$command" = plan ] && [ -z "$git_input" ] || usage; git_input=${argument#*=} ;;
        --expected-source-sha256=*) [ "$command" = plan ] && [ -z "$source_sha" ] || usage; source_sha=${argument#*=} ;;
        --workspace=/*) [ -z "$workspace" ] || usage; workspace=${argument#*=} ;;
        --instance-server=/*) [ -z "$instance" ] || usage; instance=${argument#*=} ;;
        *) usage ;;
    esac
done
[ -z "$instance" ] || SERVER_DIR=$instance
[ -d "$SERVER_DIR" ] && [ ! -L "$SERVER_DIR" ] || { echo 'instance server is unsafe' >&2; exit 1; }
[ "$(CDPATH= cd -- "$SERVER_DIR" && pwd -P)" = "$SERVER_DIR" ] || { echo 'instance server is not canonical' >&2; exit 1; }
APP_ROOT=$(CDPATH= cd -- "$SERVER_DIR/.." && pwd -P)
[ -n "$workspace" ] && [ -d "$workspace" ] && [ ! -L "$workspace" ] || usage
[ "$(CDPATH= cd -- "$workspace" && pwd -P)" = "$workspace" ] || { echo 'workspace is not canonical' >&2; exit 1; }
entry="$SERVER_DIR/docker/scripts/upgrade"
[ -f "$entry" ] && [ ! -L "$entry" ] || { echo 'installed product coordinator is unavailable' >&2; exit 1; }
php_cli=${PEANUT_UPGRADE_PHP:-}
case "$php_cli" in /*) ;; *) echo 'PEANUT_UPGRADE_PHP must select an installed absolute PHP CLI' >&2; exit 1 ;; esac
php_cli=$(realpath "$php_cli")
[ -x "$php_cli" ] && [ ! -L "$php_cli" ] || { echo 'installed PHP CLI is unavailable' >&2; exit 1; }
export PEANUT_SERVER_ENV_FILE="$SERVER_DIR/.env"
if [ "$command" = plan ]; then
    if [ -n "$git_input" ] || [ -n "$source_sha" ]; then
        [ -n "$git_input" ] && [ -n "$source_sha" ] && [ -z "$archive" ] && [ -z "$expected" ] || usage
        exec "$php_cli" "$entry" plan --scope=server --instance-root="$APP_ROOT" --package="$workspace" --git-input="$git_input" --expected-source-sha256="$source_sha"
    fi
    [ -n "$archive" ] && [ -n "$expected" ] || usage
    exec "$php_cli" "$entry" plan --scope=server --instance-root="$APP_ROOT" --package="$workspace" --archive="$archive" --expected-sha256="$expected"
fi
binding="$workspace/product-binding.json"
[ -f "$binding" ] && [ ! -L "$binding" ] || { echo 'product plan binding is unavailable' >&2; exit 1; }
plan=$("$php_cli" -r '$v=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); if (!is_string($v["plan_path"]??null)||!str_starts_with($v["plan_path"],"/")) exit(1); echo $v["plan_path"];' "$binding")
exec "$php_cli" "$entry" "$command" --scope=server --instance-root="$APP_ROOT" --package="$workspace" --plan="$plan"
