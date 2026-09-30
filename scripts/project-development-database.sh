#!/bin/sh

set -eu

repo_dir=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
remote=mac-14
remote_dir=/Users/xing/.config/peanut-admin
remote_env="$remote_dir/development-db.env"
remote_compose="$remote_dir/docker-compose.remote-development.yml"
local_env="$repo_dir/server/.env"
local_state=$(dirname "$local_env")
allow_empty_initialize=0

command=${1:-}
[ $# -gt 0 ] && shift
while [ $# -gt 0 ]; do
    case "$1" in
        --backend-env) [ $# -ge 2 ] || { printf '%s\n' '--backend-env requires a value' >&2; exit 2; }; local_env=$2; local_state=$(dirname "$local_env"); shift 2 ;;
        --initialize-empty) allow_empty_initialize=1; shift ;;
        *) printf 'unsupported argument: %s\n' "$1" >&2; exit 2 ;;
    esac
done

normalize_remote_credentials() {
    ssh -o BatchMode=yes "$remote" /bin/sh -s -- "$remote_env" <<'REMOTE'
set -eu
file=$1
[ -f "$file" ] && [ ! -L "$file" ] || { printf '%s\n' 'registered database credential file is missing or unsafe' >&2; exit 1; }
mode=$(/usr/bin/stat -f %Lp "$file")
owner=$(/usr/bin/stat -f %u "$file")
links=$(/usr/bin/stat -f %l "$file")
[ "$mode" = 600 ] && [ "$owner" = "$(/usr/bin/id -u)" ] && [ "$links" = 1 ] || {
    printf '%s\n' 'registered database credential file permissions or ownership are unsafe' >&2
    exit 1
}
canonical=$(awk -F= '$1 == "MYSQL_ROOT_PASSWORD" { sub(/^[^=]*=/, ""); print; exit }' "$file")
legacy=$(awk -F= '$1 == "DB_ROOT_PASS" { sub(/^[^=]*=/, ""); print; exit }' "$file")
if [ -n "$canonical" ] && [ -n "$legacy" ] && [ "$canonical" != "$legacy" ]; then
    printf '%s\n' 'registered database root credential aliases conflict' >&2
    exit 1
fi
root_password=$canonical
[ -n "$root_password" ] || root_password=$legacy
[ -n "$root_password" ] || { printf '%s\n' 'registered database root credential is missing' >&2; exit 1; }
if [ -n "$legacy" ]; then
    backup="${file}.before-mysql-root-password-migration"
    if [ ! -e "$backup" ]; then
        cp -p "$file" "$backup"
        chmod 600 "$backup"
    fi
fi
temporary="${file}.normalize.$$"
trap 'rm -f "$temporary"' EXIT HUP INT TERM
awk -F= '$1 != "DB_ROOT_PASS" && $1 != "MYSQL_ROOT_PASSWORD" { print }' "$file" > "$temporary"
printf 'MYSQL_ROOT_PASSWORD=%s\n' "$root_password" >> "$temporary"
chmod 600 "$temporary"
mv "$temporary" "$file"
trap - EXIT HUP INT TERM
REMOTE
}

case "$command" in
    provision)
        ssh "$remote" "umask 077; mkdir -p '$remote_dir'; if [ ! -f '$remote_env' ]; then { printf '%s\n' 'DB_NAME=peanut_admin_development' 'DB_USER=peanut_admin_development'; printf 'DB_PASS=%s\n' \"\$(openssl rand -hex 24)\"; printf 'MYSQL_ROOT_PASSWORD=%s\n' \"\$(openssl rand -hex 24)\"; } > '$remote_env'; chmod 600 '$remote_env'; fi"
        normalize_remote_credentials
        ssh "$remote" "for name in DB_NAME DB_USER DB_PASS MYSQL_ROOT_PASSWORD; do grep -q \"^\${name}=.\" '$remote_env' || { printf 'registered database credential is missing: %s\\n' \"\$name\" >&2; exit 1; }; done"
        if ! ssh -o BatchMode=yes "$remote" "/usr/local/bin/docker volume inspect peanut-admin-mysql84-development-data >/dev/null 2>&1"; then
            [ "$allow_empty_initialize" -eq 1 ] || {
                printf '%s\n' 'registered persistent database volume is missing; refusing to create an empty replacement without --initialize-empty' >&2
                exit 1
            }
        fi
        scp -q "$repo_dir/deploy/docker-compose.remote-development.yml" "$remote:$remote_compose"
        ssh "$remote" "/usr/local/bin/docker compose --env-file '$remote_env' -f '$remote_compose' up -d --wait"
        "$0" sync-credentials --backend-env "$local_env"
        ;;
    sync-credentials)
        normalize_remote_credentials
        mkdir -p "$local_state"
        umask 077
        [ -f "$local_env" ] || : > "$local_env"
        temporary=$(mktemp "$local_state/company-db.XXXXXX")
        ssh "$remote" "awk -F= '\$1 == \"DB_NAME\" || \$1 == \"DB_USER\" || \$1 == \"DB_PASS\" { print }' '$remote_env'" > "$temporary"
        for name in DB_NAME DB_USER DB_PASS; do
            value=$(awk -F= -v name="$name" '$1 == name { sub(/^[^=]*=/, ""); print; exit }' "$temporary")
            [ -n "$value" ] || { rm -f "$temporary"; printf 'registered database credential is missing: %s\n' "$name" >&2; exit 1; }
            merged=$(mktemp "$local_state/stack.env.XXXXXX")
            awk -F= -v name="$name" '$1 != name { print }' "$local_env" > "$merged"
            printf '%s=%s\n' "$name" "$value" >> "$merged"
            chmod 600 "$merged"
            mv "$merged" "$local_env"
        done
        merged=$(mktemp "$local_state/stack.env.XXXXXX")
        awk -F= '$1 != "DB_ROOT_PASS" && $1 != "MYSQL_ROOT_PASSWORD" { print }' "$local_env" > "$merged"
        chmod 600 "$merged"
        mv "$merged" "$local_env"
        rm -f "$temporary"
        printf 'Company development database credentials synchronized to %s\n' "$local_env"
        ;;
    status)
        ssh -o BatchMode=yes "$remote" "/usr/local/bin/docker inspect peanut-admin-mysql84-development --format 'image={{.Config.Image}} status={{.State.Status}} health={{.State.Health.Status}}'"
        ;;
    *)
        printf 'Usage: %s {provision|sync-credentials|status} [--backend-env path] [--initialize-empty]\n' "$0" >&2
        exit 2
        ;;
esac
